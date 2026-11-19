<?php

use App\Filament\Resources\StoreSettings\Pages\CreateStoreSetting;
use App\Filament\Resources\StoreSettings\Pages\EditStoreSetting;
use App\Filament\Resources\StoreSettings\Pages\ListStoreSettings;
use App\Models\StoreSetting;
use Livewire\Livewire;

it('lets admins edit store settings', function () {
    $settings = StoreSetting::current();

    Livewire::actingAs(admin())
        ->test(EditStoreSetting::class, ['record' => $settings->getRouteKey()])
        ->fillForm([
            'store_name' => 'Aling Nena Store',
            'store_address' => 'Purok 6, Brgy. San Roque',
            'receipt_paper_width' => 80,
            'allow_negative_stock' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(StoreSetting::current()->store_name)->toBe('Aling Nena Store')
        ->and(StoreSetting::current()->receipt_paper_width)->toBe(80)
        ->and(StoreSetting::current()->allow_negative_stock)->toBeTrue()
        ->and(StoreSetting::count())->toBe(1);
});

it('keeps cashiers out of store settings', function () {
    $this->actingAs(cashier())->get('/admin/store-settings')->assertForbidden();
    $this->actingAs(admin())->get('/admin/store-settings')->assertOk();
});

it('forbids cashiers from the settings edit page', function () {
    $record = StoreSetting::current()->getRouteKey();

    $this->actingAs(cashier())->get("/admin/store-settings/{$record}/edit")->assertForbidden();

    Livewire::actingAs(cashier())
        ->test(EditStoreSetting::class, ['record' => $record])
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get('/admin/store-settings')->assertRedirect(route('filament.admin.auth.login'));
});

it('trims text fields', function () {
    Livewire::actingAs(admin())
        ->test(EditStoreSetting::class, ['record' => StoreSetting::current()->getRouteKey()])
        ->fillForm(['store_name' => '  Nena  ', 'store_phone' => ' 0917 '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(StoreSetting::current()->store_name)->toBe('Nena')
        ->and(StoreSetting::current()->store_phone)->toBe('0917');
});

it('validates lengths and paper width', function () {
    Livewire::actingAs(admin())
        ->test(EditStoreSetting::class, ['record' => StoreSetting::current()->getRouteKey()])
        ->fillForm([
            'store_name' => str_repeat('a', 101),
            'store_phone' => str_repeat('1', 51),
            'store_address' => str_repeat('a', 256),
            'receipt_footer' => str_repeat('a', 256),
            'receipt_paper_width' => 70,
        ])
        ->call('save')
        ->assertHasFormErrors(['store_name', 'store_phone', 'store_address', 'receipt_footer', 'receipt_paper_width']);

    Livewire::actingAs(admin())
        ->test(EditStoreSetting::class, ['record' => StoreSetting::current()->getRouteKey()])
        ->fillForm(['store_name' => ''])
        ->call('save')
        ->assertHasFormErrors(['store_name' => 'required']);
});

it('gives a validation error, not a 500, for tampered values', function () {
    Livewire::actingAs(admin())
        ->test(EditStoreSetting::class, ['record' => StoreSetting::current()->getRouteKey()])
        ->set('data.store_name', ['x'])
        ->set('data.store_phone', ['x'])
        ->set('data.receipt_paper_width', ['x'])
        ->set('data.allow_negative_stock', ['x'])
        ->call('save')
        ->assertHasFormErrors(['store_name', 'store_phone', 'receipt_paper_width', 'allow_negative_stock']);

    expect(StoreSetting::current()->store_name)->not->toBe('Array');
});

it('has no create or delete path', function () {
    expect(class_exists(CreateStoreSetting::class))->toBeFalse();
    $admin = admin();
    expect($admin->can('create', StoreSetting::class))->toBeFalse()
        ->and($admin->can('delete', StoreSetting::current()))->toBeFalse();

    Livewire::actingAs($admin)->test(ListStoreSettings::class)
        ->assertCanSeeTableRecords([StoreSetting::current()])
        ->assertTableActionDoesNotExist('delete');
});
