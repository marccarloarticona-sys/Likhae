<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Finance\SellerSettlement;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminFinanceController extends Controller
{
    public function authorizeSellerSettlement(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        try {
            $service->authorize($request->user(), $settlement, $request->input('notes'));
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['settlement' => $exception->getMessage()]);
        }

        return back()->with('status', 'Seller settlement authorized. Payment remains a separate action.');
    }

    public function paySeller(Request $request, SellerSettlement $settlement, SellerSettlementService $service): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->pay($request->user(), $settlement, $data['amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return back()->with('status', 'Seller payout recorded with a settlement reference.');
    }
}
