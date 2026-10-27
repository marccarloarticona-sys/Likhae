<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\SellerSettlement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutProofController extends Controller
{
    public function seller(Request $request, SellerSettlement $settlement): StreamedResponse
    {
        $profile = $request->user()->sellerProfile;
        abort_unless($profile && (int) $settlement->seller_profile_id === (int) $profile->id, 403);

        return $this->download($settlement->payout_proof_path, $settlement->payout_proof_name);
    }

    public function logistics(Request $request, FinancialLedgerEntry $entry): StreamedResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center
            && $entry->entry_type === 'LOGISTICS_PAYOUT'
            && (int) data_get($entry->metadata, 'logistics_center_id') === (int) $center->id, 403);

        return $this->download(
            data_get($entry->metadata, 'payout_proof_path'),
            data_get($entry->metadata, 'payout_proof_name'),
        );
    }

    public function rider(Request $request, FinancialLedgerEntry $entry): StreamedResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider
            && $entry->entry_type === 'RIDER_PAYOUT'
            && (int) data_get($entry->metadata, 'rider_profile_id') === (int) $rider->id, 403);

        return $this->download(
            data_get($entry->metadata, 'payout_proof_path'),
            data_get($entry->metadata, 'payout_proof_name'),
        );
    }

    public function adminSeller(Request $request, SellerSettlement $settlement): StreamedResponse
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN), 403);

        return $this->download($settlement->payout_proof_path, $settlement->payout_proof_name);
    }

    public function adminLogistics(Request $request, FinancialLedgerEntry $entry): StreamedResponse
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN)
            && $entry->entry_type === 'LOGISTICS_PAYOUT', 403);

        return $this->download(
            data_get($entry->metadata, 'payout_proof_path'),
            data_get($entry->metadata, 'payout_proof_name'),
        );
    }

    private function download(?string $path, ?string $name): StreamedResponse
    {
        abort_unless(is_string($path) && str_starts_with($path, 'payout-proofs/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download(
            $path,
            basename($name ?: 'payout-proof'),
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
