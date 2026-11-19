<?php

namespace App\Filament\Resources\Transactions;

use App\Enums\TransactionStatus;
use App\Exceptions\TransactionAlreadyVoidedException;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Models\Transaction;
use App\Services\TransactionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use UnitEnum;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference_number';

    /**
     * Cashiers only ever see their own sales (decision D3). Applies to the list, search, global search and record URLs,
     * so another cashier's sale is a 404.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['cashier']);
        $user = auth()->user();

        return $user?->isAdmin() ? $query : $query->where('user_id', $user?->getKey());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_number')->label('Transaction #')->searchable()->copyable()->weight('bold'),
                TextColumn::make('created_at')->label('Date')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('cashier.name')->label('Cashier'),
                TextColumn::make('item_count')->label('Items')->numeric(),
                TextColumn::make('total')->money('PHP')->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyDateRange($query, $data)),
                SelectFilter::make('user_id')
                    ->label('Cashier')
                    ->relationship('cashier', 'name')
                    ->visible(fn (): bool => (bool) auth()->user()?->isAdmin()),
                SelectFilter::make('status')->options(TransactionStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                self::receiptAction(),
                self::voidAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sale')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('reference_number')->label('Transaction #')->copyable(),
                TextEntry::make('created_at')->label('Date')->dateTime('M j, Y g:i A'),
                TextEntry::make('cashier.name')->label('Cashier'),
                TextEntry::make('status')->badge(),
            ]),
            Section::make('Items')->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(8)->schema([
                    TextEntry::make('product_name')->label('Item')->columnSpan(2),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('original_price')->label('Regular price')->money('PHP'),
                    TextEntry::make('unit_price')->label('Sold at')->money('PHP'),
                    TextEntry::make('line_total')->label('Line total')->money('PHP'),
                    IconEntry::make('is_manual')->label('Manual item')->boolean(),
                    IconEntry::make('price_overridden')->label('Price changed')->boolean(),
                    TextEntry::make('priceOverrideUser.name')->label('Price changed by')->placeholder('—')->columnSpan(2),
                ]),
            ]),
            Section::make('Payment')->columns(5)->columnSpanFull()->schema([
                TextEntry::make('subtotal')->money('PHP'),
                TextEntry::make('discount_amount')->label('Discount')->money('PHP'),
                TextEntry::make('total')->money('PHP')->weight('bold'),
                TextEntry::make('cash_received')->money('PHP'),
                TextEntry::make('change_amount')->label('Change')->money('PHP'),
            ]),
            Section::make('Void')->columns(3)->columnSpanFull()
                ->visible(fn (Transaction $record): bool => $record->isVoided())
                ->schema([
                    TextEntry::make('voided_at')->label('Voided on')->dateTime('M j, Y g:i A'),
                    TextEntry::make('voidedBy.name')->label('Voided by'),
                    TextEntry::make('void_reason')->label('Reason'),
                ]),
        ]);
    }

    /**
     * Opens the receipt in a new tab with the print dialog. Viewing a receipt is governed by TransactionPolicy::view.
     */
    public static function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label('Print receipt')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->url(fn (Transaction $record): string => route('receipt', ['transaction' => $record, 'print' => 1]))
            ->openUrlInNewTab();
    }

    /**
     * Admin-only void with a required reason. The action is hidden and authorized through the policy, and
     * TransactionService checks the policy again under its lock. Livewire skips TrimStrings, so the reason is trimmed here.
     */
    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Void sale')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Transaction $record): bool => auth()->user()->can('void', $record))
            ->authorize(fn (Transaction $record): bool => auth()->user()->can('void', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Transaction $record): string => "Void sale {$record->reference_number}?")
            ->modalDescription('The sale stays in the history marked as voided and its items go back into stock. This cannot be undone.')
            ->modalSubmitActionLabel('Void sale')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is this sale being voided?')
                    ->required()
                    ->trim()
                    ->string()
                    ->maxLength(TransactionService::MAX_REASON_LENGTH)
                    ->rows(2),
            ])
            ->action(function (Transaction $record, array $data): void {
                try {
                    app(TransactionService::class)->void($record, auth()->user(), $data['reason']);
                } catch (TransactionAlreadyVoidedException $exception) {
                    Notification::make()->warning()->title($exception->getMessage())->send();

                    return;
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title('Sale was not voided')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Sale voided and stock restored')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'view' => ViewTransaction::route('/{record}'),
        ];
    }

    /**
     * Range comparisons (not DATE(created_at)) so the created_at indexes are used.
     * Filter forms are not validated, so anything that is not a real Y-m-d date is ignored rather than parsed.
     *
     * @param  array<string, mixed>  $data
     */
    private static function applyDateRange(Builder $query, array $data): Builder
    {
        $from = self::parseFilterDate($data['from'] ?? null);
        $until = self::parseFilterDate($data['until'] ?? null);

        return $query
            ->when($from, fn (Builder $query, Carbon $date): Builder => $query->where('created_at', '>=', $date->startOfDay()))
            ->when($until, fn (Builder $query, Carbon $date): Builder => $query->where('created_at', '<=', $date->endOfDay()));
    }

    private static function parseFilterDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $value);
    }
}
