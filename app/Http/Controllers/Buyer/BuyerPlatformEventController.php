<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Seller\Voucher;
use App\Services\Marketplace\PlatformCampaignCatalog;
use Illuminate\View\View;

class BuyerPlatformEventController extends Controller
{
    public function __construct(private readonly PlatformCampaignCatalog $campaigns) {}

    public function index(): View
    {
        return view('Buyer.platform-events.index', [
            'campaigns' => $this->campaigns->available(),
        ]);
    }

    public function show(Voucher $voucher): View
    {
        $campaign = $this->campaigns->available()->firstWhere('id', $voucher->id);
        abort_unless($campaign instanceof Voucher, 404);

        return view('Buyer.platform-events.show', [
            'campaign' => $campaign,
        ]);
    }
}
