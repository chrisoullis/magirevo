<?php

namespace App\Filament\Resources\UserEntitlements;

use App\Filament\Resources\UserEntitlements\Pages\EditUserEntitlement;
use App\Filament\Resources\UserEntitlements\Pages\ListUserEntitlements;
use App\Models\Plan;
use App\Models\UserEntitlement;
use App\Services\RecipeQuotaService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserEntitlementResource extends Resource
{
    protected static ?string $model = UserEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $modelLabel = 'user quota';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'plan']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')->label('Plan')->options(fn (): array => Plan::query()->orderBy('code')->pluck('code', 'id')->all())->required(),
            TextInput::make('personal_recipe_limit_override')->numeric()->integer()->minValue(0)->helperText('Empty uses the plan. 0 blocks new personal recipes.'),
            TextInput::make('sharing_limit_override')->numeric()->integer()->minValue(0)->helperText('Empty uses the plan. 0 blocks sharing.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable()->description(fn (UserEntitlement $record): string => $record->user->email),
                TextColumn::make('plan.code')->label('Plan')->badge(),
                TextColumn::make('usage')
                    ->label('Personal recipes')
                    ->state(function (UserEntitlement $record): string {
                        $summary = app(RecipeQuotaService::class)->summary($record->user);

                        return $summary['used'].' / '.($summary['limit'] ?? '∞');
                    }),
                TextColumn::make('personal_recipe_limit_override')->label('Recipe override')->placeholder('—'),
                TextColumn::make('sharing_limit_override')->label('Sharing override')->placeholder('—'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserEntitlements::route('/'),
            'edit' => EditUserEntitlement::route('/{record}/edit'),
        ];
    }
}
