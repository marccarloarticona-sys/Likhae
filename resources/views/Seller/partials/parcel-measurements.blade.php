@if(in_array(($order['status_key'] ?? null), ['preparing', 'ready-for-pickup'], true))
    <section class="sl-card">
        <header class="sl-card-head">
            <div>
                <h2>Packed parcel measurements</h2>
                <p>Enter the measured weight and volume for each packed parcel. These values are used for transfer vehicle capacity.</p>
            </div>
        </header>
        <div class="space-y-4">
            @foreach($order['items'] as $item)
                <div class="rounded-xl border border-stone-200 p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <strong>{{ $item->product_name }} · Qty {{ $item->quantity }}</strong>
                        @if($item->shipment)
                            <span class="text-xs text-stone-600">{{ $item->shipment->tracking_number }}</span>
                        @endif
                    </div>
                    @if($item->shipment && $item->shipment->current_status === 'PREPARING')
                        <form method="POST" action="{{ route('seller.orders.parcels.measurements', [$order['db_id'], $item->id]) }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            @csrf
                            @method('PATCH')
                            <label class="text-xs font-semibold text-stone-700">Packed weight (kg)
                                <input name="parcel_weight_kg" type="number" min="0.001" max="9999999.999" step="0.001" required value="{{ old('parcel_weight_kg', $item->shipment->parcel_weight_kg) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                            </label>
                            <label class="text-xs font-semibold text-stone-700">Packed volume (liters)
                                <input name="parcel_volume_liters" type="number" min="0.001" max="999999999.999" step="0.001" required value="{{ old('parcel_volume_liters', $item->shipment->parcel_volume_liters) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                            </label>
                            <button class="sl-btn sl-btn-primary" type="submit">Save measurements</button>
                        </form>
                    @elseif($item->shipment)
                        <p class="text-sm text-stone-600">Packed weight: {{ $item->shipment->parcel_weight_kg ?? 'Not recorded' }} kg · Volume: {{ $item->shipment->parcel_volume_liters ?? 'Not recorded' }} L</p>
                    @else
                        <p class="text-sm text-amber-800">Create this product line's parcel label before recording measurements.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
