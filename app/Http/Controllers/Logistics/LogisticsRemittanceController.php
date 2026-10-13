<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Finance\RiderRemittance;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\RemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use RuntimeException;

class LogisticsRemittanceController extends Controller
{
    public function index(Request $request, FinanceSchemaReadiness $schema): View
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $schemaReady = $schema->step6Ready();
        $remittances = $schemaReady
            ? RiderRemittance::query()->where('logistics_center_id', $center->id)->with(['riderProfile.user', 'items.codCollection.shipment'])->latest()->paginate(20)
            : new LengthAwarePaginator([], 0, 20, max(1, $request->integer('page', 1)), [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

        return view('Logistics.remittances.index', [
            'center' => $center,
            'remittances' => $remittances,
            'schemaReady' => $schemaReady,
        ]);
    }

    public function verify(Request $request, RiderRemittance $remittance, RemittanceService $service): RedirectResponse
    {
        $data = $request->validate(['verified_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->verify($request->user(), $remittance, $data['verified_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verified_amount' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remittance verified. Reconcile it after comparing expected and verified cash.');
    }

    public function reconcile(Request $request, RiderRemittance $remittance, RemittanceService $service): RedirectResponse
    {
        try {
            $service->reconcile($request->user(), $remittance, $request->input('notes'));
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['remittance' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remittance reconciliation recorded.');
    }
}
