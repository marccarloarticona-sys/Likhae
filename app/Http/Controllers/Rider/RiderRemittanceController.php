<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\RemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use RuntimeException;

class RiderRemittanceController extends Controller
{
    public function index(Request $request, RemittanceService $remittances, FinanceSchemaReadiness $schema): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        $schemaReady = $schema->step6Ready();
        $history = $schemaReady
            ? $rider->remittances()->with('items.codCollection.shipment')->latest()->paginate(15)
            : new LengthAwarePaginator([], 0, 15, max(1, $request->integer('page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

        return view('Rider.remittances.index', [
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
        $data = $request->validate([
            'collection_ids' => ['required', 'array', 'min:1'],
            'collection_ids.*' => ['required', 'integer'],
            'submitted_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $remittances->submit($request->user(), $data['collection_ids'], $data['submitted_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['collection_ids' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remittance submitted. Your destination logistics center must verify and reconcile the cash.');
    }
}
