<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After a Filament login, cashiers go straight to the POS and admins to the panel.
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->to($request->user()->isAdmin() ? '/admin' : route('pos'));
    }
}
