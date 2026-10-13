<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSellerComplianceResponseRequest;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Admin\SellerComplianceResponse;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SellerComplianceController extends Controller
{
    public function index(Request $request): View
    {
        $seller = $this->seller($request);
        $cases = $seller->complianceCases()
            ->with(['product.category.parent', 'actions.performedBy'])
            ->latest('opened_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('Seller.compliance.index', compact('cases'));
    }

    public function show(Request $request, SellerComplianceCase $case): View
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id, 403);

        $case->load([
            'product.category.parent',
            'product.images',
            'actions.performedBy',
            'responses',
        ]);

        $warningAction = $case->actions->where('action_type', 'WARNING')->last();
        $publicActions = $case->actions->whereIn('action_type', ['WARNING', 'PRODUCT_SUSPEND']);

        return view('Seller.compliance.show', [
            'case' => $case,
            'warningAction' => $warningAction,
            'publicActions' => $publicActions,
        ]);
    }

    public function storeResponse(StoreSellerComplianceResponseRequest $request, SellerComplianceCase $case)
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id, 403);

        if (! in_array($case->status, ['OPEN', 'UNDER_REVIEW'], true)) {
            return back()->withErrors(['response' => 'This compliance case is already resolved or closed.']);
        }

        $data = $request->validated();
        $latest = $case->responses()->latest('submitted_at')->first();
        if ($latest && hash_equals((string) $latest->explanation, (string) $data['explanation'])
            && hash_equals((string) $latest->corrective_action, (string) $data['corrective_action'])) {
            return back()->withErrors(['response' => 'This response matches your latest submission. Add a new update or evidence before submitting again.'])->withInput();
        }

        $evidencePath = null;
        $evidence = $request->file('evidence');
        if ($evidence) {
            $evidencePath = Storage::disk('local')->putFile('compliance/'.$seller->id.'/'.$case->id, $evidence);
            if (! $evidencePath) {
                return back()->withErrors(['evidence' => 'The evidence file could not be stored. Please try again.'])->withInput();
            }
        }

        $response = null;
        try {
            DB::transaction(function () use (&$response, $case, $seller, $data, $evidence, $evidencePath, $request): void {
                $response = SellerComplianceResponse::create([
                    'seller_compliance_case_id' => $case->id,
                    'seller_profile_id' => $seller->id,
                    'explanation' => $data['explanation'],
                    'corrective_action' => $data['corrective_action'],
                    'evidence_path' => $evidencePath,
                    'evidence_original_name' => $evidence?->getClientOriginalName(),
                    'evidence_mime' => $evidence?->getMimeType(),
                    'evidence_size' => $evidence?->getSize(),
                    'submitted_at' => now(),
                ]);

                $oldStatus = $case->status;
                if ($oldStatus === 'OPEN') {
                    $case->update(['status' => 'UNDER_REVIEW']);
                }

                $case->loadMissing('sellerProfile.user');
                $adminUsers = User::query()
                    ->where('account_type', User::TYPE_ADMIN)
                    ->where('status', User::STATUS_ACTIVE)
                    ->get(['id']);
                foreach ($adminUsers as $admin) {
                    Notification::create([
                        'user_id' => $admin->id,
                        'type' => 'COMPLIANCE_RESPONSE',
                        'title' => 'Seller response submitted: '.$case->case_number,
                        'message' => 'A Seller submitted an explanation and corrective action for '.$case->case_number.'. Review the response and any attached evidence before deciding the case.',
                        'reference_type' => SellerComplianceCase::class,
                        'reference_id' => $case->id,
                        'action_url' => route('admin.compliance.show', ['case' => $case->id]),
                    ]);
                }

                AuditLog::create([
                    'actor_user_id' => $request->user()->id,
                    'event' => 'COMPLIANCE_RESPONSE_SUBMITTED',
                    'auditable_type' => SellerComplianceCase::class,
                    'auditable_id' => $case->id,
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => [
                        'status' => $case->fresh()->status,
                        'response_id' => $response->id,
                        'evidence_attached' => $evidencePath !== null,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($evidencePath) {
                Storage::disk('local')->delete($evidencePath);
            }
            throw $exception;
        }

        return redirect()->route('seller.compliance.show', ['case' => $case->id])
            ->with('status', 'Your response was submitted for Admin review.');
    }

    public function downloadResponseEvidence(Request $request, SellerComplianceCase $case, SellerComplianceResponse $response)
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $response->seller_compliance_case_id === (int) $case->id && (int) $response->seller_profile_id === (int) $seller->id, 404);
        abort_unless($response->evidence_path && Storage::disk('local')->exists($response->evidence_path), 404);

        return Storage::disk('local')->download(
            $response->evidence_path,
            $response->evidence_original_name ?: 'compliance-evidence',
            array_filter(['Content-Type' => $response->evidence_mime]),
        );
    }

    private function seller(Request $request): SellerProfile
    {
        return $request->user()
            ->sellerProfile()
            ->where('status', 'ACTIVE')
            ->firstOrFail();
    }
}
