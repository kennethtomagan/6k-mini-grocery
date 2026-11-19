<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class StoreSettingPolicy extends AdminOnlyPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * There is exactly one settings row, so it can never be copied.
     */
    public function replicate(User $user, Model $model): bool
    {
        return false;
    }
}
