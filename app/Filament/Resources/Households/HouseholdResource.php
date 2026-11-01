<?php

namespace App\Filament\Resources\Households;

use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Models\Household;
use App\Support\AppPermissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only support view of households. It shows structure and counts only; it never exposes meal plans,
 * recipes, shopping items or batches, and household membership never grants access to private recipes.
 * Household roles are managed by households themselves, not by administrators here.
 */
class HouseholdResource extends Resource
{
    protected static ?string $model = Household::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 60;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['members.user'])
            ->withCount(['members', 'invitations', 'mealPlans', 'mealPlanTemplates', 'batchCooks']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(AppPermissions::USERS_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('owner')->label('Owner')->state(fn (Household $record): string => $record->members->firstWhere('role', 'owner')?->user?->name ?? '—'),
                TextColumn::make('members_count')->label('Members')->sortable(),
                TextColumn::make('invitations_count')->label('Invitations')->sortable(),
                TextColumn::make('meal_plans_count')->label('Meal plans')->sortable(),
                TextColumn::make('meal_plan_templates_count')->label('Templates')->sortable()->toggleable(),
                TextColumn::make('batch_cooks_count')->label('Batches')->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListHouseholds::route('/')];
    }
}
