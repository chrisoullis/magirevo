<?php

namespace App\Filament\Resources\IngredientSubstitutions\Schemas;

use App\Filament\Support\TranslationTabs;
use App\Models\Ingredient;
use App\Models\Recipe;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IngredientSubstitutionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Substitution')->columns(2)->columnSpanFull()->schema([
                    Select::make('ingredient_id')
                        ->label('Original ingredient')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::ingredientOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::ingredientLabel(Ingredient::with('translations')->find($value)))
                        ->exists('ingredients', 'id'),
                    Select::make('replacement_ingredient_id')
                        ->label('Replacement ingredient')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::ingredientOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::ingredientLabel(Ingredient::with('translations')->find($value)))
                        ->exists('ingredients', 'id')
                        ->different('ingredient_id'),
                    Select::make('recipe_id')
                        ->label('Recipe-specific (optional)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Recipe::query()
                            ->where(fn (Builder $query) => $query->whereHas(
                                'translations',
                                fn (Builder $translation) => $translation->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                            ))
                            ->with('translations')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Recipe $recipe): array => [
                                $recipe->id => (string) ($recipe->translate('en')?->title ?? $recipe->id),
                            ])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => $value === null
                            ? null
                            : (string) (Recipe::with('translations')->find($value)?->translate('en')?->title ?? $value))
                        ->exists('recipes', 'id'),
                    Select::make('status')
                        ->options([
                            'pending' => 'Pending approval',
                            'approved' => 'Approved and active',
                            'inactive' => 'Inactive',
                        ])
                        ->default('pending')
                        ->required(),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    Textarea::make("{$locale}.explanation")
                        ->label('Explanation')
                        ->rows(3)
                        ->maxLength(2000),
                ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function ingredientOptions(string $search): array
    {
        return Ingredient::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('code', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('translations', fn (Builder $translation) => $translation
                    ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->with('translations')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Ingredient $ingredient): array => [
                $ingredient->id => self::ingredientLabel($ingredient),
            ])
            ->all();
    }

    private static function ingredientLabel(?Ingredient $ingredient): ?string
    {
        return $ingredient === null
            ? null
            : (string) ($ingredient->translate('en')?->name ?? $ingredient->code);
    }
}
