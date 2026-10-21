@props([
    'order',
])

@php
    $id = data_get($order, 'id');

    $status = data_get($order, 'status', 'to-pay');

    $statusLabel = data_get(
        $order,
        'status_label',
        \Illuminate\Support\Str::headline($status)
    );

    $statusKey = \Illuminate\Support\Str::slug($status);

    $products = collect(data_get($order, 'products', []));
@endphp

@once
<style>
    :root {
        --lk-bg: #FBF7F2;
        --lk-bg-soft: #F6EFE7;
        --lk-bg-alt: #EFE7DE;
        --lk-card: #FFFDF9;

        --lk-border: #EADCCC;
        --lk-border-strong: #DBCEC1;

        --lk-maroon: #561C17;
        --lk-maroon-2: #642920;
        --lk-maroon-light: #7A2A22;
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-gold: #C88418;

        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.045);
        --lk-shadow-card: 0 16px 36px rgba(86, 28, 23, 0.10);
    }

    .lk-order-card {
        overflow: hidden !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 22px !important;

        background:
            radial-gradient(circle at 96% 4%, rgba(193, 151, 113, 0.13), transparent 28%),
            linear-gradient(180deg, #FFFDF9 0%, #FFF9F2 100%) !important;

        color: var(--lk-text) !important;

        box-shadow: var(--lk-shadow-soft) !important;

        transition:
            transform 180ms ease,
            border-color 180ms ease,
            box-shadow 180ms ease !important;
    }

    .lk-order-card:hover {
        transform: translateY(-3px) !important;
        border-color: var(--lk-tan) !important;
        box-shadow: var(--lk-shadow-card) !important;
    }

    .lk-order-head {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-wrap: wrap !important;
        gap: 14px !important;

        padding: 16px 20px !important;

        border-bottom: 1px solid #EFE1D5 !important;

        background:
            radial-gradient(circle at 90% 10%, rgba(193, 151, 113, 0.18), transparent 30%),
            linear-gradient(135deg, #FFFDF9 0%, #F6EFE7 100%) !important;
    }

    .lk-order-title-row {
        display: flex !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        gap: 10px !important;
    }

    .lk-order-number {
        color: var(--lk-text) !important;

        font-size: 13px !important;
        font-weight: 900 !important;
        letter-spacing: -0.02em !important;
    }

    .lk-order-date {
        color: var(--lk-muted) !important;

        font-size: 10px !important;
        font-weight: 600 !important;
    }

    .lk-order-status {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;

        min-height: 28px !important;
        padding: 0 11px !important;

        border: 1px solid transparent !important;
        border-radius: 999px !important;

        font-size: 9px !important;
        font-weight: 900 !important;
        letter-spacing: 0.12em !important;
        text-transform: uppercase !important;
        white-space: nowrap !important;
    }

    .lk-order-status.is-to-pay {
        background: #FFF4D8 !important;
        border-color: #EAD39A !important;
        color: #8A5A05 !important;
    }

    .lk-order-status.is-to-ship {
        background: #F6EFE7 !important;
        border-color: var(--lk-border-strong) !important;
        color: var(--lk-brown) !important;
    }

    .lk-order-status.is-to-receive {
        background: #F5ECEA !important;
        border-color: #E5C1BA !important;
        color: var(--lk-maroon) !important;
    }

    .lk-order-status.is-completed {
        background: #EEF5EA !important;
        border-color: #CFE2C7 !important;
        color: #3F6E35 !important;
    }

    .lk-order-status.is-cancelled {
        background: #F6E3DE !important;
        border-color: #E6B8AD !important;
        color: #A83228 !important;
    }

    .lk-order-status.is-returns {
        background: #FFF0E0 !important;
        border-color: #E8C59E !important;
        color: #9A4F16 !important;
    }

    .lk-order-products {
        display: grid !important;
        background: var(--lk-card) !important;
    }

    .lk-order-product {
        display: flex !important;
        gap: 14px !important;

        padding: 18px 20px !important;

        border-bottom: 1px solid #EFE1D5 !important;
    }

    .lk-order-product:last-child {
        border-bottom: 0 !important;
    }

    .lk-order-product-image {
        display: flex !important;
        width: 72px !important;
        height: 72px !important;
        flex: 0 0 72px !important;
        align-items: center !important;
        justify-content: center !important;

        overflow: hidden !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 16px !important;

        background:
            radial-gradient(circle at 50% 20%, rgba(255, 255, 255, 0.85), transparent 34%),
            linear-gradient(135deg, #F3ECE4 0%, #EADCCC 100%) !important;

        color: var(--lk-muted-2) !important;
    }

    .lk-order-product-image img {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
    }

    .lk-order-product-image svg {
        width: 30px !important;
        height: 30px !important;

        fill: none !important;
        stroke: currentColor !important;
        stroke-width: 1.45 !important;
    }

    .lk-order-product-copy {
        min-width: 0 !important;
        flex: 1 !important;
    }

    .lk-order-product-name {
        display: -webkit-box !important;
        overflow: hidden !important;

        margin: 0 !important;

        color: var(--lk-text) !important;

        font-size: 14px !important;
        font-weight: 900 !important;
        line-height: 1.35 !important;
        letter-spacing: -0.025em !important;

        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2 !important;
    }

    .lk-order-product-meta {
        margin: 6px 0 0 !important;

        color: var(--lk-muted) !important;

        font-size: 11px !important;
        line-height: 1.5 !important;
    }

    .lk-order-product-price {
        display: block !important;
        margin-top: 7px !important;

        color: var(--lk-maroon) !important;

        font-size: 13px !important;
        font-weight: 900 !important;
    }

    .lk-order-notice {
        padding: 14px 20px !important;

        border-top: 1px solid #EFE1D5 !important;

        font-size: 11px !important;
        font-weight: 600 !important;
        line-height: 1.65 !important;
    }

    .lk-order-notice.is-receive {
        background: #F5ECEA !important;
        color: var(--lk-maroon) !important;
    }

    .lk-order-notice.is-return {
        background: #FFF0E0 !important;
        color: #9A4F16 !important;
    }

    .lk-order-notice strong {
        font-weight: 900 !important;
    }

    .lk-order-foot {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-wrap: wrap !important;
        gap: 14px !important;

        padding: 16px 20px !important;

        border-top: 1px solid #EFE1D5 !important;

        background: #FFFDF9 !important;
    }

    .lk-order-payment {
        color: var(--lk-muted) !important;

        font-size: 12px !important;
        font-weight: 600 !important;
    }

    .lk-order-total {
        color: var(--lk-text) !important;

        font-size: 15px !important;
        font-weight: 950 !important;
    }

    .lk-order-actions {
        display: flex !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        justify-content: flex-end !important;
        gap: 8px !important;
    }

    .lk-order-actions form {
        margin: 0 !important;
    }

    .lk-order-actions .lk-btn {
        min-height: 38px !important;
        padding: 0 14px !important;

        border-radius: 12px !important;

        font-size: 10px !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        text-decoration: none !important;

        transition:
            transform 160ms ease,
            background 160ms ease,
            border-color 160ms ease,
            color 160ms ease !important;
    }

    .lk-order-actions .lk-btn:hover {
        transform: translateY(-1px) !important;
    }

    .lk-order-actions .lk-btn-light {
        border: 1px solid var(--lk-tan) !important;
        background: rgba(255, 253, 249, 0.8) !important;
        color: var(--lk-maroon) !important;
    }

    .lk-order-actions .lk-btn-light:hover {
        border-color: var(--lk-maroon) !important;
        background: #F5ECEA !important;
        color: var(--lk-maroon-dark) !important;
    }

    .lk-order-actions .lk-btn-red {
        border: 1px solid var(--lk-maroon) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;

        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.14) !important;
    }

    .lk-order-actions .lk-btn-red:hover {
        border-color: var(--lk-maroon-dark) !important;
        background: var(--lk-maroon-dark) !important;
        color: #FFFFFF !important;
    }

    @media (max-width: 640px) {
        .lk-order-head,
        .lk-order-foot {
            align-items: flex-start !important;
            flex-direction: column !important;
        }

        .lk-order-product {
            padding: 16px !important;
        }

        .lk-order-product-image {
            width: 64px !important;
            height: 64px !important;
            flex-basis: 64px !important;
        }

        .lk-order-actions {
            width: 100% !important;
            justify-content: flex-start !important;
        }

        .lk-order-actions .lk-btn,
        .lk-order-actions form {
            width: 100% !important;
        }

        .lk-order-actions form .lk-btn {
            width: 100% !important;
        }
    }

    html.dark .lk-order-card {
        background:
            radial-gradient(circle at 96% 4%, rgba(193, 151, 113, 0.08), transparent 28%),
            linear-gradient(180deg, #241A17 0%, #1E1714 100%) !important;

        border-color: #49342B !important;
        color: #FFF8F2 !important;
    }

    html.dark .lk-order-head,
    html.dark .lk-order-foot,
    html.dark .lk-order-products {
        background: #241A17 !important;
        border-color: #49342B !important;
    }

    html.dark .lk-order-product,
    html.dark .lk-order-notice {
        border-color: #49342B !important;
    }

    html.dark .lk-order-number,
    html.dark .lk-order-product-name,
    html.dark .lk-order-total {
        color: #FFF8F2 !important;
    }

    html.dark .lk-order-date,
    html.dark .lk-order-product-meta,
    html.dark .lk-order-payment {
        color: #C8B7AD !important;
    }

    html.dark .lk-order-product-image {
        background: linear-gradient(135deg, #2A1E1A, #382820) !important;
        border-color: #49342B !important;
        color: #C8B7AD !important;
    }

    html.dark .lk-order-product-price {
        color: #EBA99D !important;
    }

    html.dark .lk-order-actions .lk-btn-light {
        background: #2A1E1A !important;
        border-color: #60463A !important;
        color: #EBA99D !important;
    }

    html.dark .lk-order-actions .lk-btn-light:hover {
        background: #361B17 !important;
        border-color: #A85D50 !important;
        color: #FFFFFF !important;
    }

    html.dark .lk-order-notice.is-receive {
        background: #361B17 !important;
        color: #EBA99D !important;
    }

    html.dark .lk-order-notice.is-return {
        background: #3A2819 !important;
        color: #EBC18C !important;
    }
</style>
@endonce

<article
    class="lk-order-card"
    data-order-card="{{ $id }}"
>
    <header class="lk-order-head">
        <div class="lk-order-title-row">
            <strong class="lk-order-number">
                Order #{{ $id }}
            </strong>

            <span class="lk-order-date">
                Placed {{ data_get($order, 'placed_at') }}
            </span>
        </div>

        <span class="lk-order-status is-{{ $statusKey }}">
            {{ $statusLabel }}
        </span>
    </header>

    <div class="lk-order-products">
        @foreach($products as $product)
            @php
                $productImage = data_get($product, 'image')
                    ?? data_get($product, 'image_url');

                $productName = data_get($product, 'name', 'Product Name');

                $productVariant = data_get($product, 'variant', 'Standard');

                $productQuantity = data_get($product, 'quantity', 1);

                $productPrice = (float) data_get($product, 'price', 0);
            @endphp

            <div class="lk-order-product">
                <div class="lk-order-product-image">
                    @if($productImage)
                        <img
                            src="{{ $productImage }}"
                            alt="{{ $productName }}"
                            loading="lazy"
                            decoding="async"
                        >
                    @else
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path d="M4 5h16v14H4z"/>
                            <path d="m4 15 4-4 4 4 3-3 5 5"/>
                            <circle cx="15.5" cy="8.5" r="1.5"/>
                        </svg>
                    @endif
                </div>

                <div class="lk-order-product-copy">
                    <strong class="lk-order-product-name">
                        {{ $productName }}
                    </strong>

                    <p class="lk-order-product-meta">
                        {{ $productVariant }} · Qty {{ $productQuantity }}
                    </p>

                    <span class="lk-order-product-price">
                        ₱{{ number_format($productPrice, 2) }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    @if($status === 'to-receive')
        <div class="lk-order-notice is-receive">
            @if(data_get($order, 'delivered_at'))
                Delivered {{ data_get($order, 'delivered_at') }}.
                Auto-receive on {{ data_get($order, 'auto_receive_at') }} if no action is taken.
            @else
                Parcel is in transit. Track it for the latest rider update.
            @endif
        </div>
    @endif

    @if($status === 'returns')
        <div class="lk-order-notice is-return">
            <strong>Seller review:</strong>
            {{ data_get($order, 'case_status', 'Submitted for review') }}
            ·
            {{ data_get($order, 'case_note', 'The seller will review the evidence and respond to the request.') }}
        </div>
    @endif

    <footer class="lk-order-foot">
        <div class="lk-order-payment">
            {{ data_get($order, 'payment') }}
            ·
            <strong class="lk-order-total">
                ₱{{ number_format((float) data_get($order, 'total'), 2) }}
            </strong>
        </div>

        <div class="lk-order-actions">
            <a
                class="lk-btn lk-btn-light"
                href="{{ route('buyer.orders.show', ['id' => $id]) }}"
            >
                View Details
            </a>

            @if($status === 'to-pay')
                <button
                    type="button"
                    class="lk-btn lk-btn-light"
                    data-cancel-order
                    data-order-id="{{ $id }}"
                >
                    Cancel
                </button>

                <a
                    class="lk-btn lk-btn-red"
                    href="{{ route('buyer.orders.show', ['id' => $id]) }}"
                >
                    Pay Now
                </a>
            @elseif($status === 'to-receive')
                @if(data_get($order, 'delivered_at'))
                    <a
                        class="lk-btn lk-btn-light"
                        href="{{ route('buyer.orders.show', ['order' => $id]) }}"
                    >
                        Return / Refund
                    </a>
                    <form
                        method="POST"
                        action="{{ route('buyer.orders.received', ['id' => $id]) }}"
                    >
                        @csrf

                        <button
                            class="lk-btn lk-btn-red"
                            type="submit"
                        >
                            Order Received
                        </button>
                    </form>
                @else
                    <a
                        class="lk-btn lk-btn-red"
                        href="{{ route('buyer.orders.show', ['id' => $id]) }}"
                    >
                        Track Parcel
                    </a>
                @endif
            @elseif($status === 'completed')
                <a
                    class="lk-btn lk-btn-red"
                    href="{{ route('buyer.orders.show', ['order' => $id]) }}"
                >
                    Write Review
                </a>
            @elseif($status === 'returns')
                <a
                    class="lk-btn lk-btn-red"
                    href="{{ route('buyer.orders.show', ['id' => $id]) }}"
                >
                    View Case
                </a>
            @endif
        </div>
    </footer>
</article>
