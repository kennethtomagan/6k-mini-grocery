<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Compares keys as strings, so '5' matches 5 but '5.9', '05' and ' 5' do not. BelongsTo::is() is avoided because it also compares connection names and so rejects unsaved models.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        return $user->isAdmin()
            || ($transaction->user_id !== null
                && $user->getKey() !== null
                && (string) $transaction->user_id === (string) $user->getKey());
    }

    /**
     * An inactive admin is refused too, so a stale session or a direct service call cannot void a sale.
     */
    public function void(User $user, Transaction $transaction): bool
    {
        return (bool) $user->is_active && $user->isAdmin() && ! $transaction->isVoided();
    }

    /**
     * Sales are created only by CheckoutService.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
