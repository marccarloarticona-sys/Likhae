<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Returns\CashRefundObligation;
use App\Services\Returns\CashRefundWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminCashRefundController extends Controller
{
    public function markPaid(Request $request, CashRefundObligation $obligation, CashRefundWorkflowService $cashRefunds): RedirectResponse
    {
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:150'],
            'confirm_cash_paid' => ['required', 'accepted'],
        ]);
        try {
            $cashRefunds->markCashPaid($obligation, $request->user(), $data['payment_reference']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['cash_refund' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Cash refund payment recorded. Buyer receipt confirmation is still required.');
    }
}
