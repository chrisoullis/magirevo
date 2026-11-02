<?php

namespace App\Enums;

/**
 * Descriptive and cuisine tags are editorial. Every other classification is a dietary claim that is
 * hidden from the public until it is individually approved for the current recipe revision.
 * Unclassified is the fail-closed default for new tags.
 */
enum TagClassification: string
{
    case Unclassified = 'unclassified';
    case Descriptive = 'descriptive';
    case Cuisine = 'cuisine';
    case DietStyle = 'diet_style';
    case NutritionClaim = 'nutrition_claim';
    case IngredientAllergen = 'ingredient_allergen';

    public function isDietaryClaim(): bool
    {
        return in_array($this, [self::DietStyle, self::NutritionClaim, self::IngredientAllergen], true);
    }

    public function isFreelyPublic(): bool
    {
        return in_array($this, [self::Descriptive, self::Cuisine], true);
    }

    public function isHighRisk(): bool
    {
        return in_array($this->value, config('dietary.high_risk_classifications', []), true);
    }

    /**
     * @return list<string>
     */
    public static function freelyPublicValues(): array
    {
        return [self::Descriptive->value, self::Cuisine->value];
    }

    /**
     * @return list<string>
     */
    public static function claimValues(): array
    {
        return [self::DietStyle->value, self::NutritionClaim->value, self::IngredientAllergen->value];
    }
}
