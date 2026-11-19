<?php

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('shows the catalog to admins only', function () {
    $this->actingAs(admin())->get('/admin/products')->assertOk();
    $this->actingAs(admin())->get('/admin/categories')->assertOk();
    $this->actingAs(cashier())->get('/admin/products')->assertForbidden();
    $this->actingAs(cashier())->get('/admin/categories')->assertForbidden();
});

it('creates a product with several categories', function () {
    $categories = Category::factory()->count(2)->create();

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm([
            'name' => 'Coke 1.5L',
            'barcode' => '4801981118502',
            'selling_price' => 75,
            'cost_price' => 68.5,
            'low_stock_threshold' => 6,
            'is_active' => true,
            'categories' => $categories->pluck('id')->all(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('barcode', '4801981118502')->sole();

    expect($product->name)->toBe('Coke 1.5L')
        ->and($product->selling_price)->toBe('75.00')
        ->and($product->cost_price)->toBe('68.50')
        ->and($product->stock_quantity)->toBe(0)
        ->and($product->categories)->toHaveCount(2);
});

it('creates a product with no barcode, sku or categories', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Ice candy', 'selling_price' => 5, 'low_stock_threshold' => 0, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('name', 'Ice candy')->sole();

    expect($product->barcode)->toBeNull()
        ->and($product->sku)->toBeNull()
        ->and($product->categories)->toHaveCount(0);
});

it('rejects a duplicate barcode with a form error', function () {
    Product::factory()->create(['barcode' => '4800016644801']);

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Copy', 'barcode' => '4800016644801', 'selling_price' => 10, 'low_stock_threshold' => 0])
        ->call('create')
        ->assertHasFormErrors(['barcode']);
});

it('updates a product without touching its stock', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 12]);

    Livewire::actingAs(admin())
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Renamed', 'selling_price' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Renamed')
        ->and($product->fresh()->selling_price)->toBe('12.50')
        ->and($product->fresh()->stock_quantity)->toBe(12);
});

it('searches the product table by barcode and filters low stock', function () {
    $low = Product::factory()->create(['barcode' => '1111111111116', 'stock_quantity' => 1, 'low_stock_threshold' => 5]);
    $fine = Product::factory()->create(['barcode' => '2222222222222', 'stock_quantity' => 50]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->searchTable('1111111111116')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$fine])
        ->searchTable('')
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$fine]);
});

it('soft-deletes products', function () {
    $product = Product::factory()->create();

    $product->delete();

    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('creates a category from the manage page', function () {
    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction('create', ['name' => 'Drinks', 'description' => 'Softdrinks and juice'])
        ->assertHasNoActionErrors();

    expect(Category::query()->where('name', 'Drinks')->exists())->toBeTrue();
});

it('rejects an untrimmed duplicate barcode with a form error', function () {
    Product::factory()->create(['barcode' => '4800016644801']);

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Copy', 'barcode' => ' 4800016644801 ', 'selling_price' => 10, 'low_stock_threshold' => 0])
        ->call('create')
        ->assertHasFormErrors(['barcode']);
});

it('rejects an untrimmed duplicate sku with a form error', function () {
    Product::factory()->create(['sku' => 'SKU-1']);

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Copy', 'sku' => ' SKU-1 ', 'selling_price' => 10, 'low_stock_threshold' => 0])
        ->call('create')
        ->assertHasFormErrors(['sku']);
});

it('rejects a tampered non-string barcode and sku with validation errors', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Bad', 'selling_price' => 10, 'low_stock_threshold' => 0])
        ->set('data.barcode', ['x'])
        ->set('data.sku', ['y'])
        ->call('create')
        ->assertHasFormErrors(['barcode', 'sku']);
});

it('has no editable stock field on the product form', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->assertFormFieldDoesNotExist('stock_quantity');
});

it('restores a soft-deleted product from the edit page', function () {
    $product = Product::factory()->create();
    $product->delete();

    Livewire::actingAs(admin())
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('restore');

    expect($product->fresh()->trashed())->toBeFalse();
});

it('keeps products when a category is deleted', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create();
    $product->categories()->attach($category);

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction(TestAction::make('delete')->table($category));

    expect(Category::query()->find($category->id))->toBeNull()
        ->and($product->fresh())->not->toBeNull();
});

it('rejects an svg upload as the product image', function () {
    Storage::fake('public');

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm([
            'name' => 'Svg', 'selling_price' => 10, 'low_stock_threshold' => 0,
            'image_path' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])
        ->call('create')
        ->assertHasFormErrors(['image_path']);
});

