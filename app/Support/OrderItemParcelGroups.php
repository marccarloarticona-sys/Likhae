<?php

namespace App\Support;

use App\Models\Buyer\OrderItem;
use Illuminate\Support\Collection;

final class OrderItemParcelGroups
{
    /** @return Collection<int, array{key: string, type: string, name: string, items: Collection<int, OrderItem>, target_item: OrderItem}> */
    public static function forItems(Collection $items): Collection
    {
        $groups = [];
        $memberships = [];

        foreach ($items->filter(fn (OrderItem $item): bool => $item->product_id !== null)->groupBy('product_id') as $productId => $productItems) {
            if ($productItems->count() < 2) {
                continue;
            }

            self::appendGroup($groups, $memberships, [
                'key' => 'product:'.(int) $productId,
                'type' => 'product',
                'name' => (string) $productItems->first()->product_name,
                'items' => $productItems->values(),
            ]);
        }

        $promotions = [];
        foreach ($items as $item) {
            foreach (array_filter((array) $item->promotion_snapshot, 'is_array') as $snapshot) {
                $promotionId = (int) ($snapshot['promotion_id'] ?? 0);
                $promotionType = strtoupper((string) ($snapshot['promotion_type'] ?? ''));
                if ($promotionId < 1 || ! in_array($promotionType, ['BUNDLE', 'BUY_X_GET_Y'], true)) {
                    continue;
                }

                $key = $promotionType.':'.$promotionId;
                $promotions[$key] ??= [
                    'type' => $promotionType,
                    'name' => trim((string) ($snapshot['name'] ?? '')),
                    'items' => [],
                    'snapshots' => [],
                ];
                $promotions[$key]['items'][(int) $item->id] = $item;
                $promotions[$key]['snapshots'][] = $snapshot;
            }
        }

        foreach ($promotions as $promotionId => $promotion) {
            $promotionItems = collect($promotion['items']);
            $variantIds = collect($promotion['snapshots'])
                ->flatMap(fn (array $snapshot): array => (array) ($snapshot['participant_variant_ids'] ?? []))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0);

            if ($promotion['type'] === 'BUY_X_GET_Y') {
                $variantIds = $variantIds->merge(collect($promotion['snapshots'])
                    ->flatMap(fn (array $snapshot): array => [
                        (int) ($snapshot['buy_variant_id'] ?? 0),
                        (int) ($snapshot['reward_variant_id'] ?? 0),
                    ])
                    ->filter(fn (int $id): bool => $id > 0)
                );
            }

            $variantIds = $variantIds
                ->unique()
                ->values();
            if ($variantIds->isNotEmpty()) {
                $promotionItems = $promotionItems
                    ->merge($items->filter(fn (OrderItem $item): bool => $variantIds->contains((int) $item->product_variant_id)))
                    ->unique('id')
                    ->values();
            }

            if ($promotionItems->count() < 2) {
                continue;
            }

            self::appendGroup($groups, $memberships, [
                'key' => 'promotion:'.$promotionId,
                'type' => 'promotion',
                'name' => $promotion['name'] !== '' ? $promotion['name'] : ($promotion['type'] === 'BUNDLE' ? 'Bundle deal' : 'Buy X Get Y deal'),
                'items' => $promotionItems,
            ]);
        }

        return collect($groups)->values();
    }

    /** @param array<int, array{key: string, type: string, name: string, items: Collection<int, OrderItem>, target_item: OrderItem}> $groups
     *  @param array<string, bool> $memberships
     *  @param array{key: string, type: string, name: string, items: Collection<int, OrderItem>} $group
     */
    private static function appendGroup(array &$groups, array &$memberships, array $group): void
    {
        $group['items'] = $group['items']->sortBy('id')->values();
        $itemIds = $group['items']->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $membership = implode(',', $itemIds);
        if (count($itemIds) < 2 || isset($memberships[$membership])) {
            return;
        }

        $memberships[$membership] = true;
        $group['target_item'] = $group['items']->first();
        $groups[] = $group;
    }
}
