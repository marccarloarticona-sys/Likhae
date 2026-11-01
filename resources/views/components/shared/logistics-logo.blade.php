@props(['label' => 'LIKHAE Logistics'])

<span
    {{ $attributes->class(['sl-logistics-logo']) }}
    role="img"
    aria-label="{{ $label }}"
>
    <img
        class="sl-logistics-logo__image"
        src="{{ asset('images/logisticlogolight-transparent.png') }}"
        data-logistics-logo-light="{{ asset('images/logisticlogolight-transparent.png') }}"
        data-logistics-logo-dark="{{ asset('images/logisticlogodark-transparent.png') }}"
        width="300"
        height="100"
        alt=""
        aria-hidden="true"
        decoding="async"
    >
</span>

@once
    <script>
        (() => {
            const syncLogisticsLogos = () => {
                const darkMode = document.documentElement.classList.contains('dark');
                document.querySelectorAll('.sl-logistics-logo__image').forEach((image) => {
                    const source = darkMode ? image.dataset.logisticsLogoDark : image.dataset.logisticsLogoLight;
                    if (source && image.getAttribute('src') !== source) image.setAttribute('src', source);
                });
            };

            syncLogisticsLogos();
            document.addEventListener('DOMContentLoaded', syncLogisticsLogos, { once: true });
            new MutationObserver(syncLogisticsLogos).observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class'],
            });
        })();
    </script>
@endonce
