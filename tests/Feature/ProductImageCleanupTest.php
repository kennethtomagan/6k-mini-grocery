<?php

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function productWithImage(string $path): Product
{
    Storage::disk('public')->put($path, 'image');

    return Product::factory()->create(['image_path' => $path]);
}

it('deletes the old file when the image is replaced', function () {
    $product = productWithImage('products/old.png');
    Storage::disk('public')->put('products/new.png', 'image');

    $product->update(['image_path' => 'products/new.png']);

    Storage::disk('public')->assertMissing('products/old.png');
    Storage::disk('public')->assertExists('products/new.png');
});

it('deletes the old file when the image is cleared', function () {
    $product = productWithImage('products/old.png');

    $product->update(['image_path' => null]);

    Storage::disk('public')->assertMissing('products/old.png');
});

it('keeps the file when an edit does not touch the image', function () {
    $product = productWithImage('products/old.png');

    $product->update(['name' => 'Renamed', 'image_path' => 'products/old.png']);

    Storage::disk('public')->assertExists('products/old.png');
});

it('keeps the file when the product is soft-deleted', function () {
    $product = productWithImage('products/old.png');

    $product->delete();

    Storage::disk('public')->assertExists('products/old.png');
});

it('keeps a replaced file that another product still references', function () {
    $product = productWithImage('products/shared.png');
    Product::factory()->create(['image_path' => 'products/shared.png']);

    $product->update(['image_path' => null]);

    Storage::disk('public')->assertExists('products/shared.png');
});

it('keeps a replaced file that a soft-deleted product still references', function () {
    $product = productWithImage('products/shared.png');
    Product::factory()->create(['image_path' => 'products/shared.png'])->delete();

    $product->update(['image_path' => null]);

    Storage::disk('public')->assertExists('products/shared.png');
});

it('does nothing when a product without an image gains one', function () {
    $product = Product::factory()->create();
    Storage::disk('public')->put('products/new.png', 'image');

    $product->update(['image_path' => 'products/new.png']);

    Storage::disk('public')->assertExists('products/new.png');
});

it('does not fail the save when the old path cannot be deleted', function () {
    $product = Product::factory()->create(['image_path' => '../../escaped.png']);

    $product->update(['image_path' => null]);

    expect($product->fresh()->image_path)->toBeNull();
});

it('only deletes files inside the products directory', function () {
    $product = productWithImage('other/keep.png');

    $product->update(['image_path' => null]);

    Storage::disk('public')->assertExists('other/keep.png');
});
