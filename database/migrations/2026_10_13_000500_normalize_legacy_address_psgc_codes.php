<?php

use App\Http\Controllers\Auth\PhilippineAddressController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{table: string, prefix: string}> */
    private const ADDRESS_GROUPS = [
        ['table' => 'addresses', 'prefix' => ''],
        ['table' => 'order_addresses', 'prefix' => ''],
        ['table' => 'service_area_locations', 'prefix' => ''],
        ['table' => 'shipments', 'prefix' => 'origin_'],
        ['table' => 'shipments', 'prefix' => 'destination_'],
    ];

    public function up(): void
    {
        $updated = 0;
        $unresolved = 0;

        DB::transaction(function () use (&$updated, &$unresolved): void {
            foreach (self::ADDRESS_GROUPS as $group) {
                $table = $group['table'];
                $prefix = $group['prefix'];
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = array_map(
                    fn (string $field): string => $prefix.$field,
                    ['province_code', 'province_name', 'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name'],
                );
                if (! collect($columns)->every(fn (string $column): bool => Schema::hasColumn($table, $column))) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($prefix.'province_code')
                    ->whereNotNull($prefix.'municipality_code')
                    ->whereNotNull($prefix.'barangay_code')
                    ->orderBy('id')
                    ->chunk(200, function ($rows) use ($table, $prefix, &$updated, &$unresolved): void {
                        foreach ($rows as $row) {
                            $address = [
                                'province_code' => (string) $row->{$prefix.'province_code'},
                                'province_name' => (string) $row->{$prefix.'province_name'},
                                'municipality_code' => (string) $row->{$prefix.'municipality_code'},
                                'municipality_name' => (string) $row->{$prefix.'municipality_name'},
                                'barangay_code' => (string) $row->{$prefix.'barangay_code'},
                                'barangay_name' => (string) $row->{$prefix.'barangay_name'},
                            ];
                            $canonical = $this->canonicalAddress($address);

                            if (! $canonical) {
                                $unresolved++;
                                logger()->warning('Could not normalize a stored PSGC address.', [
                                    'table' => $table,
                                    'row_id' => $row->id,
                                ]);
                                continue;
                            }

                            $changes = [];
                            foreach ($canonical as $field => $value) {
                                $column = $prefix.$field;
                                if ((string) ($row->{$column} ?? '') !== $value) {
                                    $changes[$column] = $value;
                                }
                            }

                            if ($changes === []) {
                                continue;
                            }

                            if ($table === 'service_area_locations') {
                                $collision = DB::table($table)
                                    ->where('service_area_id', $row->service_area_id)
                                    ->where('barangay_code', $canonical['barangay_code'])
                                    ->where('id', '!=', $row->id)
                                    ->exists();
                                if ($collision) {
                                    $unresolved++;
                                    logger()->warning('Skipped PSGC normalization due to duplicate service-area coverage.', [
                                        'table' => $table,
                                        'row_id' => $row->id,
                                    ]);
                                    continue;
                                }
                            }

                            $updated += DB::table($table)->where('id', $row->id)->update($changes);
                        }
                    });
            }
        });

        logger()->info('Legacy PSGC address normalization completed.', [
            'rows_updated' => $updated,
            'rows_unresolved' => $unresolved,
        ]);
    }

    public function down(): void
    {
        // Canonical PSGC identifiers replace ambiguous legacy codes; they are
        // intentionally not reverted because the original values are not
        // reliably reconstructable from the normalized records.
    }

    /**
     * @param array{province_code: string, province_name: string, municipality_code: string, municipality_name: string, barangay_code: string, barangay_name: string} $address
     * @return array{province_code: string, province_name: string, municipality_code: string, municipality_name: string, barangay_code: string, barangay_name: string}|null
     */
    private function canonicalAddress(array $address): ?array
    {
        $municipality = PhilippineAddressController::canonicalMunicipalityFor(
            $address['province_code'],
            $address['province_name'],
            $address['municipality_code'],
            $address['municipality_name'],
        );
        if (! $municipality) {
            return null;
        }

        $barangays = PhilippineAddressController::barangayRecordsForMunicipality($municipality['municipality_code']);
        $barangay = collect($barangays)->first(fn (array $record): bool =>
            (string) ($record['code'] ?? '') === $address['barangay_code']
        ) ?? collect($barangays)->first(fn (array $record): bool =>
            strcasecmp(trim((string) ($record['name'] ?? '')), trim($address['barangay_name'])) === 0
        );

        if (! $barangay) {
            return null;
        }

        return [
            'province_code' => $municipality['province_code'],
            'province_name' => $municipality['province_name'],
            'municipality_code' => $municipality['municipality_code'],
            'municipality_name' => $municipality['municipality_name'],
            'barangay_code' => (string) $barangay['code'],
            'barangay_name' => (string) $barangay['name'],
        ];
    }
};
