<?php

use App\Http\Controllers\ReceiptController;
use App\Livewire\Pos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): RedirectResponse {
    $user = auth()->user();

    if ($user === null) {
        return redirect()->route('filament.admin.auth.login');
    }

    return redirect()->to($user->isAdmin() ? '/admin' : route('pos'));
});

Route::middleware(['auth', 'can:access-pos'])->group(function (): void {
    Route::get('/pos', Pos::class)->name('pos');
    Route::get('/transactions/{transaction}/receipt', ReceiptController::class)->name('receipt');
});
