<?php

namespace App\Support;

class IngredientAlphabet
{
    public static function letters(string $locale): array
    {
        return $locale === 'el' ? preg_split('//u', 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ', -1, PREG_SPLIT_NO_EMPTY) : range('A', 'Z');
    }
}
