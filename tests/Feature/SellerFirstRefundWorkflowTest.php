<?php

namespace Tests\Feature;

use App\Models\Admin\Dispute;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\ReturnRefundEvidence;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\RefundProofStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerFirstRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_request_uses_cash_seller_scope_and_does_not_create_an_admin_dispute(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();

        $response = $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));

        $response->assertStatus(200);
        $request = ReturnRefundRequest::query()->firstOrFail();
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();
        $this->assertSame('CASH', $request->refund_method);
        $this->assertNull($request->gcash_mobile);
        $this->assertNull($request->gcash_recipient_name);
        $this->assertNull($request->dispute_id);
        $this->assertSame(0, Dispute::query()->count());
        $this->assertSame($sellerUser->sellerProfile->id, $sellerRequest->seller_profile_id);
        $this->assertNull(DB::table('return_refund_requests')->value('gcash_mobile'));
        $this->assertDatabaseHas('notifications', ['user_id' => $sellerUser->id, 'reference_id' => $sellerRequest->id]);
    }

    public function test_seller_rejection_reason_requires_ten_to_three_hundred_trimmed_characters(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)
            ->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();

        foreach ([str_repeat('x', 9), " \t\n ", str_repeat('x', 301)] as $reason) {
            $this->actingAs($sellerUser)
                ->post(route('seller.orders.refunds.reject', $sellerRequest), ['rejection_reason' => $reason])
                ->assertSessionHasErrors('rejection_reason');
        }

        $this->assertSame(SellerReturnRefundRequest::STATUS_SELLER_REVIEW, $sellerRequest->fresh()->status);
    }

    public function test_seller_can_reject_with_exactly_ten_trimmed_characters(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();

        $this->actingAs($sellerUser)
            ->post(route('seller.orders.refunds.reject', $sellerRequest), ['rejection_reason' => '  abcdefghij  '])
            ->assertRedirect();

        $this->assertSame('abcdefghij', $sellerRequest->fresh()->decision_reason);
        $this->assertSame(SellerReturnRefundRequest::STATUS_REJECTED, $sellerRequest->fresh()->status);
        $this->travelTo(now()->addDays(4));
        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);
        $this->assertSame(SellerReturnRefundRequest::STATUS_REJECTED, $sellerRequest->fresh()->status);
        $this->travelBack();
    }

    public function test_seller_can_reject_with_exactly_three_hundred_characters(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();
        $reason = str_repeat('x', 300);

        $this->actingAs($sellerUser)
            ->post(route('seller.orders.refunds.reject', $sellerRequest), ['rejection_reason' => $reason])
            ->assertRedirect();

        $this->assertSame(300, mb_strlen($sellerRequest->fresh()->decision_reason));
    }

    public function test_seller_response_deadline_shows_and_auto_approval_is_idempotent_at_three_days(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();
        $deadline = $sellerRequest->created_at->copy()->addDays(3);

        $this->actingAs($sellerUser)
            ->get(route('seller.orders.refunds.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Seller response due:')
            ->assertSee($deadline->format('M d, Y g:i A'));

        $sellerRequest->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $originalOrderStatus = $order->fresh()->status;
        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);
        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);

        $sellerRequest->refresh();
        $this->assertSame(SellerReturnRefundRequest::STATUS_APPROVED, $sellerRequest->status);
        $this->assertEquals((float) $sellerRequest->requested_amount, (float) $sellerRequest->approved_amount);
        $this->assertSame(1, $sellerRequest->events()->where('event_type', 'SELLER_AUTO_APPROVED_TIMEOUT')->count());
        $this->assertSame(1, DB::table('notifications')->where('reference_type', SellerReturnRefundRequest::class)->where('reference_id', $sellerRequest->id)->where('title', 'Refund request automatically approved')->count());
        $this->assertSame($originalOrderStatus, $order->fresh()->status);
        $this->assertSame(0, $sellerRequest->payouts()->count());
        $this->assertSame(0, DB::table('cash_refund_obligations')->where('seller_return_refund_request_id', $sellerRequest->id)->count());
    }

    public function test_deadline_command_waits_before_three_days_and_skips_manual_response_and_admin_dispute(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();
        $sellerRequest->forceFill(['created_at' => now()->subDays(3)->addMinutes(5)])->saveQuietly();

        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);
        $this->assertSame(SellerReturnRefundRequest::STATUS_SELLER_REVIEW, $sellerRequest->fresh()->status);

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.approve', $sellerRequest), ['confirm_approval' => 1])->assertRedirect();
        $sellerRequest->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);
        $this->assertSame(SellerReturnRefundRequest::STATUS_APPROVED, $sellerRequest->fresh()->status);
        $this->assertSame(0, $sellerRequest->events()->where('event_type', 'SELLER_AUTO_APPROVED_TIMEOUT')->count());

        $this->travelBack();
    }

    public function test_deadline_command_does_not_approve_an_escalated_admin_dispute(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->sole();
        $this->actingAs($sellerUser)
            ->post(route('seller.orders.refunds.reject', $sellerRequest), ['rejection_reason' => 'The supplied return details are incomplete.'])
            ->assertRedirect();
        $this->actingAs($buyer)->post(route('buyer.returns.escalate', $sellerRequest), [
            'reason' => 'I disagree with the Seller review',
            'explanation' => 'Please have Admin review the evidence and decision.',
            'confirm_escalation' => 1,
        ])->assertRedirect();
        $sellerRequest->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();

        $this->artisan('likhae:auto-approve-expired-seller-refunds')->assertExitCode(0);
        $this->assertSame(SellerReturnRefundRequest::STATUS_ESCALATED, $sellerRequest->fresh()->status);
        $this->assertNotNull($sellerRequest->fresh()->dispute_id);
        $this->assertSame(0, $sellerRequest->events()->where('event_type', 'SELLER_AUTO_APPROVED_TIMEOUT')->count());
    }

    public function test_return_form_disables_gcash_and_requires_cash_acknowledgement(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)
            ->get(route('buyer.orders.return-refund.form', $order))
            ->assertOk()
            ->assertSee('GCash')
            ->assertSee('Coming Soon')
            ->assertSee('disabled', false);

        $payload = $this->requestPayload($item);
        unset($payload['confirm_cash']);
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)
            ->assertSessionHasErrors('confirm_cash');
        $this->assertSame(0, ReturnRefundRequest::query()->count());
    }

    public function test_return_request_requires_at_least_one_evidence_image(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        unset($payload['images']);

        $this->actingAs($buyer)
            ->post(route('buyer.orders.return-refund', $order), $payload)
            ->assertSessionHasErrors('images');
        $this->assertSame(0, ReturnRefundRequest::query()->count());
    }

    public function test_return_request_accepts_five_images_and_ten_character_description(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        $payload['description'] = '1234567890';
        $payload['images'] = array_map(
            fn (int $index) => UploadedFile::fake()->image('evidence-'.$index.'.jpg', 30, 30),
            range(1, 5),
        );

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertStatus(200);

        $this->assertSame(5, ReturnRefundEvidence::query()->where('evidence_type', 'BUYER_PHOTO')->count());
        $this->assertSame('1234567890', ReturnRefundRequest::query()->sole()->description);
    }

    public function test_return_request_rejects_six_images_and_descriptions_under_ten_characters(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        $payload['images'] = array_map(
            fn (int $index) => UploadedFile::fake()->image('evidence-'.$index.'.jpg', 20, 20),
            range(1, 6),
        );
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('images');

        $payload['images'] = [UploadedFile::fake()->image('valid.jpg', 20, 20)];
        $payload['description'] = '123456789';
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('description');
        $payload['description'] = " \t\n ";
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('description');

        $this->assertSame(0, ReturnRefundRequest::query()->count());
    }

    public function test_return_request_enforces_the_five_megabyte_per_image_limit(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        $payload['images'] = [UploadedFile::fake()->image('oversized.jpg', 20, 20)->size(5121)];

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('images.0');
        $this->assertSame(0, ReturnRefundRequest::query()->count());

        $payload['images'] = [UploadedFile::fake()->image('limit.jpg', 20, 20)->size(5120)];
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertStatus(200);
        $this->assertSame(1, ReturnRefundEvidence::query()->where('evidence_type', 'BUYER_PHOTO')->count());
    }

    public function test_return_request_rejects_unsupported_and_corrupted_image_files(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        $payload['images'] = [UploadedFile::fake()->createWithContent('proof.txt', 'not an image')];
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('images.0');

        $payload['images'] = [UploadedFile::fake()->createWithContent('corrupt.jpg', 'not an encoded image')];
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertSessionHasErrors('images.0');
        $this->assertSame(0, ReturnRefundRequest::query()->count());
    }

    public function test_another_buyer_cannot_submit_return_evidence_for_the_order(): void
    {
        [$owner, , $order, $item] = $this->createDeliveredOrder();
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);

        $this->actingAs($otherBuyer)
            ->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item))
            ->assertForbidden();
        $this->assertSame(0, ReturnRefundRequest::query()->count());
    }

    public function test_failed_video_storage_rolls_back_return_records_and_removes_stored_image(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        Storage::fake('local');
        $imagePath = 'return-refund-evidence/partial/image.enc';
        $proofs = \Mockery::mock(RefundProofStorageService::class);
        $proofs->shouldReceive('storeImage')->once()->andReturnUsing(function () use ($imagePath): array {
            Storage::disk('local')->put($imagePath, 'encrypted-image');

            return ['disk' => 'local', 'path' => $imagePath, 'original_name' => 'proof.jpg', 'mime' => 'image/webp', 'encrypted' => true];
        });
        $proofs->shouldReceive('storeFile')->once()->andThrow(new \RuntimeException('Simulated storage failure.'));
        $this->instance(RefundProofStorageService::class, $proofs);

        $payload = $this->requestPayload($item);
        $payload['video'] = UploadedFile::fake()->create('proof.mp4', 2, 'video/mp4');
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertStatus(500);

        $this->assertSame(0, ReturnRefundRequest::query()->count());
        $this->assertSame(0, ReturnRefundEvidence::query()->count());
        Storage::disk('local')->assertMissing($imagePath);
    }

    public function test_manipulated_gcash_fields_cannot_change_the_cash_refund_method(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item) + [
            'refund_method' => 'GCASH_TRANSFER',
            'gcash_recipient_name' => 'Forged Account',
            'gcash_mobile' => '09171234567',
        ];

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertStatus(200);

        $request = ReturnRefundRequest::query()->firstOrFail();
        $this->assertSame('CASH', $request->refund_method);
        $this->assertNull($request->gcash_mobile);
        $this->assertNull($request->gcash_recipient_name);
    }

    public function test_responsible_seller_can_open_parent_scoped_buyer_evidence(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();
        $evidence = ReturnRefundEvidence::create([
            'return_refund_request_id' => $sellerRequest->return_refund_request_id,
            'seller_return_refund_request_id' => null,
            'uploaded_by_user_id' => $buyer->id,
            'disk' => 'local',
            'file_path' => 'return-refund-evidence/parent-scoped.enc',
            'original_name' => 'hero-bg.png',
            'mime_type' => 'image/png',
            'evidence_type' => 'BUYER_PHOTO',
            'encrypted' => true,
        ]);

        $this->mock(RefundProofStorageService::class)
            ->shouldReceive('read')
            ->once()
            ->with('local', 'return-refund-evidence/parent-scoped.enc')
            ->andReturn('protected-evidence');

        $this->actingAs($sellerUser)
            ->get(route('seller.orders.refunds.evidence', $evidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSee('protected-evidence', false);
    }

    public function test_seller_can_approve_cash_request_without_a_fake_online_payout(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.approve', $sellerRequest), ['confirm_approval' => 1])->assertRedirect();
        $sellerRequest->refresh();
        $this->assertSame(SellerReturnRefundRequest::STATUS_APPROVED, $sellerRequest->status);

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.payout', $sellerRequest), [
            'amount_sent' => '899.75',
            'transaction_reference' => 'FORGED-ONLINE-REF',
            'sent_at' => now()->subMinute()->format('Y-m-d\\TH:i'),
        ])->assertStatus(409);
        $this->assertSame(0, $sellerRequest->payouts()->count());

        $this->actingAs($sellerUser)
            ->get(route('seller.orders.refunds.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Cash refund obligation')
            ->assertSee('New COD refunds stay cash-only');
        $this->actingAs($buyer)
            ->get(route('buyer.returns.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Cash');
        $this->assertSame(SellerReturnRefundRequest::STATUS_APPROVED, $sellerRequest->fresh()->status);
    }

    public function test_another_seller_cannot_open_the_refund_request(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();
        $otherUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        SellerProfile::create(['user_id' => $otherUser->id, 'primary_category_id' => $sellerUser->sellerProfile->primary_category_id, 'business_name' => 'Other Seller', 'status' => 'ACTIVE']);

        $this->actingAs($otherUser)->get(route('seller.orders.refunds.show', $sellerRequest))->assertForbidden();
    }

    public function test_multi_seller_request_creates_one_owned_child_per_seller(): void
    {
        [$buyer, $sellerOne, $sellerTwo, $order, $itemOne, $itemTwo] = $this->createMultiSellerOrder();
        $payload = $this->requestPayload($itemOne);
        $payload['item_ids'] = [$itemOne->id, $itemTwo->id];
        $payload['quantities'] = [$itemOne->id => 1, $itemTwo->id => 1];

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)->assertStatus(200);

        $children = SellerReturnRefundRequest::query()->orderBy('seller_profile_id')->get();
        $this->assertCount(2, $children);
        $this->assertSame([$sellerOne->sellerProfile->id, $sellerTwo->sellerProfile->id], $children->pluck('seller_profile_id')->all());
        $this->assertCount(1, $children[0]->items);
        $this->assertCount(1, $children[1]->items);
    }

    public function test_return_refund_pages_render_before_and_after_escalation(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();

        $this->actingAs($buyer)
            ->get(route('buyer.orders.return-refund.form', $order))
            ->assertOk()
            ->assertSee('Return & Refund');

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item))->assertStatus(200);
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();

        $this->actingAs($buyer)
            ->get(route('buyer.returns'))
            ->assertOk()
            ->assertSee($sellerRequest->request->request_number);
        $this->actingAs($buyer)
            ->get(route('buyer.returns.parent', $sellerRequest->request))
            ->assertOk()
            ->assertSee($sellerRequest->request->request_number);
        $this->actingAs($buyer)
            ->get(route('buyer.returns.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Cash only')
            ->assertSee('New COD refunds are cash-only');

        $this->actingAs($sellerUser)
            ->get(route('seller.orders', ['status' => 'returns']))
            ->assertOk()
            ->assertSee($sellerRequest->request->request_number);
        $this->actingAs($sellerUser)
            ->get(route('seller.orders.refunds.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Approve Refund');

        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($admin)
            ->get(route('admin.complaints'))
            ->assertOk();

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.reject', $sellerRequest), [
            'rejection_reason' => 'The evidence does not show the reported defect.',
        ])->assertRedirect();
        $this->actingAs($buyer)->post(route('buyer.returns.escalate', $sellerRequest), [
            'reason' => 'I disagree with the rejection',
            'explanation' => 'Admin should review the request and determine the correct resolution.',
            'confirm_escalation' => 1,
        ])->assertRedirect();

        $dispute = Dispute::query()->firstOrFail();
        $this->actingAs($buyer)
            ->get(route('buyer.returns.show', $sellerRequest))
            ->assertOk()
            ->assertSee('Cash only')
            ->assertSee('New COD refunds are cash-only');
        $this->actingAs($sellerUser)
            ->get(route('seller.orders.refunds.show', $sellerRequest))
            ->assertOk()
            ->assertSee('The evidence does not show the reported defect.');
        $this->actingAs($admin)
            ->get(route('admin.complaints'))
            ->assertOk()
            ->assertSee($dispute->dispute_number);
        $this->actingAs($admin)
            ->get(route('admin.disputes.show', $dispute))
            ->assertOk()
            ->assertSee($dispute->dispute_number)
            ->assertSee('Reject Escalation');
    }

    public function test_buyer_can_escalate_a_seller_rejection_and_admin_can_record_a_decision(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.reject', $sellerRequest), ['rejection_reason' => 'The evidence does not show the reported defect.'])->assertRedirect();
        $this->actingAs($buyer)->post(route('buyer.returns.escalate', $sellerRequest), [
            'reason' => 'I disagree with the rejection',
            'explanation' => 'The attached evidence and description show the defect clearly and need Admin review.',
            'confirm_escalation' => 1,
        ])->assertRedirect();

        $dispute = Dispute::query()->firstOrFail();
        $this->assertSame('RETURN_REFUND_ESCALATION', $dispute->type);
        $this->assertSame($dispute->id, $sellerRequest->fresh()->dispute_id);

        $evidence = ReturnRefundEvidence::create([
            'return_refund_request_id' => $sellerRequest->return_refund_request_id,
            'seller_return_refund_request_id' => null,
            'uploaded_by_user_id' => $buyer->id,
            'disk' => 'local',
            'file_path' => 'return-refund-evidence/admin-parent-scoped.enc',
            'original_name' => 'buyer-photo.png',
            'mime_type' => 'image/png',
            'evidence_type' => 'BUYER_PHOTO',
            'encrypted' => true,
        ]);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);
        $this->mock(RefundProofStorageService::class)
            ->shouldReceive('read')
            ->once()
            ->with('local', 'return-refund-evidence/admin-parent-scoped.enc')
            ->andReturn('protected-admin-evidence');
        $this->actingAs($admin)
            ->get(route('admin.disputes.refund-evidence', $evidence))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSee('protected-admin-evidence', false);

        $this->actingAs($admin)->patch(route('admin.disputes.update', $dispute), [
            'status' => 'REJECTED',
            'admin_action' => 'REJECT_ESCALATION',
            'resolution' => 'Admin rejected the escalation after reviewing the investigation outcome.',
        ])->assertRedirect();
        $this->assertSame(SellerReturnRefundRequest::STATUS_REJECTED, $sellerRequest->fresh()->status);
    }

    public function test_admin_can_return_an_escalation_to_the_seller_with_refund_required(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.reject', $sellerRequest), [
            'rejection_reason' => 'The evidence does not show the reported defect.',
        ])->assertRedirect();
        $this->actingAs($buyer)->post(route('buyer.returns.escalate', $sellerRequest), [
            'reason' => 'I disagree with the rejection',
            'explanation' => 'Admin should review the request and require a refund.',
            'confirm_escalation' => 1,
        ])->assertRedirect();

        $dispute = Dispute::query()->firstOrFail();
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin)
            ->get(route('admin.disputes.show', $dispute))
            ->assertOk()
            ->assertSee($dispute->dispute_number)
            ->assertSee('Return to Seller', false);

        $this->actingAs($admin)->patch(route('admin.disputes.update', $dispute), [
            'status' => 'RESOLVED',
            'admin_action' => 'RETURN_TO_SELLER_REFUND',
            'resolution' => 'Admin requires the Seller to refund the eligible amount.',
        ])->assertRedirect();

        $sellerRequest->refresh();
        $this->assertSame(SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED, $sellerRequest->status);
        $this->assertEquals((float) $sellerRequest->requested_amount, (float) $sellerRequest->approved_amount);
        $this->assertSame('RESOLVED', $dispute->fresh()->status);
        $this->assertDatabaseHas('return_refund_events', [
            'seller_return_refund_request_id' => $sellerRequest->id,
            'event_type' => 'ADMIN_REFUND_REQUIRED',
        ]);
    }

    private function requestPayload(OrderItem $item): array
    {
        return [
            'issue_category' => 'DAMAGED',
            'issue_reason' => 'Scratched item',
            'item_ids' => [$item->id],
            'quantities' => [$item->id => 1],
            'description' => 'The item arrived with a visible scratch on the front panel.',
            'images' => [UploadedFile::fake()->image('evidence.jpg', 40, 40)],
            'buyer_email' => 'buyer@example.test',
            'confirm_cash' => 1,
            'confirm_request' => 1,
        ];
    }

    private function createDeliveredOrder(): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Refund Test Category', 'slug' => 'refund-test-category', 'is_active' => true]);
        $seller = SellerProfile::create(['user_id' => $sellerUser->id, 'primary_category_id' => $category->id, 'business_name' => 'Refund Test Seller', 'status' => 'ACTIVE']);
        $order = Order::create(['order_number' => 'REFUND-TEST-ORDER', 'buyer_user_id' => $buyer->id, 'status' => 'PROCESSING', 'placed_at' => now()->subDays(2)]);
        $sellerOrder = SellerOrder::create(['seller_order_number' => 'REFUND-TEST-SELLER-ORDER', 'order_id' => $order->id, 'seller_profile_id' => $seller->id, 'status' => 'COMPLETED']);
        $shipment = Shipment::create(['seller_order_id' => $sellerOrder->id, 'tracking_number' => 'REFUND-TEST-TRACKING', 'destination_province_code' => 'P1', 'destination_province_name' => 'Province', 'destination_municipality_code' => 'M1', 'destination_municipality_name' => 'Municipality', 'destination_barangay_code' => 'B1', 'destination_barangay_name' => 'Barangay', 'current_status' => 'DELIVERED']);
        ShipmentEvent::create(['shipment_id' => $shipment->id, 'status' => 'DELIVERED', 'actor_user_id' => $sellerUser->id, 'notes' => 'Delivered.', 'occurred_at' => now()->subDay()]);
        $item = OrderItem::create(['seller_order_id' => $sellerOrder->id, 'product_name' => 'Refund Test Item', 'sku' => 'REFUND-ITEM', 'variant_description' => 'Standard', 'unit_price' => 899.75, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 899.75]);

        return [$buyer, $sellerUser, $order, $item];
    }

    private function createMultiSellerOrder(): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerOne = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $sellerTwo = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Multi Refund Category', 'slug' => 'multi-refund-category', 'is_active' => true]);
        $profileOne = SellerProfile::create(['user_id' => $sellerOne->id, 'primary_category_id' => $category->id, 'business_name' => 'Multi Seller One', 'status' => 'ACTIVE']);
        $profileTwo = SellerProfile::create(['user_id' => $sellerTwo->id, 'primary_category_id' => $category->id, 'business_name' => 'Multi Seller Two', 'status' => 'ACTIVE']);
        $order = Order::create(['order_number' => 'MULTI-REFUND-ORDER', 'buyer_user_id' => $buyer->id, 'status' => 'PROCESSING', 'placed_at' => now()->subDays(2)]);
        $sellerOrderOne = SellerOrder::create(['seller_order_number' => 'MULTI-SELLER-ONE', 'order_id' => $order->id, 'seller_profile_id' => $profileOne->id, 'status' => 'COMPLETED']);
        $sellerOrderTwo = SellerOrder::create(['seller_order_number' => 'MULTI-SELLER-TWO', 'order_id' => $order->id, 'seller_profile_id' => $profileTwo->id, 'status' => 'COMPLETED']);
        foreach ([$sellerOrderOne, $sellerOrderTwo] as $index => $sellerOrder) {
            $shipment = Shipment::create(['seller_order_id' => $sellerOrder->id, 'tracking_number' => 'MULTI-TRACKING-'.$index, 'destination_province_code' => 'P1', 'destination_province_name' => 'Province', 'destination_municipality_code' => 'M1', 'destination_municipality_name' => 'Municipality', 'destination_barangay_code' => 'B1', 'destination_barangay_name' => 'Barangay', 'current_status' => 'DELIVERED']);
            ShipmentEvent::create(['shipment_id' => $shipment->id, 'status' => 'DELIVERED', 'actor_user_id' => $sellerOrder->sellerProfile->user_id, 'notes' => 'Delivered.', 'occurred_at' => now()->subDay()]);
        }
        $itemOne = OrderItem::create(['seller_order_id' => $sellerOrderOne->id, 'product_name' => 'Multi Seller Item One', 'sku' => 'MULTI-ONE', 'unit_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);
        $itemTwo = OrderItem::create(['seller_order_id' => $sellerOrderTwo->id, 'product_name' => 'Multi Seller Item Two', 'sku' => 'MULTI-TWO', 'unit_price' => 200, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 200]);

        return [$buyer, $sellerOne, $sellerTwo, $order, $itemOne, $itemTwo];
    }
}
