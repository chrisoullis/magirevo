<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false)->helperText('Plan codes never change.'),
            Toggle::make('is_active'),
            TextInput::make('max_personal_recipes')->numeric()->minValue(0)->integer()->helperText('Leave empty for unlimited. 0 means none allowed.'),
            TextInput::make('max_active_shares_per_recipe')->numeric()->minValue(0)->integer()->helperText('Leave empty for unlimited. 0 means sharing is not allowed.'),
            TextInput::make('max_storage_mb')->numeric()->minValue(0)->integer()->helperText('Not enforced yet.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $limit = fn (?int $state): string => $state === null ? 'Unlimited' : (string) $state;

        return $table
            ->columns([
                TextColumn::make('code')->sortable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('max_personal_recipes')->formatStateUsing($limit),
                TextColumn::make('max_active_shares_per_recipe')->formatStateUsing($limit),
                TextColumn::make('max_storage_mb')->formatStateUsing($limit),
                TextColumn::make('entitlements_count')->counts('entitlements')->label('Users'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