it('stores a jpg upload under products on the public disk', function () {
    Storage::fake('public');

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm([
            'name' => 'Photo', 'selling_price' => 10, 'low_stock_threshold' => 0,
            'image_path' => UploadedFile::fake()->image('a.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $path = Product::query()->where('name', 'Photo')->sole()->image_path;

    expect($path)->toStartWith('products/');
    Storage::disk('public')->assertExists($path);
});

it('does not save a tampered image path', function () {
    Storage::fake('public');

    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Tamper', 'selling_price' => 10, 'low_stock_threshold' => 0])
        ->set('data.image_path', ['k' => 'secrets/evil.php'])
        ->call('create')
        ->assertHasFormErrors(['image_path']);

    expect(Product::query()->where('name', 'Tamper')->exists())->toBeFalse();
});

it('keeps the existing image when an edit does not touch it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/mine.jpg', 'image');
    $product = Product::factory()->create(['image_path' => 'products/mine.jpg']);

    Livewire::actingAs(admin())
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Renamed product'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Renamed product')
        ->and($product->fresh()->image_path)->toBe('products/mine.jpg');
});

it('caps the low-stock threshold', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Big', 'selling_price' => 10, 'low_stock_threshold' => 100001])
        ->call('create')
        ->assertHasFormErrors(['low_stock_threshold']);
});

it('rejects prices with more than two decimals', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Precise', 'selling_price' => '12.345', 'cost_price' => '1.234', 'low_stock_threshold' => 0])
        ->call('create')
        ->assertHasFormErrors(['selling_price', 'cost_price']);
});

it('rejects overlong descriptions', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Long', 'selling_price' => 1, 'low_stock_threshold' => 0, 'description' => str_repeat('a', 5001)])
        ->call('create')
        ->assertHasFormErrors(['description']);

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction('create', ['name' => 'Long', 'description' => str_repeat('a', 5001)])
        ->assertHasActionErrors(['description']);
});

it('rejects an untrimmed duplicate category name and trims a new one', function () {
    Category::factory()->create(['name' => 'Drinks']);

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction('create', ['name' => ' Drinks '])
        ->assertHasActionErrors(['name']);

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction('create', ['name' => ' Snacks '])
        ->assertHasNoActionErrors();

    expect(Category::query()->where('name', 'Snacks')->exists())->toBeTrue();
});

it('edits a category and rejects a duplicate name', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $snacks = Category::factory()->create(['name' => 'Snacks']);

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->table($snacks), ['name' => 'Candy'])
        ->assertHasNoActionErrors();

    expect($snacks->fresh()->name)->toBe('Candy');

    Livewire::actingAs(admin())
        ->test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->table($snacks), ['name' => 'Drinks'])
        ->assertHasActionErrors(['name']);
});

it('soft-deletes a product from the table and from the edit page', function () {
    $fromTable = Product::factory()->create();
    $fromPage = Product::factory()->create();

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('delete')->table($fromTable));

    Livewire::actingAs(admin())
        ->test(EditProduct::class, ['record' => $fromPage->getRouteKey()])
        ->callAction('delete');

    $this->assertSoftDeleted($fromTable);
    $this->assertSoftDeleted($fromPage);
});

it('searches the product table by name and sku', function () {
    $cola = Product::factory()->create(['name' => 'Zesty Cola', 'sku' => 'SKU-AAA']);
    $other = Product::factory()->create(['name' => 'Plain Bread', 'sku' => 'SKU-BBB']);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->searchTable('Zesty')
        ->assertCanSeeTableRecords([$cola])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('SKU-BBB')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$cola]);
});

it('filters the product table by category and active state', function () {
    $category = Category::factory()->create();
    $inCategory = Product::factory()->create();
    $inCategory->categories()->attach($category);
    $uncategorised = Product::factory()->create();
    $inactive = Product::factory()->inactive()->create();

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->filterTable('categories', [$category->id])
        ->assertCanSeeTableRecords([$inCategory])
        ->assertCanNotSeeTableRecords([$uncategorised, $inactive])
        ->removeTableFilters()
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$inCategory, $uncategorised]);
});

it('hides trashed products by default and shows them with the trashed filter', function () {
    $live = Product::factory()->create();
    $trashed = Product::factory()->create();
    $trashed->delete();

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$live, $trashed])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$live]);
});

it('colours the stock badge danger at the threshold and success above it', function () {
    $atThreshold = Product::factory()->create(['stock_quantity' => 5, 'low_stock_threshold' => 5]);
    $above = Product::factory()->create(['stock_quantity' => 6, 'low_stock_threshold' => 5]);

    $column = Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->instance()
        ->getTable()
        ->getColumn('stock_quantity');

    expect($column->record($atThreshold)->getColor(null))->toBe('danger')
        ->and($column->record($above)->getColor(null))->toBe('success');
});

it('forbids cashiers from every catalog page', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(cashier())->test(ListProducts::class)->assertForbidden();
    Livewire::actingAs(cashier())->test(CreateProduct::class)->assertForbidden();
    Livewire::actingAs(cashier())->test(EditProduct::class, ['record' => $product->getRouteKey()])->assertForbidden();
    Livewire::actingAs(cashier())->test(ManageCategories::class)->assertForbidden();
});

it('stops the barcode field submitting the form on enter', function () {
    $html = Livewire::actingAs(admin())->test(CreateProduct::class)->html();

    expect($html)->toMatch('/<input[^>]*id="form\.barcode"[^>]*x-on:keydown\.enter\.prevent/');
});
