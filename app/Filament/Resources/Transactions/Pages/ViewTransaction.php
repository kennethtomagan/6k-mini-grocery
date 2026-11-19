<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Relations the detail page shows, loaded up front so the item list runs no query per line.
     *
     * @var list<string>
     */
    private const DETAIL_RELATIONS = ['cashier', 'voidedBy', 'items.priceOverrideUser'];

    protected function getHeaderActions(): array
    {
        return [
            TransactionResource::receiptAction(),
            TransactionResource::voidAction()->after(fn (): Model => $this->reloadDetails()),
        ];
    }

    /**
     * ViewRecord only calls mutateFormDataBeforeFill() when the page has no infolist, so the relations are
     * eager-loaded when the record is resolved instead.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(self::DETAIL_RELATIONS);
    }

    /**
     * Re-reads the sale in place after a void attempt, so the status, the Void section and the action's
     * visibility reflect what is now stored.
     */
    private function reloadDetails(): Transaction
    {
        /** @var Transaction $record */
        $record = $this->getRecord();

        return $record->refresh()->loadMissing(self::DETAIL_RELATIONS);
    }
}
