<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\User;
use App\Services\RegistrationWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RiderApplicationController extends Controller
{
    public function __construct(
        private readonly RegistrationWorkflowService $workflow,
    ) {
    }

    public function index(Request $request): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                RegistrationApplication::STATUS_PENDING,
                RegistrationApplication::STATUS_UNDER_REVIEW,
                RegistrationApplication::STATUS_APPROVED,
                RegistrationApplication::STATUS_REJECTED,
                RegistrationApplication::STATUS_CANCELLED,
            ])],
        ]);

        $status = $filters['status'] ?? null;

        $query = RegistrationApplication::query()
            ->with(['user.addresses', 'documents', 'riderData.targetLogisticsCenter', 'reviewer'])
            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER))
            ->whereHas('riderData', fn ($query) => $query->where('target_logistics_center_id', $center->id));

        if ($status) {
            $query->where('status', $status);
        }

        $applications = $query
            ->latest('submitted_at')
            ->get();

        $summarySource = RegistrationApplication::query()
            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER))
            ->whereHas('riderData', fn ($query) => $query->where('target_logistics_center_id', $center->id));

        $summary = [
            ['label' => 'Total Applications', 'value' => (clone $summarySource)->count(), 'description' => 'All rider registrations', 'tone' => 'primary', 'icon' => 'applications'],
            ['label' => 'Pending', 'value' => (clone $summarySource)->whereIn('status', [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW])->count(), 'description' => 'Awaiting verification', 'tone' => 'warning', 'icon' => 'clock'],
            ['label' => 'Approved', 'value' => (clone $summarySource)->where('status', RegistrationApplication::STATUS_APPROVED)->count(), 'description' => 'Verified rider accounts', 'tone' => 'success', 'icon' => 'check'],
            ['label' => 'Rejected', 'value' => (clone $summarySource)->where('status', RegistrationApplication::STATUS_REJECTED)->count(), 'description' => 'Applications declined', 'tone' => 'danger', 'icon' => 'x'],
        ];

        $rows = $applications->map(function (RegistrationApplication $application): array {
            $user = $application->user;
            $data = $application->riderData;

            return [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'name' => $user?->name ?? 'Rider Applicant',
                'email' => $user?->email ?? 'Not recorded',
                'status' => str($application->status)->replace('_', ' ')->headline()->toString(),
                'vehicle' => $data?->vehicle_type ? str($data->vehicle_type)->headline()->toString() : 'Not provided',
                'plate' => $data?->plate_number ?? 'Not provided',
                'center' => $data?->targetLogisticsCenter?->business_name ?? 'Not recorded',
                'submitted' => $application->submitted_at?->diffForHumans() ?? 'Recently',
                'is_pending' => in_array($application->status, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true),
            ];
        });

        return view('Logistics.riders.application.index', [
            'logisticsRiderApplications' => $rows,
            'logisticsRiderSummary' => $summary,
            'statusFilter' => $status,
        ]);
    }

    public function show(Request $request, RegistrationApplication $application): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $application->load(['user.addresses', 'documents', 'riderData.targetLogisticsCenter', 'reviewer']);

        abort_unless(
            $application->user?->account_type === User::TYPE_RIDER
                && (int) $application->riderData?->target_logistics_center_id === (int) $center->id,
            404,
        );

        $address = $application->user?->addresses?->firstWhere('is_default', true)
            ?? $application->user?->addresses?->first();

        return view('Logistics.riders.application.show', [
            'application' => $application,
            'rider' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'name' => $application->user?->name ?? 'Rider Applicant',
                'email' => $application->user?->email ?? 'Not recorded',
                'contact' => $application->user?->contact_number ?? 'Not recorded',
                'address' => $address?->formatted() ?? 'Not recorded',
                'vehicle' => $application->riderData?->vehicle_type ? str($application->riderData->vehicle_type)->headline()->toString() : 'Not provided',
                'plate' => $application->riderData?->plate_number ?? 'Not provided',
                'license' => $application->riderData?->drivers_license_number ?? 'Not provided',
                'center' => $application->riderData?->targetLogisticsCenter?->business_name ?? 'Not recorded',
                'submitted' => $application->submitted_at?->format('M d, Y h:i A') ?? 'Not recorded',
                'status' => str($application->status)->replace('_', ' ')->headline()->toString(),
            ],
        ]);
    }

    public function approve(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $this->ensureAssignedRiderApplication($request, $application);
        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->workflow->approveRider(
                application: $application,
                reviewer: $request->user(),
                notes: $data['decision_notes'] ?? null,
                request: $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('logistics.riders.application.show', $application)
            ->with('success', 'Rider application approved. The rider account is now active.');
    }

    public function reject(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $this->ensureAssignedRiderApplication($request, $application);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $this->workflow->reject(
                application: $application,
                reviewer: $request->user(),
                reason: $data['reason'],
                request: $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('logistics.riders.application.show', $application)
            ->with('success', 'Rider application rejected.');
    }

    public function document(
        Request $request,
        RegistrationApplication $application,
        ApplicationDocument $document,
    ): StreamedResponse {
        $this->ensureAssignedDocument($request, $application, $document);
        abort_unless(Storage::disk('registrations')->exists($document->file_path), 404);

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $filename = strtolower($document->document_type).($extension ? '.'.$extension : '');

        if ($request->boolean('preview')) {
            return Storage::disk('registrations')->response(
                $document->file_path,
                $filename,
                ['Content-Type' => $document->mime_type ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
                'inline',
            );
        }

        return Storage::disk('registrations')->download(
            $document->file_path,
            $filename,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function verifyDocument(Request $request, RegistrationApplication $application, ApplicationDocument $document): RedirectResponse
    {
        $this->ensureAssignedDocument($request, $application, $document);

        try {
            $this->workflow->verifyDocument($application, $document, $request->user(), $request);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Document marked as verified.');
    }

    public function rejectDocument(Request $request, RegistrationApplication $application, ApplicationDocument $document): RedirectResponse
    {
        $this->ensureAssignedDocument($request, $application, $document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $this->workflow->rejectDocument($application, $document, $request->user(), $data['reason'], $request);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', 'Document marked as rejected.');
    }

    private function ensureAssignedRiderApplication(Request $request, RegistrationApplication $application): void
    {
        $center = $request->user()->logisticsCenter()->approvedActive()->firstOrFail();
        $application->loadMissing(['user', 'riderData']);

        abort_unless(
            $application->user?->account_type === User::TYPE_RIDER
                && (int) $application->riderData?->target_logistics_center_id === (int) $center->id,
            404,
        );
    }

    private function ensureAssignedDocument(Request $request, RegistrationApplication $application, ApplicationDocument $document): void
    {
        $this->ensureAssignedRiderApplication($request, $application);
        abort_unless((int) $document->registration_application_id === (int) $application->id, 404);
    }
}
