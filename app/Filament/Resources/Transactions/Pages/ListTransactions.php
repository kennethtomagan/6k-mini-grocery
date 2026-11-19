<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Filter state comes from the URL and from Livewire requests and is never validated. Every filter on this table
 * takes single values, so an array where a value belongs, or a bare value where a filter's state belongs, is dropped
 * before Filament uses it; otherwise its indicator, date cast or state accessor fails with a 500.
 * Only public Livewire hooks are used, so Filament's own handling of valid filter state is untouched.
 */
class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    public function mount(): void
    {
        $this->tableFilters = self::withSingleValuedFilters($this->tableFilters);

        parent::mount();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'tableFilters') || str_starts_with($property, 'tableDeferredFilters')) {
            $this->tableFilters = self::withSingleValuedFilters($this->tableFilters);
            $this->tableDeferredFilters = self::withSingleValuedFilters($this->tableDeferredFilters);
        }
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, mixed>|null
     */
    private static function withSingleValuedFilters(?array $filters): ?array
    {
        if ($filters === null) {
            return null;
        }

        foreach ($filters as $name => $state) {
            if (! is_array($state)) {
                $filters[$name] = [];

                continue;
            }

            foreach ($state as $field => $value) {
                if (is_array($value) || is_object($value)) {
                    $filters[$name][$field] = null;
                }
            }
        }

        return $filters;
    }
}
