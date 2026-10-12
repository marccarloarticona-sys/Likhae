<?php

namespace App\Http\Controllers\Admin;

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

class AdminOnboardingController extends Controller
{
    private const ADMIN_MANAGED_ACCOUNT_TYPES = [
        User::TYPE_BUYER,
        User::TYPE_SELLER,
        User::TYPE_LOGISTICS,
    ];

    private const STATUS_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public function __construct(
        private readonly RegistrationWorkflowService $workflow,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                self::STATUS_NEEDS_REVIEW,
                RegistrationApplication::STATUS_PENDING,
                RegistrationApplication::STATUS_UNDER_REVIEW,
            ])],
            'type' => ['nullable', Rule::in([
                ...self::ADMIN_MANAGED_ACCOUNT_TYPES,
            ])],
        ]);

        $status = $filters['status'] ?? self::STATUS_NEEDS_REVIEW;
        $type = $filters['type'] ?? null;
        $statusFilter = match ($status) {
            self::STATUS_NEEDS_REVIEW => [
                RegistrationApplication::STATUS_PENDING,
                RegistrationApplication::STATUS_UNDER_REVIEW,
            ],
            default => [$status],
        };

        $managedApplications = RegistrationApplication::query()
            ->whereHas('user', fn ($query) => $query->whereIn('account_type', self::ADMIN_MANAGED_ACCOUNT_TYPES));

        $registrationCounts = (clone $managedApplications)
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->whereIn('status', [
                RegistrationApplication::STATUS_PENDING,
                RegistrationApplication::STATUS_UNDER_REVIEW,
            ])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $records = (clone $managedApplications)
            ->with([
                'user.addresses',
                'sellerData.category',
                'logisticsData.businessAddress',
            ])
            ->when($statusFilter !== null, fn ($query) => $query->whereIn('status', $statusFilter))
            ->when($type, fn ($query) => $query->whereHas('user', fn ($userQuery) => $userQuery->where('account_type', $type)))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('Admin.registrations.index', [
            'records' => $records,
            'status' => $status,
            'type' => $type,
            'registrationStats' => [
                'pending' => (int) ($registrationCounts[RegistrationApplication::STATUS_PENDING] ?? 0),
                'under_review' => (int) ($registrationCounts[RegistrationApplication::STATUS_UNDER_REVIEW] ?? 0),
            ],
        ]);
    }

    public function show(RegistrationApplication $application): View
    {
        $application->load([
            'user.addresses',
            'documents.verifier',
            'sellerData.category',
            'logisticsData.businessAddress',
            'reviewer',
        ]);

        abort_unless(
            $application->user
                && in_array($application->user->account_type, self::ADMIN_MANAGED_ACCOUNT_TYPES, true),
            404,
        );

        return view('Admin.registrations.show', compact('application'));
    }

    public function approve(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $this->ensureAdminReviewable($application);
        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->workflow->approveByAdmin(
                application: $application,
                reviewer: $request->user(),
                notes: $data['decision_notes'] ?? null,
                request: $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()
            ->route('admin.registrations.show', $application)
            ->with('success', 'Registration approved. The account is now active.');
    }

    public function reject(Request $request, RegistrationApplication $application): RedirectResponse
    {
        $this->ensureAdminReviewable($application);

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
            ->route('admin.registrations.show', $application)
            ->with('success', 'Registration rejected.');
    }

    public function document(
        RegistrationApplication $application,
        ApplicationDocument $document,
        Request $request,
    ): StreamedResponse {
        $this->ensureAdminReviewable($application);
        abort_unless($document->registration_application_id === $application->id, 404);
        abort_unless(Storage::disk('registrations')->exists($document->file_path), 404);

        $extension = pathinfo($document->original_name ?: $document->file_path, PATHINFO_EXTENSION);
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

    public function verifyDocument(
        Request $request,
        RegistrationApplication $application,
        ApplicationDocument $document,
    ): RedirectResponse {
        $this->ensureAdminReviewable($application);
        $this->ensureDocumentBelongsToApplication($application, $document);

        try {
            $this->workflow->verifyDocument(
                application: $application,
                document: $document,
                reviewer: $request->user(),
                request: $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Document marked as verified.');
    }

    public function rejectDocument(
        Request $request,
        RegistrationApplication $application,
        ApplicationDocument $document,
    ): RedirectResponse {
        $this->ensureAdminReviewable($application);
        $this->ensureDocumentBelongsToApplication($application, $document);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $this->workflow->rejectDocument(
                application: $application,
                document: $document,
                reviewer: $request->user(),
                reason: $data['reason'],
                request: $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', 'Document marked as rejected.');
    }

    public function sellers(): RedirectResponse
    {
        return redirect()->route('admin.registrations', ['type' => User::TYPE_SELLER]);
    }

    public function couriers(): RedirectResponse
    {
        return redirect()->route('admin.registrations', ['type' => User::TYPE_LOGISTICS]);
    }

    private function ensureAdminReviewable(RegistrationApplication $application): void
    {
        $application->loadMissing('user');

        abort_unless(
            $application->user
                && in_array($application->user->account_type, self::ADMIN_MANAGED_ACCOUNT_TYPES, true),
            404,
        );
    }

    private function ensureDocumentBelongsToApplication(
        RegistrationApplication $application,
        ApplicationDocument $document,
    ): void {
        abort_unless((int) $document->registration_application_id === (int) $application->id, 404);
    }
}
