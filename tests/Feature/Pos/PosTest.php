<?php

use App\Livewire\Pos;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Pos\Cart;
use Filament\Auth\Pages\Login;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function posScreen(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? cashier())->test(Pos::class);
}

it('requires login and an active account', function () {
    $this->get('/pos')->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs(cashier())->get('/pos')->assertOk()->assertSee('Scan barcode');
    $this->actingAs(cashier(['is_active' => false]))->get('/pos')->assertForbidden();
});

it('sends each role to its home', function () {
    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs(cashier())->get('/')->assertRedirect(route('pos'));
    $this->actingAs(admin())->get('/')->assertRedirect('/admin');
});

it('adds a scanned product and increases quantity on a second scan', function () {
    $product = Product::factory()->create(['barcode' => '4800016644801', 'selling_price' => '15.00', 'stock_quantity' => 10]);

    posScreen()
        ->set('barcode', '4800016644801')->call('scan')
        ->assertSet('barcode', '')
        ->assertSet("lines.p{$product->id}.quantity", 1)
        ->set('barcode', " 4800016644801\n")->call('scan')
        ->assertSet("lines.p{$product->id}.quantity", 2)
        ->assertSet("lines.p{$product->id}.unit_price_cents", 1500);
});

it('finds products by sku when scanning', function () {
    $product = Product::factory()->create(['barcode' => null, 'sku' => 'BEV-001', 'stock_quantity' => 10]);

    posScreen()->set('barcode', 'BEV-001')->call('scan')->assertSet("lines.p{$product->id}.quantity", 1);
});

it('offers manual entry when a barcode is unknown', function () {
    Product::factory()->inactive()->create(['barcode' => '999']);

    posScreen()
        ->set('barcode', '999')->call('scan')
        ->assertSet('notFoundCode', '999')
        ->assertSet('lines', [])
        ->assertSee('No product found for')
        ->call('openManualEntry')
        ->assertSet('showManualModal', true)
        ->assertSet('notFoundCode', null);
});

it('adds a manual item', function () {
    $component = posScreen()
        ->call('openManualEntry')
        ->set('manualName', 'Ice (plastic)')
        ->set('manualQuantity', '2')
        ->set('manualPrice', '5.50')
        ->call('addManualItem')
        ->assertSet('showManualModal', false);

    $line = collect($component->get('lines'))->sole();

    expect($line)->toMatchArray(['name' => 'Ice (plastic)', 'quantity' => 2, 'unit_price_cents' => 550, 'is_manual' => true, 'product_id' => null]);
});

it('validates manual items', function () {
    posScreen()
        ->call('openManualEntry')
        ->set('manualName', '')->set('manualQuantity', '0')->set('manualPrice', '-1')
        ->call('addManualItem')
        ->assertHasErrors(['manualName', 'manualQuantity', 'manualPrice'])
        ->assertSet('lines', []);
});

it('changes quantities and removes lines', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $key = "p{$product->id}";

    posScreen()
        ->call('addProduct', $product->id)
        ->call('increment', $key)
        ->call('increment', $key)
        ->assertSet("lines.{$key}.quantity", 3)
        ->call('decrement', $key)
        ->assertSet("lines.{$key}.quantity", 2)
        ->call('setQuantity', $key, '7')
        ->assertSet("lines.{$key}.quantity", 7)
        ->call('setQuantity', $key, '0')
        ->assertSet('lines', [])
        ->call('addProduct', $product->id)
        ->call('remove', $key)
        ->assertSet('lines', []);
});

it('blocks adding more than the stock on hand', function () {
    $product = Product::factory()->create(['name' => 'Last one', 'stock_quantity' => 1]);
    $key = "p{$product->id}";

    posScreen()
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertDispatched('pos-toast', message: 'Only 1 left of Last one.')
        ->assertSet("lines.{$key}.quantity", 1)
        ->call('setQuantity', $key, '5')
        ->assertSet("lines.{$key}.quantity", 1);
});

