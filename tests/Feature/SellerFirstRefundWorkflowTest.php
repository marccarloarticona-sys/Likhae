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

    public function test_buyer_request_is_gcash_seller_scoped_and_not_an_admin_dispute(): void
    {
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();

        $response = $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));

        $response->assertStatus(200);
        $request = ReturnRefundRequest::query()->firstOrFail();
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();
        $this->assertSame('09171234567', $request->gcash_mobile);
        $this->assertSame('GCASH_TRANSFER', $request->refund_method);
        $this->assertNull($request->dispute_id);
        $this->assertSame(0, Dispute::query()->count());
        $this->assertSame($sellerUser->sellerProfile->id, $sellerRequest->seller_profile_id);
        $this->assertNotSame('09171234567', DB::table('return_refund_requests')->value('gcash_mobile'));
        $this->assertDatabaseHas('notifications', ['user_id' => $sellerUser->id, 'reference_id' => $sellerRequest->id]);
    }

    public function test_invalid_gcash_number_is_rejected_server_side(): void
    {
        [$buyer, , $order, $item] = $this->createDeliveredOrder();
        $payload = $this->requestPayload($item);
        $payload['gcash_mobile'] = '0912345';

        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $payload)
            ->assertSessionHasErrors('gcash_mobile');
        $this->assertSame(0, ReturnRefundRequest::query()->count());
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

    public function test_seller_can_approve_report_payment_and_buyer_can_confirm(): void
    {
        Storage::fake('local');
        [$buyer, $sellerUser, $order, $item] = $this->createDeliveredOrder();
        $this->actingAs($buyer)->post(route('buyer.orders.return-refund', $order), $this->requestPayload($item));
        $sellerRequest = SellerReturnRefundRequest::query()->firstOrFail();

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.approve', $sellerRequest), ['confirm_approval' => 1])->assertRedirect();
        $sellerRequest->refresh();
        $this->assertSame(SellerReturnRefundRequest::STATUS_APPROVED, $sellerRequest->status);

        $this->actingAs($sellerUser)->post(route('seller.orders.refunds.payout', $sellerRequest), [
            'amount_sent' => '899.75',
            'transaction_reference' => 'GCASH-REF-123',
            'sent_at' => now()->subMinute()->format('Y-m-d\\TH:i'),
            'proof' => UploadedFile::fake()->image('gcash-proof.png', 900, 600),
        ])->assertRedirect();
        $sellerRequest->refresh();
        $this->assertSame(SellerReturnRefundRequest::STATUS_REFUND_SENT, $sellerRequest->status);
        $this->assertSame(1, $sellerRequest->payouts()->count());
        $this->assertNotSame('GCASH-REF-123', DB::table('refund_payouts')->value('transaction_reference'));

        $this->actingAs($buyer)->post(route('buyer.returns.confirm', $sellerRequest), ['confirm_receipt' => 1])->assertRedirect();
        $this->assertSame(SellerReturnRefundRequest::STATUS_CONFIRMED, $sellerRequest->fresh()->status);
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
            ->assertSee('Payment report');

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
            ->assertSee('Payment report');
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

        $this->actingAs($admin)->patch(route('admin.disputes.update', $dispute), ['status' => 'RESOLVED', 'resolution' => 'Admin recorded the investigation outcome.'])->assertRedirect();
        $this->assertSame(SellerReturnRefundRequest::STATUS_DISPUTE_RESOLVED, $sellerRequest->fresh()->status);
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
            'buyer_email' => 'buyer@example.test',
            'gcash_recipient_name' => 'Receiving Account',
            'gcash_mobile' => '+639171234567',
            'confirm_gcash' => 1,
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
