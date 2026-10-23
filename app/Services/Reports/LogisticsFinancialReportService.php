<?php

namespace App\Services\Reports;

use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Logistics\LogisticsCenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LogisticsFinancialReportService
{
    /** @var list<string> */
    private const ENTRY_TYPES = [
        'LOGISTICS_ALLOCATION',
        'PRODUCT_PROCEEDS_REMITTANCE',
        'LOGISTICS_PAYOUT',
    ];

    /**
     * @param array{date_from?:?string,date_to?:?string,status?:?string,rider_id?:?int|string,area_id?:?int|string} $filters
     * @return Builder<FinancialLedgerEntry>
     */
    public function query(LogisticsCenter $center, array $filters): Builder
    {
        $query = FinancialLedgerEntry::query()
            ->where('account_type', 'LOGISTICS')
            ->whereIn('entry_type', self::ENTRY_TYPES)
            ->whereExists(function ($pool) use ($center): void {
                $pool->selectRaw('1')
                    ->from('logistics_earning_pools')
                    ->whereColumn('logistics_earning_pools.shipment_financial_allocation_id', 'financial_ledger_entries.shipment_financial_allocation_id')
                    ->whereColumn('logistics_earning_pools.shipment_id', 'financial_ledger_entries.shipment_id')
                    ->where('logistics_earning_pools.logistics_center_id', $center->id);
            })
            ->with([
                'allocation',
                'shipment.serviceArea',
                'shipment.sellerOrder.order',
                'shipment.riderAssignments' => fn ($assignments) => $assignments
                    ->where('assignment_type', 'DELIVERY')
                    ->orderByDesc('id'),
                'shipment.riderAssignments.riderProfile.user',
            ]);

        if (filled($filters['date_from'] ?? null)) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_from'], config('app.timezone'));
            $query->whereRaw('COALESCE(financial_ledger_entries.paid_at, financial_ledger_entries.posted_at, financial_ledger_entries.created_at) >= ?', [$start->format('Y-m-d H:i:s')]);
        }

        if (filled($filters['date_to'] ?? null)) {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['date_to'], config('app.timezone'))
                ->endOfDay();
            $query->whereRaw('COALESCE(financial_ledger_entries.paid_at, financial_ledger_entries.posted_at, financial_ledger_entries.created_at) <= ?', [$end->format('Y-m-d H:i:s.u')]);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['rider_id'] ?? null)) {
            $query->whereHas('shipment.riderAssignments', fn ($assignments) => $assignments
                ->where('assignment_type', 'DELIVERY')
                ->where('rider_profile_id', $filters['rider_id']));
        }

        if (filled($filters['area_id'] ?? null)) {
            $query->whereHas('shipment', fn (Builder $shipment) => $shipment
                ->where('service_area_id', $filters['area_id']));
        }

        return $query
            ->orderByRaw('COALESCE(financial_ledger_entries.paid_at, financial_ledger_entries.posted_at, financial_ledger_entries.created_at) DESC')
            ->orderByDesc('financial_ledger_entries.id');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{parcel_count:int,transaction_count:int,shipping_fees:float,logistics_share:float,proceeds_expected:float,proceeds_received:float,proceeds_discrepancies:int,payout_requested:float,payout_pending_ack:float,payout_paid:float,admin_product_commission:float}
     */
    public function summary(LogisticsCenter $center, array $filters): array
    {
        $base = $this->query($center, $filters);
        $allocationEntries = fn () => (clone $base)->where('entry_type', 'LOGISTICS_ALLOCATION');
        $proceedsEntries = fn () => (clone $base)->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE');
        $payoutEntries = fn () => (clone $base)->where('entry_type', 'LOGISTICS_PAYOUT');

        $shippingAndCommission = $allocationEntries()
            ->join('shipment_financial_allocations as report_allocations', 'report_allocations.id', '=', 'financial_ledger_entries.shipment_financial_allocation_id');

        return [
            'parcel_count' => (clone $allocationEntries())->distinct('shipment_id')->count('shipment_id'),
            'transaction_count' => (clone $base)->count(),
            'shipping_fees' => (float) (clone $shippingAndCommission)->sum('report_allocations.shipping_fee'),
            'logistics_share' => (float) $allocationEntries()->sum('financial_ledger_entries.amount'),
            'proceeds_expected' => (float) $proceedsEntries()->sum('financial_ledger_entries.amount'),
            'proceeds_received' => $this->receivedProceedsTotal($proceedsEntries()),
            'proceeds_discrepancies' => (clone $proceedsEntries())->where('status', 'DISCREPANCY')->count(),
            'payout_requested' => (float) $payoutEntries()->where('status', 'PAYOUT_REQUESTED')->sum('financial_ledger_entries.amount'),
            'payout_pending_ack' => (float) $payoutEntries()->where('status', 'PAYOUT_PENDING_ACK')->sum('financial_ledger_entries.amount'),
            'payout_paid' => (float) $payoutEntries()->where('status', 'PAID')->sum('financial_ledger_entries.amount'),
            'admin_product_commission' => (float) (clone $shippingAndCommission)->sum('report_allocations.platform_commission'),
        ];
    }

    /** @return list<string> */
    public function statuses(LogisticsCenter $center): array
    {
        return FinancialLedgerEntry::query()
            ->where('account_type', 'LOGISTICS')
            ->whereIn('entry_type', self::ENTRY_TYPES)
            ->whereExists(function ($pool) use ($center): void {
                $pool->selectRaw('1')
                    ->from('logistics_earning_pools')
                    ->whereColumn('logistics_earning_pools.shipment_financial_allocation_id', 'financial_ledger_entries.shipment_financial_allocation_id')
                    ->whereColumn('logistics_earning_pools.shipment_id', 'financial_ledger_entries.shipment_id')
                    ->where('logistics_earning_pools.logistics_center_id', $center->id);
            })
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(FinancialLedgerEntry $entry): array
    {
        $shipment = $entry->shipment;
        $allocation = $entry->allocation;
        $metadata = (array) $entry->metadata;
        $rider = $shipment?->riderAssignments?->first()?->riderProfile?->user;
        $destination = collect([
            $shipment?->destination_barangay_name,
            $shipment?->destination_municipality_name,
            $shipment?->destination_province_name,
        ])->filter()->implode(', ');
        $timestamp = $entry->paid_at ?: $entry->posted_at ?: $entry->created_at;
        $isAllocation = $entry->entry_type === 'LOGISTICS_ALLOCATION';

        return [
            'date' => $timestamp?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
            'tracking' => (string) ($shipment?->tracking_number ?? ''),
            'order' => (string) ($shipment?->sellerOrder?->order?->order_number ?? ''),
            'area' => (string) ($shipment?->serviceArea?->name ?? ''),
            'destination' => $destination,
            'rider' => (string) ($rider?->name ?? ''),
            'transaction' => $entry->entry_type,
            'direction' => $entry->direction,
            'amount' => number_format((float) $entry->amount, 2, '.', ''),
            'status' => $entry->status,
            'shipping_fee' => $isAllocation && $allocation ? number_format((float) $allocation->shipping_fee, 2, '.', '') : '',
            'logistics_share' => $isAllocation && $allocation ? number_format((float) $allocation->logistics_allocation, 2, '.', '') : '',
            'admin_product_commission' => $isAllocation && $allocation ? number_format((float) $allocation->platform_commission, 2, '.', '') : '',
            'expected' => (string) ($metadata['amount_expected'] ?? ($entry->entry_type === 'PRODUCT_PROCEEDS_REMITTANCE' ? $entry->amount : '')),
            'submitted' => (string) ($metadata['amount_submitted'] ?? ''),
            'received' => (string) ($metadata['amount_received'] ?? ''),
            'discrepancy' => (string) ($metadata['discrepancy_amount'] ?? ''),
            'reference' => (string) ($metadata['receipt_reference'] ?? $metadata['payout_reference'] ?? ''),
        ];
    }

    /** @return list<string> */
    public function csvHeader(): array
    {
        return [
            'Date', 'Tracking', 'Order', 'Service area', 'Destination', 'Delivery rider',
            'Transaction', 'Direction', 'Amount PHP', 'Status', 'Shipping fee PHP',
            'Logistics shipping share PHP', 'Admin product commission PHP', 'Expected PHP',
            'Submitted PHP', 'Received PHP', 'Discrepancy PHP', 'Reference',
        ];
    }

    /** @return list<string> */
    public function csvRow(FinancialLedgerEntry $entry): array
    {
        return array_values($this->row($entry));
    }

    /** @param resource $stream
     *  @param array<string, mixed> $filters
     */
    public function writeCsvToStream($stream, LogisticsCenter $center, array $filters): void
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('A writable CSV stream is required.');
        }

        fputcsv($stream, $this->csvHeader());
        fputcsv($stream, []);
        fputcsv($stream, ['Financial summary']);
        $summary = $this->summary($center, $filters);
        foreach ([
            ['Parcels with allocation', (string) $summary['parcel_count']],
            ['Recorded shipping fees PHP', number_format($summary['shipping_fees'], 2, '.', '')],
            ['Logistics shipping share PHP', number_format($summary['logistics_share'], 2, '.', '')],
            ['Admin product commission PHP', number_format($summary['admin_product_commission'], 2, '.', '')],
            ['Product proceeds expected PHP', number_format($summary['proceeds_expected'], 2, '.', '')],
            ['Product proceeds received PHP', number_format($summary['proceeds_received'], 2, '.', '')],
            ['Product proceeds discrepancies', (string) $summary['proceeds_discrepancies']],
            ['Payout requested PHP', number_format($summary['payout_requested'], 2, '.', '')],
            ['Payout pending acknowledgment PHP', number_format($summary['payout_pending_ack'], 2, '.', '')],
            ['Payout paid PHP', number_format($summary['payout_paid'], 2, '.', '')],
        ] as $row) {
            fputcsv($stream, $row);
        }
        fputcsv($stream, []);

        $this->query($center, $filters)
            ->reorder()
            ->lazyById(500, 'financial_ledger_entries.id', 'id')
            ->each(fn (FinancialLedgerEntry $entry) => fputcsv($stream, $this->csvRow($entry)));
    }

    /** @return list<string> */
    public function pdfRow(FinancialLedgerEntry $entry): array
    {
        $row = $this->row($entry);

        return [
            $row['date'],
            trim($row['tracking'].($row['order'] !== '' ? ' / '.$row['order'] : '')),
            $row['transaction'],
            'PHP '.$row['amount'],
            $row['status'],
            $row['shipping_fee'] === '' ? '' : 'PHP '.$row['shipping_fee'],
            $row['logistics_share'] === '' ? '' : 'PHP '.$row['logistics_share'],
            $row['admin_product_commission'] === '' ? '' : 'PHP '.$row['admin_product_commission'],
        ];
    }

    /** Sum the persisted actual-received amount, including a partially received discrepancy. */
    private function receivedProceedsTotal(Builder $query): float
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(CAST(json_extract(financial_ledger_entries.metadata, '$.amount_received') AS REAL), CASE WHEN financial_ledger_entries.status = 'RECEIVED' THEN financial_ledger_entries.amount ELSE 0 END)"
            : "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(financial_ledger_entries.metadata, '$.amount_received')) AS DECIMAL(12, 2)), CASE WHEN financial_ledger_entries.status = 'RECEIVED' THEN financial_ledger_entries.amount ELSE 0 END)";

        return (float) $query->sum(DB::raw($expression));
    }
}
