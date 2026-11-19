<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * There is deliberately no delete action: users are never deleted.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