it('filters the grid by search and category', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $coke = Product::factory()->create(['name' => 'Coke Mismo']);
    $coke->categories()->attach($drinks);
    Product::factory()->create(['name' => 'Skyflakes']);
    Product::factory()->inactive()->create(['name' => 'Hidden product']);

    posScreen()
        ->assertSee('Coke Mismo')->assertSee('Skyflakes')->assertDontSee('Hidden product')
        ->set('search', 'sky')
        ->assertSee('Skyflakes')->assertDontSee('Coke Mismo')
        ->set('search', '')
        ->call('selectCategory', $drinks->id)
        ->assertSee('Coke Mismo')->assertDontSee('Skyflakes');
});

it('adds the single search result when Enter is pressed', function () {
    $product = Product::factory()->create(['name' => 'Bear Brand Swak', 'stock_quantity' => 5]);
    Product::factory()->create(['name' => 'Milo']);

    posScreen()->set('search', 'bear')->call('submitSearch')
        ->assertSet("lines.p{$product->id}.quantity", 1)
        ->assertSet('search', '');
});

it('refuses price edits without permission', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    posScreen()->call('addProduct', $product->id)->call('openPriceEditor', "p{$product->id}")->assertForbidden();
});

it('overrides a price with permission and leaves the product price alone', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $key = "p{$product->id}";

    posScreen(cashier(['can_override_price' => true]))
        ->call('addProduct', $product->id)
        ->call('openPriceEditor', $key)
        ->assertSet('editingPriceKey', $key)
        ->assertSet('newPrice', '25.00')
        ->set('newPrice', '20')
        ->call('savePrice')
        ->assertSet('editingPriceKey', null)
        ->assertSet("lines.{$key}.unit_price_cents", 2000)
        ->assertSet("lines.{$key}.original_price_cents", 2500)
        ->assertSet("lines.{$key}.price_overridden", true);

    expect($product->fresh()->selling_price)->toBe('25.00');
});

it('completes a sale, shows the change and starts a fresh cart', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '37.50', 'stock_quantity' => 10]);

    $component = posScreen($cashier)->call('addProduct', $product->id)->call('increment', "p{$product->id}");
    $tokenBefore = $component->get('checkoutToken');

    $component
        ->call('openPayment')
        ->set('cashReceived', '100')
        ->call('completeSale')
        ->assertHasNoErrors()
        ->assertSet('showPaymentModal', false)
        ->assertSet('lines', [])
        ->assertSet('cashReceived', '')
        ->assertDontSeeHtml('id="pos-cash"')
        ->assertSee('Sale complete')
        ->assertSee('₱25.00');

    $transaction = Transaction::query()->sole();

    expect($component->get('completedTransactionId'))->toBe($transaction->id)
        ->and($component->get('checkoutToken'))->not->toBe($tokenBefore)
        ->and($transaction->user_id)->toBe($cashier->id)
        ->and($transaction->total)->toBe('75.00')
        ->and($transaction->change_amount)->toBe('25.00')
        ->and($product->fresh()->stock_quantity)->toBe(8);

    $component->call('newSale')
        ->assertSet('completedTransactionId', null)
        ->assertDispatched('focus-barcode')
        ->assertDontSee('Sale complete');
});

it('does not create a second sale when Complete Sale is triggered twice', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);

    posScreen()
        ->call('addProduct', $product->id)
        ->set('cashReceived', '10')
        ->call('completeSale')
        ->set('cashReceived', '10')
        ->call('completeSale');

    expect(Transaction::count())->toBe(1)->and($product->fresh()->stock_quantity)->toBe(9);
});

it('keeps the cart when cash is short', function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 10]);

    posScreen()
        ->call('addProduct', $product->id)
        ->call('openPayment')
        ->set('cashReceived', '20')
        ->call('completeSale')
        ->assertDispatched('pos-toast')
        ->assertSet('showPaymentModal', true)
        ->assertSet("lines.p{$product->id}.quantity", 1)
        ->assertSet('completedTransactionId', null);

    expect(Transaction::count())->toBe(0);
});

