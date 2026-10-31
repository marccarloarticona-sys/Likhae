<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\RemittanceService;
use App\Support\RiderVehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use RuntimeException;

class RiderRemittanceController extends Controller
{
    public function index(Request $request, RemittanceService $remittances, FinanceSchemaReadiness $schema): View|RedirectResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        if (RiderVehicleType::isInterLogistics($rider->vehicle_type)) {
            return redirect()->route('rider.dashboard')->with('status', 'Interhub drivers do not remit parcel COD. Timekeeping and payroll are on your Dashboard.');
        }
        $schemaReady = $schema->step6Ready();
        $history = $schemaReady
            ? $rider->remittances()->with(['items.codCollection.shipment', 'items.codCollection.order'])->latest()->paginate(15)
            : new LengthAwarePaginator([], 0, 15, max(1, $request->integer('page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

        return view('rider.remittances.index', [
            'rider' => $rider,
            'collections' => $schemaReady ? $remittances->availableCollections($rider) : collect(),
            'remittances' => $history,
            'schemaReady' => $schemaReady,
        ]);
    }

    public function store(Request $request, RemittanceService $remittances): RedirectResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless(! RiderVehicleType::isInterLogistics($rider->vehicle_type), 403, 'Interhub drivers do not remit parcel COD.');
        $submittedAmount = $request->input('submitted_amount');
        if (is_string($submittedAmount) && str_contains($submittedAmount, ',')) {
            $submittedAmount = trim($submittedAmount);
            if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $submittedAmount)) {
                $request->merge(['submitted_amount' => str_replace(',', '', $submittedAmount)]);
            }
        }

        $data = $request->validate([
            'collection_ids' => ['required', 'array', 'min:1'],
            'collection_ids.*' => ['required', 'integer'],
            'submitted_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $remittances->submit($request->user(), $data['collection_ids'], $data['submitted_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            $field = str_starts_with($exception->getMessage(), 'The submitted cash must equal the selected COD due total')
                ? 'submitted_amount'
                : 'collection_ids';
            throw \Illuminate\Validation\ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        return back()->with('status', 'Selected parcel remittances submitted. Your destination logistics center must acknowledge the received amount.');
    }
}
