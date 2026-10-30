<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\RegistrationApplication;
use App\Services\RegistrationWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegistrationStatusController extends Controller
{
    public function show(Request $request, RegistrationWorkflowService $workflow): View
    {
        $application = $request->user()->pendingRegistrationApplication();
        abort_unless($application instanceof RegistrationApplication, 404);

        $currentDocuments = $application->documents
            ->groupBy(fn ($document): string => strtoupper((string) $document->document_type))
            ->map(fn ($versions) => $versions->sortByDesc('id')->first())
            ->values();

        return view('auth.registration-status', [
            'application' => $application,
            'currentDocuments' => $currentDocuments,
            'rejectedUploads' => $workflow->rejectedDocumentUploads($application),
        ]);
    }

    public function resubmit(Request $request, RegistrationWorkflowService $workflow): RedirectResponse
    {
        $applicant = $request->user();
        $application = $applicant->pendingRegistrationApplication();
        abort_unless($application instanceof RegistrationApplication, 404);

        $rejectedUploads = $workflow->rejectedDocumentUploads($application);
        abort_if($rejectedUploads === [], 409, 'There are no rejected documents awaiting replacement.');

        $rules = collect($rejectedUploads)
            ->mapWithKeys(fn (array $upload): array => [
                $upload['input'] => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            ])
            ->all();
        $request->validate($rules);

        $files = collect($rejectedUploads)
            ->filter(fn (array $upload): bool => $request->hasFile($upload['input']))
            ->mapWithKeys(fn (array $upload): array => [$upload['input'] => $request->file($upload['input'])])
            ->all();

        if ($files === []) {
            throw ValidationException::withMessages([
                'documents' => 'Choose at least one replacement file to submit.',
            ]);
        }

        $workflow->resubmitRejectedDocuments($application, $files, $applicant, $request);

        return redirect()
            ->route('registration.status')
            ->with('status', 'Your replacement document was submitted. Your application is back in the review queue.');
    }
}