it('refreshes cart prices when the catalog price changed before payment', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $component = posScreen()->call('addProduct', $product->id);

    $product->update(['selling_price' => '12.00']);

    $component->set('cashReceived', '100')->call('completeSale')
        ->assertDispatched('pos-toast')
        ->assertSet("lines.p{$product->id}.unit_price_cents", 1200);

    expect(Transaction::count())->toBe(0);
});

it('refuses a sale with a discount from a cashier who may not discount', function () {
    $product = Product::factory()->create(['selling_price' => '100.00', 'stock_quantity' => 10]);

    posScreen()->call('addProduct', $product->id)->set('discount', '50')->set('cashReceived', '100')->call('completeSale');

    expect(Transaction::count())->toBe(0);
});

it('applies a discount for a permitted cashier', function () {
    $product = Product::factory()->create(['selling_price' => '100.00', 'stock_quantity' => 10]);

    posScreen(cashier(['can_apply_discount' => true]))
        ->call('addProduct', $product->id)->set('discount', '10')->set('cashReceived', '100')->call('completeSale');

    expect(Transaction::query()->sole()->total)->toBe('90.00');
});

it('keeps the cart across a page reload', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['stock_quantity' => 10]);

    posScreen($cashier)->call('addProduct', $product->id);

    posScreen($cashier)->assertSet("lines.p{$product->id}.quantity", 1);
});

it('does not let the browser write the cart or the checkout token', function () {
    expect(fn () => posScreen()->set('lines', ['p1' => ['unit_price_cents' => 1]]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => posScreen()->set('checkoutToken', 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('lands cashiers on the pos and admins on the panel after logging in', function (string $role, string $expectedPath) {
    $user = $role === 'admin' ? admin() : cashier();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(url($expectedPath));

    $this->assertAuthenticatedAs($user);
})->with([
    'cashier' => ['cashier', '/pos'],
    'admin' => ['admin', '/admin'],
]);

it('links to the pos from the admin panel', function () {
    $this->actingAs(admin())->get('/admin')
        ->assertOk()
        ->assertSee('Open POS')
        ->assertSee(route('pos'), escape: false);
});

/**
 * Send one real /livewire/update request for a snapshot taken from a rendered /pos page,
 * so the route's persistent middleware (auth, can:access-pos) runs as in production.
 *
 * @param  array<string, mixed>  $snapshot
 * @param  list<mixed>  $params
 */
function posUpdateRequest(array $snapshot, string $method, array $params = []): TestResponse
{
    return test()->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            'updates' => [],
        ]],
    ], ['X-Livewire' => 'true']);
}

it('refuses a deactivated cashier on the next component update', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $html = $this->actingAs($cashier)->get('/pos')->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');

    posUpdateRequest($snapshot, 'addProduct', [$product->id])->assertOk();

    $cashier->update(['is_active' => false]);

    posUpdateRequest($snapshot, 'addProduct', [$product->id])->assertForbidden();
});

it('renders the scanner field focused and the payment controls', function () {
    $html = $this->actingAs(cashier())->get('/pos')->assertOk()->getContent();

    expect($html)
        ->toMatch('/<input\s+id="pos-barcode"[^>]*\sautofocus[\s>]/')
        ->toContain('wire:click="openPayment"')
        ->not->toContain('id="pos-cash"')
        ->not->toContain('id="pos-discount"');
});

it('opens the payment modal only for a cart with items', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    posScreen()
        ->call('openPayment')
        ->assertSet('showPaymentModal', false)
        ->call('addProduct', $product->id)
        ->assertDontSeeHtml('id="pos-cash"')
        ->call('openPayment')
        ->assertSet('showPaymentModal', true)
        ->assertSeeHtml('id="pos-cash"')
        ->assertSee('Cash received')
        ->assertSee('Complete Sale');
});

