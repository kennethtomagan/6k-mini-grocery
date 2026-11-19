<?php

use App\Models\Product;

dataset('adminListUrls', [
    'products' => '/admin/products',
    'categories' => '/admin/categories',
    'users' => '/admin/users',
    'inventory movements' => '/admin/inventory-movements',
    'transactions' => '/admin/transactions',
]);

dataset('tamperedQueries', [
    'filters as text' => 'filters=x',
    'filters as empty' => 'filters=',
    'search as array' => 'search[]=x',
    'sort as array' => 'sort[]=x',
    'tab as array' => 'tab[]=x',
    'grouping as array' => 'grouping[]=x',
    'reordering as array' => 'reordering[]=x',
    'search as JSON list' => 'search=[1]',
    'search as JSON object' => 'search={"a":1}',
    'sort as JSON list' => 'sort=["x"]',
    'grouping as JSON list' => 'grouping=[1]',
    'tab as JSON object' => 'tab={"a":1}',
    'reordering as JSON list' => 'reordering=[1]',
]);

it('opens every admin list with a tampered table query parameter', function (string $url, string $query) {
    $this->actingAs(admin())->get("{$url}?{$query}")->assertOk();
})->with('adminListUrls')->with('tamperedQueries');

it('opens the sales reports with a tampered filters parameter', function () {
    $this->actingAs(admin())->get('/admin/sales-reports?filters=x')->assertOk();
});

it('still applies a search from the URL on the products list', function () {
    Product::factory()->create(['name' => 'Alpha Cola']);
    Product::factory()->create(['name' => 'Bravo Chips']);

    $this->actingAs(admin())->get('/admin/products?search=Alpha')
        ->assertOk()
        ->assertSee('Alpha Cola')
        ->assertDontSee('Bravo Chips');
});

it('still applies a sort from the URL on the products list', function () {
    Product::factory()->create(['name' => 'Alpha Cola']);
    Product::factory()->create(['name' => 'Bravo Chips']);

    $this->actingAs(admin())->get('/admin/products?sort=name:desc')
        ->assertOk()
        ->assertSeeInOrder(['Bravo Chips', 'Alpha Cola']);
});

it('still applies a filter from the URL on the products list', function () {
    Product::factory()->create(['name' => 'Alpha Cola', 'is_active' => true]);
    Product::factory()->inactive()->create(['name' => 'Bravo Chips']);

    $this->actingAs(admin())->get('/admin/products?filters[is_active][value]=0')
        ->assertOk()
        ->assertSee('Bravo Chips')
        ->assertDontSee('Alpha Cola');
});

it('opens the product edit page with a tampered relation parameter', function (string $query) {
    $product = Product::factory()->create();

    $this->actingAs(admin())->get("/admin/products/{$product->id}/edit?{$query}")->assertOk();
})->with([
    'array' => 'relation[]=x',
    'JSON list' => 'relation=[1]',
]);

it('still opens a search that merely looks like JSON text', function () {
    Product::factory()->create(['name' => 'Alpha Cola']);

    $this->actingAs(admin())->get('/admin/products?search=Alpha')->assertOk()->assertSee('Alpha Cola');
});
