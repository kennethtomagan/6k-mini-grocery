<?php

namespace App\Providers\Filament;

use App\Http\Middleware\DropTamperedTableQuery;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandName('K6 Tindahan')
            ->darkMode(false)
            ->colors([
                'primary' => Color::Emerald,
                // Ink: a cool neutral, so surfaces, borders and text read as one family.
                'gray' => [
                    50 => 'oklch(0.979 0.004 248)',
                    100 => 'oklch(0.960 0.006 248)',
                    200 => 'oklch(0.922 0.010 250)',
                    300 => 'oklch(0.868 0.014 251)',
                    400 => 'oklch(0.668 0.021 253)',
                    500 => 'oklch(0.536 0.024 255)',
                    600 => 'oklch(0.452 0.024 256)',
                    700 => 'oklch(0.372 0.022 257)',
                    800 => 'oklch(0.282 0.020 258)',
                    900 => 'oklch(0.218 0.018 259)',
                    950 => 'oklch(0.165 0.016 260)',
                ],
                'info' => Color::Violet,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            // One request for the UI face and the Sora display face used by headings.
            ->font(
                'Instrument Sans',
                url: 'https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|sora:500,600&display=swap',
            )
            ->monoFont(
                'JetBrains Mono',
                url: 'https://fonts.bunny.net/css?family=jetbrains-mono:400,500&display=swap',
            )
            ->navigationGroups(['Sales', 'Catalog', 'Inventory', 'Settings'])
            ->navigationItems([
                NavigationItem::make('Open POS')
                    ->url(fn (): string => route('pos'))
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->sort(-1),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                DropTamperedTableQuery::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
