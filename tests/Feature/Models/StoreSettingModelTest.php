<?php

use App\Models\StoreSetting;

it('recreates exactly one default row when the table is empty', function () {
    StoreSetting::query()->delete();

    $settings = StoreSetting::current();

    expect(StoreSetting::count())->toBe(1)
        ->and($settings->store_name)->toBe('My Sari-Sari Store')
        ->and($settings->receipt_footer)->toBe('Salamat po! Balik kayo.')
        ->and($settings->receipt_paper_width)->toBe(58)
        ->and($settings->allow_negative_stock)->toBeFalse();
});

it('keeps a single row when current is called repeatedly', function () {
    StoreSetting::query()->delete();

    StoreSetting::current();
    StoreSetting::current();

    expect(StoreSetting::count())->toBe(1);
});
