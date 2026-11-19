<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\StockMovementType;
use App\Filament\Resources\Products\ProductResource;
use App\Services\InventoryService;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    private int $initialStock = 0;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->initialStock = (int) ($data['initial_stock'] ?? 0);
        unset($data['initial_stock']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->initialStock > 0) {
            app(InventoryService::class)->adjust(
                $this->record,
                $this->initialStock,
                StockMovementType::Initial,
                auth()->user(),
                'Opening stock',
            );
        }
    }
}
