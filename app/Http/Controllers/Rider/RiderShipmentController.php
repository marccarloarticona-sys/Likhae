<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderAssignment;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Media\ImageOptimizationService;
use App\Services\Maps\RiderLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

class RiderShipmentController extends Controller
{
    public function updateLocation(Request $request, RiderAssignment $assignment, RiderLocationService $locations): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:250'],
            'recorded_at' => ['required', 'date'],
        ]);
        abort_unless(is_finite((float) $data['latitude']) && is_finite((float) $data['longitude']), 422, 'Coordinates must be finite numeric values.');

        $location = $locations->update(
            $assignment,
            $request->user(),
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $data['accuracy'],
            Carbon::parse($data['recorded_at']),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy' => (float) $data['accuracy'],
                'recorded_at' => $location->recorded_at?->toIso8601String(),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->with(['shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress'])
            ->latest()
            ->paginate(15);

        return view('Rider.shipments', compact('assignments', 'rider'));
    }

    public function transition(Request $request, RiderAssignment $assignment, ShipmentWorkflowService $workflow, ImageOptimizationService $images, LocalRiderTripService $localTrips): RedirectResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider && (int) $assignment->rider_profile_id === (int) $rider->id, 403);

        $uploadedProof = $request->file('proof_file');
        if ($request->input('action') === 'delivery_success' && $uploadedProof instanceof UploadedFile && ! $uploadedProof->isValid()) {
            $uploadError = $uploadedProof->getError();
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE => 'The photo exceeds the server upload limit. Choose a smaller photo and try again.',
                UPLOAD_ERR_FORM_SIZE => 'The photo exceeds the upload limit. Choose a smaller photo and try again.',
                UPLOAD_ERR_PARTIAL => 'The photo upload was interrupted. Check your connection and try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not receive the photo. Please contact support.',
                default => 'The photo could not be uploaded. Choose another photo and try again.',
            };

            Log::warning('Delivery proof upload failed before validation.', [
                'assignment_id' => $assignment->id,
                'upload_error' => $uploadError,
            ]);

            throw ValidationException::withMessages(['proof_file' => $message]);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,start,pickup_complete,delivery_success,delivery_failed,reject'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
            'scanned_code' => ['required_if:action,pickup_complete', 'nullable', 'string', 'max:150'],
            'failure_reason' => ['required_if:action,delivery_failed', 'nullable', 'string', 'max:1000'],
            'attempt_status' => ['nullable', 'string', 'in:FAILED,RESCHEDULED,RETURNED'],
            'next_attempt_at' => ['required_if:attempt_status,RESCHEDULED', 'nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'collected_amount' => ['required_if:action,delivery_success', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'collection_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Keep below the active PHP upload_max_filesize (2 MB) so a
            // validation message is returned instead of PHP discarding it.
            'proof_file' => ['required_if:action,delivery_success', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1536'],
        ], [
            'proof_file.max' => 'The proof photo must be 1.5 MB or smaller.',
        ]);

        $proofPath = null;
        if ($request->hasFile('proof_file')) {
            $proofPath = $images->store($request->file('proof_file'), 'delivery-proofs');
            if (! $proofPath) {
                Log::error('Delivery proof could not be stored.', [
                    'assignment_id' => $assignment->id,
                    'rider_user_id' => $request->user()->id,
                ]);

                throw ValidationException::withMessages([
                    'proof_file' => 'The server could not save the photo. Please try again or contact support.',
                ]);
            }
            $data['proof_path'] = $proofPath;
        }

        try {
            $result = $workflow->riderTransition($assignment, $data['action'], $request->user(), $data);
            $localTrips->syncAssignment($result);
        } catch (RuntimeException $exception) {
            if ($proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            throw ValidationException::withMessages([
                'action' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            if ($proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        return back()->with('status', 'Rider assignment updated.');
    }
}
