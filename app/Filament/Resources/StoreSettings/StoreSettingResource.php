<?php

namespace App\Filament\Resources\StoreSettings;

use App\Filament\Resources\StoreSettings\Pages\EditStoreSetting;
use App\Filament\Resources\StoreSettings\Pages\ListStoreSettings;
use App\Models\StoreSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** A resource over a single row. The navigation link opens the edit page directly. */
class StoreSettingResource extends Resource
{
    protected static ?string $model = StoreSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Store Settings';

    protected static ?int $navigationSort = 2;

    public static function getNavigationUrl(): string
    {
        return static::getUrl('edit', ['record' => StoreSetting::current()]);
    }

    /**
     * Livewire skips TrimStrings, so text fields are trimmed and forced to strings here. A tampered array then fails validation instead of reaching the database.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Store')->columns(2)->columnSpanFull()->schema([
                TextInput::make('store_name')->required()->trim()->string()->maxLength(100),
                TextInput::make('store_phone')->label('Phone')->trim()->string()->maxLength(50),
                Textarea::make('store_address')->label('Address')->rows(2)->trim()->string()->maxLength(255)->columnSpanFull(),
            ]),
            Section::make('Receipt')->columns(2)->columnSpanFull()->schema([
                Select::make('receipt_paper_width')
                    ->label('Thermal paper width')
                    ->options([58 => '58 mm', 80 => '80 mm'])
                    ->required()
                    ->in([58, 80])
                    ->selectablePlaceholder(false),
                TextInput::make('receipt_footer')->label('Footer message')->trim()->string()->maxLength(255),
            ]),
            Section::make('Stock rules')->columnSpanFull()->schema([
                Toggle::make('allow_negative_stock')
                    ->label('Allow selling when the system shows no stock')
                    ->helperText('Off: the POS blocks the sale. On: the sale goes through and stock goes below zero until you correct the count.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store_name'),
                TextColumn::make('receipt_paper_width')->label('Paper')->suffix(' mm'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreSettings::route('/'),
            'edit' => EditStoreSetting::route('/{record}/edit'),
        ];
    }
}
