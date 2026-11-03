<?php

namespace App\Filament\Resources\Ingredients\Tables;

use App\Filament\Support\DeletionGuard;
use App\Filament\Support\TranslatedColumn;
use App\Models\Ingredient;
use App\Models\IngredientCatalogGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')->state(fn (Ingredient $record): string => $record->imageUrl(true))
                    ->height(48)->extraImgAttributes(fn (Ingredient $record): array => ['loading' => 'lazy', 'alt' => $record->imageAlt(app()->getLocale())]),
                TranslatedColumn::make('en'),
                TranslatedColumn::make('el'),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('catalogGroup.code')->label('Ingredient group')->formatStateUsing(fn (?string $state, Ingredient $record): string => $record->catalogGroup?->translate(app()->getLocale())?->name ?? $state ?? '—'),
                TextColumn::make('defaultUnit.code')->label('Default unit')->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label('Active')->sortable(),
                TextColumn::make('recipe_ingredients_count')->counts('recipeIngredients')->label('Recipe lines'),
            ])
            ->defaultSort('code')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('catalog_group_id')->label('Ingredient group')
                    ->options(fn (): array => IngredientCatalogGroup::query()->with('translations')->orderBy('sort_order')->get()->mapWithKeys(fn ($group): array => [$group->id => $group->translate(app()->getLocale())?->name ?? $group->code])->all()),
                Filter::make('missing_image')->label('Missing primary photograph')->query(fn (Builder $query) => $query->whereDoesntHave('primaryImage')),
                Filter::make('incomplete_translations')->label('Incomplete EN/EL names or slugs')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereDoesntHave('translations', fn ($t) => $t->where('locale', 'en')->whereRaw('length(btrim(name)) > 0')->whereNotNull('slug')->whereRaw('length(btrim(slug)) > 0'))
                        ->orWhereDoesntHave('translations', fn ($t) => $t->where('locale', 'el')->whereRaw('length(btrim(name)) > 0')->whereNotNull('slug')->whereRaw('length(btrim(slug)) > 0')))),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->before(DeletionGuard::single()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->before(DeletionGuard::bulk()),
                ]),
            ]);
    }
}