it('closes the payment modal without losing the cart or the amounts typed', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    posScreen()
        ->call('addProduct', $product->id)
        ->call('openPayment')
        ->set('cashReceived', '100')
        ->call('closePayment')
        ->assertSet('showPaymentModal', false)
        ->assertSet('cashReceived', '100')
        ->assertSet("lines.p{$product->id}.quantity", 1)
        ->assertDispatched('focus-barcode')
        ->assertDontSeeHtml('id="pos-cash"');
});

it('does not let the browser open the payment modal', function () {
    expect(fn () => posScreen()->set('showPaymentModal', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows the discount field and price buttons only to permitted users', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    posScreen()->call('addProduct', $product->id)->call('openPayment')
        ->assertDontSeeHtml('id="pos-discount"')
        ->assertDontSeeHtml('openPriceEditor');

    posScreen(cashier(['can_override_price' => true, 'can_apply_discount' => true]))->call('addProduct', $product->id)->call('openPayment')
        ->assertSeeHtml('id="pos-discount"')
        ->assertSeeHtml("openPriceEditor('p{$product->id}')");
});

it('returns focus to the barcode field after a scan and offers manual add for unknown codes', function () {
    Product::factory()->create(['barcode' => '111', 'stock_quantity' => 5]);

    posScreen()
        ->set('barcode', '111')->call('scan')
        ->assertDispatched('focus-barcode')
        ->set('barcode', '222')->call('scan')
        ->assertDispatched('focus-barcode')
        ->assertSee('Add as manual item');
});

it('caps repeated scans at the cart maximum quantity', function () {
    StoreSetting::current()->update(['allow_negative_stock' => true]);
    $product = Product::factory()->create(['name' => 'Candy', 'barcode' => '555', 'stock_quantity' => 1]);
    $key = "p{$product->id}";

    posScreen()
        ->set('barcode', '555')->call('scan')
        ->call('setQuantity', $key, (string) Cart::MAX_QUANTITY)
        ->assertSet("lines.{$key}.quantity", Cart::MAX_QUANTITY)
        ->set('barcode', '555')->call('scan')
        ->assertSet("lines.{$key}.quantity", Cart::MAX_QUANTITY)
        ->assertDispatched('pos-toast')
        ->call('addProduct', $product->id)
        ->call('increment', $key)
        ->assertSet("lines.{$key}.quantity", Cart::MAX_QUANTITY);
});

it('caps a typed quantity at the cart maximum without failing on huge numbers', function (string $typed) {
    StoreSetting::current()->update(['allow_negative_stock' => true]);
    $product = Product::factory()->create(['stock_quantity' => 1]);
    $key = "p{$product->id}";

    posScreen()
        ->call('addProduct', $product->id)
        ->call('setQuantity', $key, $typed)
        ->assertSet("lines.{$key}.quantity", Cart::MAX_QUANTITY);
})->with(['just above' => '10000', 'exponent' => '1e30', 'huge integer' => '99999999999999999999999']);

it('lets the cashier sell beyond stock when the store allows negative stock', function () {
    StoreSetting::current()->update(['allow_negative_stock' => true]);
    $product = Product::factory()->create(['stock_quantity' => 0]);

    posScreen()
        ->call('addProduct', $product->id)
        ->call('increment', "p{$product->id}")
        ->assertSet("lines.p{$product->id}.quantity", 2);
});

it('dims sold-out products and refuses to add them', function () {
    $product = Product::factory()->create(['name' => 'Sold out item', 'stock_quantity' => 0]);

    $component = posScreen();

    expect($component->html())->toMatch('/wire:click="addProduct\('.$product->id.'\)"\s+disabled/');

    $component->call('addProduct', $product->id)
        ->assertDispatched('pos-toast', message: 'Only 0 left of Sold out item.')
        ->assertSet('lines', []);
});

it('refuses a product that was deactivated after the grid was shown', function () {
    $product = Product::factory()->inactive()->create();

    posScreen()
        ->call('addProduct', $product->id)
        ->assertDispatched('pos-toast', message: 'That product is no longer available.')
        ->assertSet('lines', []);
});

it('rejects out-of-range money input instead of charging zero', function (string $amount) {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $key = "p{$product->id}";
    $component = posScreen(cashier(['can_override_price' => true, 'can_apply_discount' => true]))
        ->call('addProduct', $product->id);

    $component->call('openPriceEditor', $key)->set('newPrice', $amount)->call('savePrice')
        ->assertHasErrors('newPrice')
        ->assertSet("lines.{$key}.unit_price_cents", 1000)
        ->call('closePriceEditor');

    $component->call('openManualEntry')->set('manualName', 'Ice')->set('manualPrice', $amount)->call('addManualItem')
        ->assertHasErrors('manualPrice')
        ->call('closeManualEntry');

    $component->set('cashReceived', $amount)->call('completeSale')->assertHasErrors('cashReceived');
    $component->set('cashReceived', '100')->set('discount', $amount)->call('completeSale')->assertHasErrors('discount');

    expect($component->get('lines'))->toHaveCount(1)
        ->and(Transaction::count())->toBe(0);
})->with(['1e13' => '1e13', 'just above the maximum' => '10000000', 'negative' => '-5']);

it('rejects money input with more than two decimals and accepts one or two', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $key = "p{$product->id}";
    $component = posScreen(cashier(['can_override_price' => true, 'can_apply_discount' => true]))
        ->call('addProduct', $product->id);

    $component->call('openPriceEditor', $key)->set('newPrice', '12.345')->call('savePrice')
        ->assertHasErrors('newPrice')
        ->assertSet("lines.{$key}.unit_price_cents", 1000)
        ->set('newPrice', '12.3')->call('savePrice')
        ->assertHasNoErrors()
        ->assertSet("lines.{$key}.unit_price_cents", 1230)
        ->call('openPriceEditor', $key)->set('newPrice', '12.30')->call('savePrice')
        ->assertHasNoErrors()
        ->assertSet("lines.{$key}.unit_price_cents", 1230);

    $component->call('openManualEntry')->set('manualName', 'Ice')->set('manualPrice', '12.345')->call('addManualItem')
        ->assertHasErrors('manualPrice');
    expect($component->get('lines'))->toHaveCount(1);

    $component->set('manualPrice', '12.3')->call('addManualItem')->assertHasNoErrors();
    expect($component->get('lines'))->toHaveCount(2);

    $component->set('cashReceived', '100.005');
    expect($component->instance()->changeCents)->toBe(0)
        ->and($component->instance()->canComplete)->toBeFalse();
    $component->call('completeSale')->assertHasErrors('cashReceived');

    $component->set('cashReceived', '100')->set('discount', '1.005');
    expect($component->instance()->discountCents)->toBe(0)
        ->and($component->instance()->totalCents)->toBe(2460);
    $component->call('completeSale')->assertHasErrors('discount');

    expect(Transaction::count())->toBe(0);

    $component->set('cashReceived', '100.5')->set('discount', '1.50');
    expect($component->instance()->discountCents)->toBe(150)
        ->and($component->instance()->canComplete)->toBeTrue();
    $component->call('completeSale')->assertHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

it('leaves stock and movements untouched until a sale completes', function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 10]);
    $other = Product::factory()->create(['selling_price' => '20.00', 'stock_quantity' => 8]);
    $key = "p{$product->id}";

    $assertUntouched = function () use ($product, $other): void {
        expect($product->fresh()->stock_quantity)->toBe(10)
            ->and($other->fresh()->stock_quantity)->toBe(8)
            ->and(InventoryMovement::count())->toBe(0);
    };

    $component = posScreen(cashier(['can_override_price' => true]))->call('addProduct', $product->id);
    $assertUntouched();

    $component->call('increment', $key);
    $assertUntouched();

    $component->call('setQuantity', $key, '4');
    $assertUntouched();

    $component->call('openPriceEditor', $key)->set('newPrice', '45')->call('savePrice');
    $assertUntouched();

    $component->call('addProduct', $other->id)->call('remove', "p{$other->id}")->call('remove', $key);
    $assertUntouched();
});

