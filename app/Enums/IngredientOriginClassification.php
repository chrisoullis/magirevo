<?php

namespace App\Enums;

enum IngredientOriginClassification: string
{
    case AnimalFlesh = 'animal_flesh';
    case AnimalDerivedOther = 'animal_derived_other';
    case NotAnimalDerived = 'not_animal_derived';
    case Unknown = 'unknown';
}
