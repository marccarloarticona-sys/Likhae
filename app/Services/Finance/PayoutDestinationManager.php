<?php

namespace App\Services\Finance;

use App\Support\PayoutAccountDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PayoutDestinationManager
{
    /** @return list<array{id: string, payout_method: string, payout_bank_name: ?string, payout_account_name: string, payout_account_type: string, payout_account_number: string, is_default: bool}> */
    public function accounts(Model $owner): array
    {
        $saved = $owner->payout_destinations;
        $accounts = is_array($saved) ? array_values(array_filter($saved, 'is_array')) : [];

        if ($accounts === [] && $this->hasLegacyData($owner)) {
            $accounts[] = $this->legacyAccount($owner);
        }

        $defaultId = null;
        foreach ($accounts as $index => $account) {
            if (! empty($account['is_default']) && $this->isCompleteDestination($account)) {
                $defaultId = (string) ($account['id'] ?? ($index === 0 ? 'legacy' : ''));
                break;
            }
        }
        if ($defaultId === null) {
            foreach ($accounts as $index => $account) {
                if ($this->isCompleteDestination($account)) {
                    $defaultId = (string) ($account['id'] ?? ($index === 0 ? 'legacy' : ''));
                    break;
                }
            }
        }

        return array_values(array_map(function (array $account, int $index) use ($defaultId): array {
            return [
                'id' => (string) ($account['id'] ?? ($index === 0 ? 'legacy' : Str::uuid())),
                'payout_method' => (string) ($account['payout_method'] ?? ''),
                'payout_bank_name' => filled($account['payout_bank_name'] ?? null) ? (string) $account['payout_bank_name'] : null,
                'payout_account_name' => (string) ($account['payout_account_name'] ?? ''),
                'payout_account_type' => (string) ($account['payout_account_type'] ?? PayoutAccountDetails::ACCOUNT_NUMBER),
                'payout_account_number' => (string) ($account['payout_account_number'] ?? ''),
                'is_default' => $defaultId !== null
                    && (string) ($account['id'] ?? ($index === 0 ? 'legacy' : '')) === $defaultId,
            ];
        }, $accounts, array_keys($accounts)));
    }

    /** @param array<string, string> $data */
    public function add(Model $owner, array $data, bool $makeDefault): void
    {
        $this->mutate($owner, function (array $accounts) use ($data, $makeDefault): array {
            $id = (string) Str::uuid();
            $hasDefault = collect($accounts)->contains(fn (array $account): bool => ! empty($account['is_default']));
            $makeDefault = $makeDefault || ! $hasDefault;
            if ($makeDefault) {
                foreach ($accounts as &$account) {
                    $account['is_default'] = false;
                }
                unset($account);
            }

            $accounts[] = [
                'id' => $id,
                'payout_method' => $data['payout_method'],
                'payout_bank_name' => $data['payout_bank_name'] ?? null,
                'payout_account_name' => $data['payout_account_name'],
                'payout_account_type' => $data['payout_account_type'],
                'payout_account_number' => $data['payout_account_number'],
                'is_default' => $makeDefault,
            ];

            return $accounts;
        });
    }

    public function setDefault(Model $owner, string $destinationId): void
    {
        $this->mutate($owner, function (array $accounts) use ($destinationId): array {
            $found = false;
            foreach ($accounts as &$account) {
                $selected = (string) ($account['id'] ?? '') === $destinationId;
                if ($selected && ! $this->isCompleteDestination($account)) {
                    throw ValidationException::withMessages(['destination_id' => 'Add complete payout details before making this destination the default.']);
                }
                $account['is_default'] = $selected;
                $found = $found || $account['is_default'];
            }
            unset($account);

            if (! $found) {
                throw ValidationException::withMessages(['destination_id' => 'That payout destination is no longer available. Refresh the page and try again.']);
            }

            return $accounts;
        });
    }

    /** @param array<string, string> $data */
    public function update(Model $owner, string $destinationId, array $data, bool $makeDefault): void
    {
        $this->mutate($owner, function (array $accounts) use ($destinationId, $data, $makeDefault): array {
            $targetIndex = null;
            foreach ($accounts as $index => $account) {
                if ((string) ($account['id'] ?? '') === $destinationId) {
                    $targetIndex = $index;
                    break;
                }
            }

            if ($targetIndex === null) {
                throw ValidationException::withMessages(['destination_id' => 'That payout destination is no longer available. Refresh the page and try again.']);
            }

            $wasDefault = ! empty($accounts[$targetIndex]['is_default']);
            $accounts[$targetIndex] = [
                'id' => $destinationId,
                'payout_method' => $data['payout_method'],
                'payout_bank_name' => $data['payout_bank_name'] ?? null,
                'payout_account_name' => $data['payout_account_name'],
                'payout_account_type' => $data['payout_account_type'],
                'payout_account_number' => $data['payout_account_number'],
                'is_default' => $wasDefault,
            ];

            $hasOtherDefault = collect($accounts)->contains(fn (array $account, int $index): bool => $index !== $targetIndex && ! empty($account['is_default']));
            if ($makeDefault || (! $hasOtherDefault && ! $wasDefault)) {
                foreach ($accounts as &$account) {
                    $account['is_default'] = false;
                }
                unset($account);
                $accounts[$targetIndex]['is_default'] = true;
            }

            return $accounts;
        });
    }

    public function remove(Model $owner, string $destinationId): void
    {
        $this->mutate($owner, function (array $accounts) use ($destinationId): array {
            $remaining = array_values(array_filter(
                $accounts,
                fn (array $account): bool => (string) ($account['id'] ?? '') !== $destinationId,
            ));

            if (count($remaining) === count($accounts)) {
                throw ValidationException::withMessages(['destination_id' => 'That payout destination is no longer available. Refresh the page and try again.']);
            }

            if ($remaining !== [] && ! collect($remaining)->contains(fn (array $account): bool => ! empty($account['is_default']))) {
                foreach ($remaining as &$account) {
                    if ($this->isCompleteDestination($account)) {
                        $account['is_default'] = true;
                        break;
                    }
                }
                unset($account);
            }

            return $remaining;
        });
    }

    private function mutate(Model $owner, callable $change): void
    {
        DB::transaction(function () use ($owner, $change): void {
            $locked = $owner->newQuery()->lockForUpdate()->findOrFail($owner->getKey());
            $accounts = $change($this->accounts($locked));
            $default = collect($accounts)->first(fn (array $account): bool => ! empty($account['is_default']));

            $locked->forceFill([
                'payout_destinations' => $accounts === [] ? null : $accounts,
                'payout_method' => $default['payout_method'] ?? null,
                'payout_bank_name' => $default['payout_bank_name'] ?? null,
                'payout_account_name' => $default['payout_account_name'] ?? null,
                'payout_account_type' => $default['payout_account_type'] ?? null,
                'payout_account_number' => $default['payout_account_number'] ?? null,
            ])->save();
        });
    }

    private function hasLegacyData(Model $owner): bool
    {
        return filled($owner->payout_method)
            || filled($owner->payout_bank_name)
            || filled($owner->payout_account_name)
            || filled($owner->payout_account_number);
    }

    private function isCompleteDestination(array $account): bool
    {
        return filled($account['payout_method'] ?? null)
            && filled($account['payout_account_name'] ?? null)
            && filled($account['payout_account_number'] ?? null)
            && (($account['payout_method'] ?? null) !== 'Bank Transfer' || filled($account['payout_bank_name'] ?? null));
    }

    private function hasLegacyDestination(Model $owner): bool
    {
        return filled($owner->payout_method)
            && filled($owner->payout_account_name)
            && filled($owner->payout_account_number)
            && ($owner->payout_method !== 'Bank Transfer' || filled($owner->payout_bank_name));
    }

    /** @return array{id: string, payout_method: string, payout_bank_name: ?string, payout_account_name: string, payout_account_type: string, payout_account_number: string, is_default: bool} */
    private function legacyAccount(Model $owner): array
    {
        return [
            'id' => 'legacy',
            'payout_method' => (string) $owner->payout_method,
            'payout_bank_name' => $owner->payout_bank_name,
            'payout_account_name' => (string) $owner->payout_account_name,
            'payout_account_type' => PayoutAccountDetails::typeFor($owner),
            'payout_account_number' => (string) $owner->payout_account_number,
            'is_default' => $this->hasLegacyDestination($owner),
        ];
    }
}
