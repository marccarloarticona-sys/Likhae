<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Seller\Voucher;
use App\Services\Marketplace\BuyerVoucherClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuyerVoucherClaimController extends Controller
{
    public function store(Request $request, Voucher $voucher, BuyerVoucherClaimService $claims): RedirectResponse
    {
        $claims->claim($request->user(), (int) $voucher->id);

        return redirect()->route('buyer.rewards')->with('buyer_notice', 'Voucher collected. Checkout will recheck its availability and order eligibility.');
    }
}
