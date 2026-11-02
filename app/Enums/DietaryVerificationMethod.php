<?php

namespace App\Enums;

/**
 * How a claim was checked. Editorial review is an ingredient-list check by site staff and is never
 * professional certification; the other methods require evidence.
 */
enum DietaryVerificationMethod: string
{
    case EditorialIngredientReview = 'editorial_ingredient_review';
    case ProductLabelReview = 'product_label_review';
    case SupplierDocumentation = 'supplier_documentation';
    case LaboratoryTest = 'laboratory_test';
    case QualifiedExpertReview = 'qualified_expert_review';

    public function isQualified(): bool
    {
        return in_array($this->value, config('dietary.qualified_verification_methods', []), true);
    }
}
