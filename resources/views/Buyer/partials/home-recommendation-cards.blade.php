@foreach ($recommendedProducts as $product)
    <x-buyer.product-card :product="$product" home />
@endforeach
