@foreach($products as $product)
    <x-buyer.product-card :product="$product" catalog-listing />
@endforeach
