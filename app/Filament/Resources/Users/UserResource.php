<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    /**
     * The model's hashed cast hashes the password; a blank password on edit is not
     * dehydrated, so the old one is kept. Disabled fields are not dehydrated, so an
     * admin cannot demote or deactivate their own account.
     */
    public static function form(Schema $schema): Schema
    {
        $isSelf = fn (?User $record): bool => $record !== null && $record->is(auth()->user());
        $isCashier = fn (Get $get): bool => self::toRole($get('role')) === UserRole::Cashier;

        return $schema->components([
            Section::make('Account')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->maxLength(72)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (mixed $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : null),
            ]),
            Section::make('Access')->columns(2)->columnSpanFull()->schema([
                Select::make('role')
                    ->options(UserRole::class)
                    ->default(UserRole::Cashier->value)
                    ->required()
                    ->live()
                    ->disabled($isSelf),
                Toggle::make('is_active')
                    ->label('Active (can log in)')
                    ->default(true)
                    ->inline(false)
                    ->disabled($isSelf),
                Toggle::make('can_override_price')
                    ->label('May change item prices at the POS')
                    ->inline(false)
                    ->visible($isCashier),
                Toggle::make('can_apply_discount')
                    ->label('May apply discounts at the POS')
                    ->inline(false)
                    ->visible($isCashier),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('can_override_price')
                    ->label('Price override')
                    ->state(fn (User $record): bool => $record->isAdmin() || $record->can_override_price)
                    ->boolean(),
                IconColumn::make('can_apply_discount')
                    ->label('Discounts')
                    ->state(fn (User $record): bool => $record->isAdmin() || $record->can_apply_discount)
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    private static function toRole(mixed $value): ?UserRole
    {
        return $value instanceof UserRole ? $value : UserRole::tryFrom((string) $value);
    }
}