it('leaves stock and movements untouched when checkout fails', function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 10]);

    $component = posScreen()
        ->call('addProduct', $product->id)
        ->set('cashReceived', '20')
        ->call('completeSale');

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);

    $product->update(['is_active' => false]);

    $component->set('cashReceived', '100')->call('completeSale');

    expect(Transaction::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);
});

it('treats amounts with commas as invalid in the live totals and on submit', function () {
    $product = Product::factory()->create(['selling_price' => '500.00', 'stock_quantity' => 5]);

    $component = posScreen(cashier(['can_apply_discount' => true]))
        ->call('addProduct', $product->id)
        ->set('cashReceived', '1,000');

    expect($component->instance()->changeCents)->toBe(0)
        ->and($component->instance()->canComplete)->toBeFalse();

    $component->call('completeSale')->assertHasErrors('cashReceived');

    $component->set('cashReceived', '1000')->set('discount', '1,00');

    expect($component->instance()->totalCents)->toBe(50000)
        ->and($component->instance()->changeCents)->toBe(50000);

    $component->call('completeSale')->assertHasErrors('discount');

    expect(Transaction::count())->toBe(0);
});

it('enables Complete Sale only once cash covers the total', function () {
    $product = Product::factory()->create(['selling_price' => '55.00', 'stock_quantity' => 5]);

    $component = posScreen()->call('addProduct', $product->id);
    expect($component->instance()->canComplete)->toBeFalse();

    $component->set('cashReceived', '50');
    expect($component->instance()->canComplete)->toBeFalse()
        ->and($component->instance()->changeCents)->toBe(0);

    $component->call('setCash', 100);
    expect($component->instance()->canComplete)->toBeTrue()
        ->and($component->instance()->changeCents)->toBe(4500);

    $component->call('exactCash')->assertSet('cashReceived', '55.00');
});

