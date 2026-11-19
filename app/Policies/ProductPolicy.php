<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends AdminOnlyPolicy
{
    public function adjustStock(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }
}
