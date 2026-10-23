<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Seller\Product;
use App\Services\Marketplace\FlashSaleReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FlashSaleReminderController extends Controller
{
    public function store(Request $request, Product $product, FlashSaleReminderService $reminders): RedirectResponse
    {
        $reminders->register($request->user(), $product);

        return back()->with('buyer_notice', 'Reminder set. We’ll notify you about 10 minutes before this flash sale starts.');
    }

    public function destroy(Request $request, Product $product, FlashSaleReminderService $reminders): RedirectResponse
    {
        $reminders->cancel($request->user(), $product);

        return back()->with('buyer_notice', 'Flash sale reminder cancelled.');
    }
}
