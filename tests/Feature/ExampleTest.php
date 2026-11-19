<?php

use Filament\Widgets\FilamentInfoWidget;

it('renders the admin login page with the store brand', function () {
    config(['app.name' => 'Laravel']);

    $this->get('/admin/login')->assertOk()->assertSee('K6 Tindahan');
});

it('brands the admin panel for the store', function () {
    config(['app.name' => 'Laravel']);

    $panel = filament()->getPanel('admin');

    expect($panel->getBrandName())->toBe('K6 Tindahan')
        ->and($panel->hasDarkMode())->toBeFalse()
        ->and($panel->getNavigationGroups())->toBe(['Sales', 'Catalog', 'Inventory', 'Settings'])
        ->and($panel->getWidgets())->not->toContain(FilamentInfoWidget::class);
});
