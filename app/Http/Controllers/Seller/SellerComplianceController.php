<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSellerComplianceResponseRequest;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Admin\ProductModerationScan;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Admin\SellerComplianceResponse;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $moderationScans = Schema::hasTable('product_moderation_scans')
            ? $case->moderationScans()->get()
            : collect();
        $case->responses->each(function (SellerComplianceResponse $response): void {
            $response->setAttribute(
                'evidence_available',
                filled($response->evidence_path) && Storage::disk('local')->exists($response->evidence_path),
            );
        });

        $warningAction = $case->actions->where('action_type', 'WARNING')->last();
        $publicActions = $case->actions->whereIn('action_type', ['WARNING', 'PRODUCT_SUSPEND']);

        return view('Seller.compliance.show', [
            'case' => $case,
            'warningAction' => $warningAction,
            'publicActions' => $publicActions,
            'moderationScans' => $moderationScans,
        ]);
    }

    public function submitModerationAppeal(Request $request, SellerComplianceCase $case, ProductModerationScan $scan, ProductModerationService $moderation)
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id, 404);
        abort_unless((int) $scan->seller_profile_id === (int) $seller->id
            && (int) $scan->seller_compliance_case_id === (int) $case->id
            && $scan->scan_status === 'COMPLETE'
            && in_array($scan->overall_risk, ['MEDIUM', 'HIGH', 'CRITICAL', 'UNKNOWN'], true), 404);

        $data = $request->validate([
            'explanation' => ['required', 'string', 'min:20', 'max:5000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        if ($scan->appeal_status !== 'NOT_APPEALED') {
            return back()->withErrors(['appeal' => 'An appeal for this scan has already been submitted or reviewed.']);
        }

        $evidence = $request->file('evidence');
        $evidencePath = null;
        if ($evidence) {
            $extension = strtolower($evidence->getClientOriginalExtension());
            $evidencePath = Storage::disk('local')->putFileAs(
                'product-moderation-appeals/'.$seller->id.'/'.$scan->id,
                $evidence,
                Str::uuid().'.'.$extension,
            );
            if (! $evidencePath) {
                return back()->withErrors(['evidence' => 'The evidence file could not be stored. Please try again.'])->withInput();
            }
        }

        try {
            DB::transaction(function () use ($case, $seller, $scan, $data, $evidence, $evidencePath, $request): void {
                $lockedScan = ProductModerationScan::query()->whereKey($scan->id)->lockForUpdate()->firstOrFail();
                if ((int) $lockedScan->seller_profile_id !== (int) $seller->id
                    || (int) $lockedScan->seller_compliance_case_id !== (int) $case->id
                    || $lockedScan->appeal_status !== 'NOT_APPEALED'
                    || $lockedScan->scan_status !== 'COMPLETE') {
                    abort(409, 'This scan cannot accept another appeal.');
                }

                $lockedScan->update([
                    'appeal_status' => 'APPEAL_SUBMITTED',
                    'appeal_reason' => 'SELLER_APPEAL',
                    'appeal_explanation' => $data['explanation'],
                    'appeal_evidence_path' => $evidencePath,
                    'appeal_evidence_original_name' => $evidence?->getClientOriginalName(),
                    'appeal_evidence_mime' => $evidence?->getMimeType(),
                    'appeal_evidence_size' => $evidence?->getSize(),
                    'appeal_submitted_at' => now(),
                ]);

                if ($case->status === 'OPEN') {
                    $case->update(['status' => 'UNDER_REVIEW']);
                }

                AuditLog::create([
                    'actor_user_id' => $request->user()->id,
                    'event' => 'PRODUCT_AI_APPEAL_SUBMITTED',
                    'auditable_type' => ProductModerationScan::class,
                    'auditable_id' => $lockedScan->id,
                    'old_values' => ['appeal_status' => 'NOT_APPEALED'],
                    'new_values' => [
                        'appeal_status' => 'APPEAL_SUBMITTED',
                        'product_id' => $lockedScan->product_id,
                        'evidence_attached' => $evidencePath !== null,
                    ],
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($evidencePath) {
                Storage::disk('local')->delete($evidencePath);
            }
            throw $exception;
        }

        $moderation->notifyAdminsOfAppeal($scan->fresh());

        return redirect()->route('seller.compliance.show', ['case' => $case->id])
            ->with('status', 'Your product moderation appeal was submitted for Admin review.');
    }

    public function submitModerationClarification(Request $request, SellerComplianceCase $case, ProductModerationScan $scan, ProductModerationService $moderation)
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id
            && (int) $scan->seller_profile_id === (int) $seller->id
            && (int) $scan->seller_compliance_case_id === (int) $case->id, 404);
        $data = $request->validate([
            'explanation' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        DB::transaction(function () use ($case, $seller, $scan, $data, $request): void {
            $lockedScan = ProductModerationScan::query()->whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedScan->seller_profile_id !== (int) $seller->id
                || (int) $lockedScan->seller_compliance_case_id !== (int) $case->id
                || $lockedScan->appeal_status !== 'APPEAL_UNDER_REVIEW') {
                abort(409, 'This appeal is not awaiting clarification.');
            }

            $entry = '[Seller clarification '.now()->format('Y-m-d H:i:s T').'] '.trim($data['explanation']);
            $combined = trim((string) $lockedScan->appeal_explanation."\n\n".$entry);
            $lockedScan->update([
                'appeal_status' => 'APPEAL_SUBMITTED',
                'appeal_explanation' => mb_substr($combined, 0, 60000),
                'appeal_submitted_at' => now(),
            ]);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'PRODUCT_AI_APPEAL_CLARIFICATION_SUBMITTED',
                'auditable_type' => ProductModerationScan::class,
                'auditable_id' => $lockedScan->id,
                'old_values' => ['appeal_status' => 'APPEAL_UNDER_REVIEW'],
                'new_values' => ['appeal_status' => 'APPEAL_SUBMITTED', 'product_id' => $lockedScan->product_id],
                'created_at' => now(),
            ]);
        });

        $moderation->notifyAdminsOfAppeal($scan->fresh(), true);

        return redirect()->route('seller.compliance.show', ['case' => $case->id])
            ->with('status', 'Your appeal clarification was submitted for Admin review.');
    }

    public function downloadModerationAppealEvidence(Request $request, SellerComplianceCase $case, ProductModerationScan $scan)
    {
        $seller = $this->seller($request);
        abort_unless((int) $case->seller_profile_id === (int) $seller->id
            && (int) $scan->seller_profile_id === (int) $seller->id
            && (int) $scan->seller_compliance_case_id === (int) $case->id, 404);
        abort_unless($scan->appeal_evidence_path && Storage::disk('local')->exists($scan->appeal_evidence_path), 404);

        return Storage::disk('local')->download(
            $scan->appeal_evidence_path,
            $scan->appeal_evidence_original_name ?: 'appeal-evidence',
            array_filter(['Content-Type' => $scan->appeal_evidence_mime]),
        );
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
