<?php

namespace App\Policies;

use App\Models\TransactionItem;
use App\Models\User;

/**
 * Transaction items are written only by CheckoutService and never changed afterwards.
 */
class TransactionItemPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function view(User $user, TransactionItem $item): bool
    {
        return $item->transaction !== null
            && app(TransactionPolicy::class)->view($user, $item->transaction);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TransactionItem $item): bool
    {
        return false;
    }

    public function delete(User $user, TransactionItem $item): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, TransactionItem $item): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, TransactionItem $item): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, TransactionItem $item): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
