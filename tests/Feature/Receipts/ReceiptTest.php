<?php

use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Livewire\Pos;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    StoreSetting::current()->update([
        'store_name' => 'Aling Nena Store',
        'store_address' => 'Purok 6, Brgy. San Roque',
        'receipt_footer' => 'Salamat po!',
        'receipt_paper_width' => 80,
    ]);
    $this->cashier = cashier(['name' => 'Ana Reyes']);
    $this->product = Product::factory()->create(['name' => 'Coke Mismo', 'selling_price' => '20.00', 'stock_quantity' => 10]);
    $this->sale = checkout($this->cashier, cartOf([[$this->product, 2]]), cashCents: 5000);
});

it('renders a receipt with store, items and payment', function () {
    $this->actingAs($this->cashier)->get(route('receipt', $this->sale))
        ->assertOk()
        ->assertSee('Aling Nena Store')
        ->assertSee('Purok 6, Brgy. San Roque')
        ->assertSee($this->sale->reference_number)
        ->assertSee('Ana Reyes')
        ->assertSee('Coke Mismo')
        ->assertSee('2 x 20.00')
        ->assertSee('40.00')
        ->assertSee('50.00')
        ->assertSee('10.00')
        ->assertSee('Salamat po!')
        ->assertSee('size: 80mm auto', false)
        ->assertDontSee('window.print', false)
        ->assertDontSee('VOIDED');
});

it('uses the 58 mm layout and auto-prints when asked', function () {
    StoreSetting::current()->update(['receipt_paper_width' => 58]);

    $this->actingAs($this->cashier)->get(route('receipt', ['transaction' => $this->sale, 'print' => 1]))
        ->assertOk()
        ->assertSee('size: 58mm auto', false)
        ->assertSee('width: 48mm', false)
        ->assertSee('font-size: 10px', false)
        ->assertSee('window.print', false);
});

it('uses the 80 mm printable width and font size', function () {
    $this->actingAs($this->cashier)->get(route('receipt', $this->sale))
        ->assertSee('width: 72mm', false)
        ->assertSee('font-size: 12px', false);
});

it('marks voided sales', function () {
    app(TransactionService::class)->void($this->sale, admin(), 'Test void');

    $this->actingAs($this->cashier)->get(route('receipt', $this->sale))->assertSee('VOIDED');
});

it('protects receipts', function () {
    $this->get(route('receipt', $this->sale))->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs(cashier())->get(route('receipt', $this->sale))->assertForbidden();
    $this->actingAs(admin())->get(route('receipt', $this->sale))->assertOk();
});

it('refuses an inactive user their own receipt', function () {
    $this->cashier->update(['is_active' => false]);

    $this->actingAs($this->cashier)->get(route('receipt', $this->sale))->assertForbidden();
});

it('refuses an inactive admin a receipt', function () {
    $this->actingAs(admin(['is_active' => false]))->get(route('receipt', $this->sale))->assertForbidden();
});

it('escapes store text and item names', function () {
    StoreSetting::current()->update([
        'store_name' => '<script>alert(1)</script>',
        'store_address' => '<b>addr</b>',
        'store_phone' => '<i>ph</i>',
        'receipt_footer' => '<script>alert(2)</script>',
    ]);
    $evil = Product::factory()->create(['name' => '<img src=x onerror=1>', 'selling_price' => '5.00', 'stock_quantity' => 5]);
    $sale = checkout($this->cashier, cartOf([[$evil, 1]]), cashCents: 500);

    $this->actingAs($this->cashier)->get(route('receipt', $sale))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<script>alert(2)</script>', false)
        ->assertDontSee('<b>addr</b>', false)
        ->assertDontSee('<i>ph</i>', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('does not query per item', function () {
    $this->actingAs($this->cashier);

    $receiptQueryCount = function (int $lineCount): int {
        $products = Product::factory()->count($lineCount)->create(['selling_price' => '5.00', 'stock_quantity' => 50]);
        $sale = checkout($this->cashier, cartOf($products->map(fn (Product $product): array => [$product, 1])->all()), cashCents: 5000);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('receipt', $sale))->assertOk();

        return count(DB::getQueryLog());
    };

    expect($receiptQueryCount(8))->toBe($receiptQueryCount(1));
});

it('offers print buttons on the transaction list, detail page and POS overlay', function () {
    $admin = admin();
    $url = route('receipt', ['transaction' => $this->sale, 'print' => 1]);

    Livewire\Livewire::actingAs($admin)->test(ListTransactions::class)
        ->assertTableActionVisible('receipt', $this->sale)
        ->assertTableActionHasUrl('receipt', $url, $this->sale);

    Livewire\Livewire::actingAs($admin)->test(ViewTransaction::class, ['record' => $this->sale->getRouteKey()])
        ->assertActionVisible('receipt')
        ->assertActionHasUrl('receipt', $url);

    $component = Livewire\Livewire::actingAs($this->cashier)->test(Pos::class)
        ->call('addProduct', $this->product->id)
        ->set('cashReceived', '100')
        ->call('completeSale')
        ->assertSee('Sale complete')
        ->assertSee('Print receipt');

    $newSale = Transaction::query()->latest('id')->first();
    $component->assertSee(route('receipt', ['transaction' => $newSale->id, 'print' => 1]), false);
});