it('trims manual item names and limits them to 150 characters', function () {
    $component = posScreen()
        ->call('openManualEntry')
        ->set('manualName', '  '.str_repeat('a', 150).'  ')
        ->set('manualPrice', '5')
        ->call('addManualItem')
        ->assertHasNoErrors();

    expect(collect($component->get('lines'))->sole()['name'])->toBe(str_repeat('a', 150));

    $component->call('openManualEntry')
        ->set('manualName', str_repeat('a', 151))->set('manualPrice', '5')
        ->call('addManualItem')
        ->assertHasErrors(['manualName' => 'max']);

    $component->set('manualName', '   ')->call('addManualItem')->assertHasErrors(['manualName' => 'required']);

    expect($component->get('lines'))->toHaveCount(1);
});

it('limits manual quantities to whole numbers up to the cart maximum', function (string $quantity) {
    posScreen()
        ->call('openManualEntry')
        ->set('manualName', 'Ice')->set('manualQuantity', $quantity)->set('manualPrice', '5')
        ->call('addManualItem')
        ->assertHasErrors('manualQuantity')
        ->assertSet('lines', []);
})->with(['above maximum' => (string) (Cart::MAX_QUANTITY + 1), 'fraction' => '1.5', 'text' => 'two']);

it('refuses a tampered price save from a cashier without permission', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $key = "p{$product->id}";

    posScreen()
        ->call('addProduct', $product->id)
        ->set('newPrice', '1')
        ->call('savePrice')
        ->assertForbidden();

    expect(session("pos.lines.{$key}.unit_price_cents"))->toBe(1000)
        ->and(session("pos.lines.{$key}.price_overridden"))->toBeFalse();
});

