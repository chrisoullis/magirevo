<?php

namespace App\Enums;

enum TagGroup: string
{
    case Cuisine = 'cuisine';
    case CookingMethod = 'cooking_method';
    case Equipment = 'equipment';
    case Occasion = 'occasion';
    case PreparationTime = 'preparation_time';
    case Flavour = 'flavour';
    case Texture = 'texture';
    case MainIngredient = 'main_ingredient';
    case RecipeStyle = 'recipe_style';
    case Season = 'season';
    case Practical = 'practical';
    case DietaryClaim = 'dietary_claim';

    public function label(?string $locale = null): string
    {
        return __("tags.groups.{$this->value}", [], $locale);
    }

    /** @return array<string, string> */
    public static function options(?string $locale = null): array
    {
        $options = [];

        foreach (self::cases() as $group) {
            $options[$group->value] = $group->label($locale);
        }

        return $options;
    }
}
