<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Filament\Support\DeletionGuard;
use App\Filament\Support\TranslatedColumn;
use App\Models\Category;
use App\Services\CategoryHierarchyService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->state(fn (Category $record): string => $record->imageUrl())
                    ->imageSize(40)
                    ->square(),
                TextColumn::make('code')->searchable()->sortable(),
                TranslatedColumn::make('en'),
                TranslatedColumn::make('el'),
                TextColumn::make('parent_name')
                    ->label('Parent')
                    ->state(fn (Category $record): ?string => $record->parent?->getTranslation('en', false)?->name ?? $record->parent?->code)
                    ->placeholder('—'),
                TextColumn::make('level')
                    ->state(fn (Category $record): string => $record->isRoot() ? 'Parent' : 'Subcategory')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Parent' ? 'primary' : 'gray'),
                TextColumn::make('sort_order')->label('Order')->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active')->sortable(),
                TextColumn::make('direct_recipes_count')->label('Direct recipes')->sortable(),
                TextColumn::make('total_recipes_count')
                    ->label('Total unique recipes')
                    ->tooltip('Distinct recipes assigned to the category or any of its subcategories')
                    ->sortable(),
                TextColumn::make('published_total_recipes_count')->label('Published recipes')->sortable()->toggleable(),
                TextColumn::make('children_count')->label('Subcategories')->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->hierarchyOrdered())
            ->filters([
                Filter::make('roots_only')->label('Root categories only')
                    ->query(fn (Builder $query): Builder => $query->roots()),
                Filter::make('subcategories_only')->label('Subcategories only')
                    ->query(fn (Builder $query): Builder => $query->subcategories()),
                SelectFilter::make('parent_id')
                    ->label('Parent category')
                    ->options(fn (): array => Category::query()->roots()->with('translations')->ordered()->get()
                        ->mapWithKeys(fn (Category $category): array => [$category->getKey() => $category->getTranslation('en', false)?->name ?? $category->code])
                        ->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('moveUp')
                    ->label('Move up')
                    ->icon('heroicon-m-arrow-up')
                    ->iconButton()
                    ->authorize('update')
                    ->action(fn (Category $record) => app(CategoryHierarchyService::class)->shift($record, -1)),
                Action::make('moveDown')
                    ->label('Move down')
                    ->icon('heroicon-m-arrow-down')
                    ->iconButton()
                    ->authorize('update')
                    ->action(fn (Category $record) => app(CategoryHierarchyService::class)->shift($record, 1)),
                Action::make('toggleActive')
                    ->label(fn (Category $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (Category $record): string => $record->is_active ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
                    ->authorize('update')
                    ->action(fn (Category $record) => $record->update(['is_active' => ! $record->is_active])),
                DeleteAction::make()->before(DeletionGuard::single()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->before(DeletionGuard::bulk()),
                ]),
            ]);
    }
}
