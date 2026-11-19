<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Actions\AdjustStockAction;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The barcode and SKU are trimmed before validation, because Livewire requests skip
     * TrimStrings and an untrimmed duplicate would otherwise pass the unique rule and hit
     * the database index. The barcode field ignores Enter, since USB scanners send it
     * after the code and it must not submit the form. Stock is written only by the
     * inventory service: the opening stock field is consumed by CreateProduct and the current
     * stock field is display-only.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product details')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(150)->columnSpanFull(),
                TextInput::make('barcode')
                    ->trim()
                    ->string()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->helperText('Optional. Click here and scan the item to fill it in.')
                    ->extraInputAttributes(['x-on:keydown.enter.prevent' => '']),
                TextInput::make('sku')->label('SKU')->trim()->string()->maxLength(64)->unique(ignoreRecord: true),
                TextInput::make('selling_price')
                    ->required()->numeric()->rule('decimal:0,2')->minValue(0)->maxValue(99999999.99)->step('0.01')->prefix('₱'),
                TextInput::make('cost_price')
                    ->numeric()->rule('decimal:0,2')->minValue(0)->maxValue(99999999.99)->step('0.01')->prefix('₱'),
                Select::make('categories')
                    ->relationship('categories', 'name')
                    ->multiple()->preload()->searchable()
                    ->columnSpanFull(),
                Textarea::make('description')->label('Description / notes')->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Stock and status')->columns(2)->columnSpanFull()->schema([
                TextInput::make('initial_stock')
                    ->label('Opening stock')
                    ->helperText('How many are on the shelf right now.')
                    ->integer()->minValue(0)->maxValue(AdjustStockAction::MAX_QUANTITY)->default(0)
                    ->visibleOn('create'),
                TextInput::make('stock_quantity')
                    ->label('Current stock')
                    ->helperText('Use "Adjust stock" to change this.')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('low_stock_threshold')
                    ->label('Low-stock warning at or below')
                    ->required()->integer()->minValue(0)->maxValue(100000)->default(5),
                Toggle::make('is_active')->label('Active (shown in the POS)')->default(true)->inline(false),
            ]),
            Section::make('Image')->columnSpanFull()->schema([
                FileUpload::make('image_path')
                    ->label('Product image')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->preventFilePathTampering()
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public')
                    ->maxSize(2048),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->visibility('public')->imageSize(40),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('barcode')->searchable()->toggleable(),
                TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('categories.name')->badge()->toggleable(),
                TextColumn::make('selling_price')->money('PHP')->sortable(),
                TextColumn::make('stock_quantity')
                    ->label('Stock')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (Product $record): string => $record->isLowStock() ? 'danger' : 'success'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('categories')->relationship('categories', 'name')->preload(),
                Filter::make('low_stock')->label('Low stock')->query(fn (Builder $query): Builder => $query->lowStock()),
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                AdjustStockAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('name');
    }

    /**
     * Lets admins open and restore soft-deleted products.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
