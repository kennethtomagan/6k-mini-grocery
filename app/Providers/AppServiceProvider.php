<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);

        Gate::define('access-pos', fn (User $user): bool => (bool) $user->is_active);

        Gate::define('override-price', fn (User $user): bool => $user->is_active
            && ($user->isAdmin() || $user->can_override_price));

        Gate::define('apply-discount', fn (User $user): bool => $user->is_active
            && ($user->isAdmin() || $user->can_apply_discount));

        Gate::define('view-reports', fn (User $user): bool => $user->is_active && $user->isAdmin());
    }
}