it('does not let the browser mark a sale as completed', function () {
    expect(fn () => posScreen()->set('completedTransactionId', 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the checkout token across a page reload', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $token = posScreen($cashier)->call('addProduct', $product->id)->get('checkoutToken');

    expect($token)->not->toBe('')
        ->and(posScreen($cashier)->get('checkoutToken'))->toBe($token);
});

it('clears the cart and issues a new checkout token', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $component = posScreen()->call('addProduct', $product->id)->set('cashReceived', '100');
    $token = $component->get('checkoutToken');

    $component->call('clearCart')
        ->assertSet('lines', [])
        ->assertSet('cashReceived', '');

    expect($component->get('checkoutToken'))->not->toBe($token);
});

it('shows the completed sale only to the cashier who made it', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $component = posScreen()->call('addProduct', $product->id)->set('cashReceived', '20')->call('completeSale');

    expect($component->instance()->completedTransaction)->not->toBeNull();

    $this->actingAs(cashier());

    expect($component->instance()->completedTransaction())->toBeNull();
});

it('records a sale made in a second tab instead of returning the first tab\'s sale', function () {
    $cashier = cashier();
    $first = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $second = Product::factory()->create(['selling_price' => '99.00', 'stock_quantity' => 10]);

    $tabA = posScreen($cashier);
    $tabB = posScreen($cashier);

    $tabA->call('addProduct', $first->id)->set('cashReceived', '10')->call('completeSale')->assertHasNoErrors();

    $tabB->call('addProduct', $second->id)->set('cashReceived', '100')->call('completeSale')->assertHasNoErrors();

    $sales = Transaction::query()->orderBy('id')->get();

    expect($sales)->toHaveCount(2)
        ->and($sales[0]->total)->toBe('10.00')
        ->and($sales[1]->total)->toBe('99.00')
        ->and($tabB->get('completedTransactionId'))->toBe($sales[1]->id)
        ->and($first->fresh()->stock_quantity)->toBe(9)
        ->and($second->fresh()->stock_quantity)->toBe(9);
});

it('works on the one server-side cart from every tab', function () {
    $cashier = cashier();
    $first = Product::factory()->create(['stock_quantity' => 10]);
    $second = Product::factory()->create(['stock_quantity' => 10]);

    $tabA = posScreen($cashier);
    $tabB = posScreen($cashier);

    $tabA->call('addProduct', $first->id);
    $tabB->call('addProduct', $second->id)
        ->assertSet("lines.p{$first->id}.quantity", 1)
        ->assertSet("lines.p{$second->id}.quantity", 1)
        ->assertSet('checkoutToken', $tabA->get('checkoutToken'));
});

it('keeps Complete Sale disabled and explains why when the discount exceeds the subtotal', function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 5]);

    $component = posScreen(cashier(['can_apply_discount' => true]))
        ->call('addProduct', $product->id)
        ->set('cashReceived', '100')
        ->set('discount', '60');

    expect($component->instance()->canComplete)->toBeFalse();
    $component->assertSee('Discount cannot be more than the subtotal.');

    $component->set('discount', '50');

    expect($component->instance()->canComplete)->toBeTrue();
    $component->assertDontSee('Discount cannot be more than the subtotal.');
});

it('does not let the browser choose which line the price editor edits', function () {
    expect(fn () => posScreen()->set('editingPriceKey', "p1');alert(1);('"))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('renders line keys as escaped JavaScript arguments', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $key = "p{$product->id}";

    posScreen(cashier(['can_override_price' => true]))
        ->call('addProduct', $product->id)
        ->call('openPriceEditor', $key)
        ->assertSeeHtml('wire:click="resetPrice('.e(Js::from($key)).')"')
        ->assertSeeHtml('wire:click="remove('.e(Js::from($key)).')"');
});

it('lets a cashier undo a price override after the permission was revoked', function () {
    $cashier = cashier(['can_override_price' => true]);
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $key = "p{$product->id}";

    $component = posScreen($cashier)
        ->call('addProduct', $product->id)
        ->call('openPriceEditor', $key)->set('newPrice', '20')->call('savePrice')
        ->assertSet("lines.{$key}.price_overridden", true);

    $cashier->update(['can_override_price' => false]);

    $component->call('closePriceEditor')
        ->assertDontSeeHtml("openPriceEditor('{$key}')")
        ->assertSeeHtml("resetPrice('{$key}')")
        ->assertSee('Use regular price')
        ->call('resetPrice', $key)
        ->assertSet("lines.{$key}.unit_price_cents", 2500)
        ->assertSet("lines.{$key}.price_overridden", false)
        ->set('cashReceived', '25')
        ->call('completeSale')
        ->assertHasNoErrors();

    expect(Transaction::query()->sole()->total)->toBe('25.00');
});

it('gives the header controls touch-sized targets', function () {
    $html = $this->actingAs(admin())->get('/pos')->assertOk()->getContent();

    foreach (['Sales history', 'Admin', 'Log out'] as $label) {
        expect($html)->toMatch('/class="[^"]*\bmin-h-12\b[^"]*">'.$label.'</');
    }
});

it('reads the store settings once per request', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $component = posScreen();

    $settingsQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$settingsQueries): void {
        if (str_contains($query->sql, 'store_settings')) {
            $settingsQueries++;
        }
    });

    $component->call('addProduct', $product->id);

    expect($settingsQueries)->toBe(1);
});

it('does not pass off an old sale as a new one when the cart changed under a used token', function () {
    $cashier = cashier();
    $first = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $second = Product::factory()->create(['selling_price' => '99.00', 'stock_quantity' => 10]);

    $component = posScreen($cashier)->call('addProduct', $first->id);
    $usedToken = $component->get('checkoutToken');
    $component->set('cashReceived', '10')->call('completeSale')->call('newSale');
    $firstSale = Transaction::query()->sole();

    session()->put('pos.token', $usedToken);
    session()->put('pos.lines', cartOf([[$second, 1]])->toArray());

    $component->set('cashReceived', '100')->call('completeSale')
        ->assertDispatched('pos-toast', message: 'This sale was already recorded. Your cart has changed — press Complete Sale again.')
        ->assertSet('completedTransactionId', null)
        ->assertSet("lines.p{$second->id}.quantity", 1);

    expect($component->get('checkoutToken'))->not->toBe($usedToken)
        ->and(Transaction::count())->toBe(1);

    $component->call('completeSale')->assertHasNoErrors()->assertSet('lines', []);

    $secondSale = Transaction::query()->whereKeyNot($firstSale->id)->sole();

    expect($component->get('completedTransactionId'))->toBe($secondSale->id)
        ->and($secondSale->total)->toBe('99.00')
        ->and($second->fresh()->stock_quantity)->toBe(9)
        ->and($first->fresh()->stock_quantity)->toBe(9);
});

it('returns the recorded sale when the same cart is retried with its token', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);

    $component = posScreen($cashier)->call('addProduct', $product->id)->call('increment', "p{$product->id}");
    $usedToken = $component->get('checkoutToken');
    $lines = $component->get('lines');
    $component->set('cashReceived', '20')->call('completeSale')->call('newSale');
    $sale = Transaction::query()->sole();

    session()->put('pos.token', $usedToken);
    session()->put('pos.lines', $lines);

    $component->set('cashReceived', '20')->call('completeSale')
        ->assertSet('completedTransactionId', $sale->id)
        ->assertSet('lines', [])
        ->assertNotDispatched('pos-toast');

    expect(Transaction::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(8);
});

it('starts a new checkout token when the session has none', function () {
    $component = posScreen();
    $snapshotToken = $component->get('checkoutToken');

    session()->forget('pos.token');

    $component->call('selectCategory', null);

    expect($component->get('checkoutToken'))->not->toBe($snapshotToken)
        ->and($component->get('checkoutToken'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and(session('pos.token'))->toBe($component->get('checkoutToken'));
});
