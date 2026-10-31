<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use Illuminate\Database\Seeder;

class IngredientSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->ingredients() as $code => [$english, $englishPlural, $greek, $greekPlural]) {
            $ingredient = Ingredient::query()->firstOrCreate(['code' => $code], ['is_active' => true]);
            $ingredient->fill([
                'en' => ['name' => $english, 'plural_name' => $englishPlural],
                'el' => ['name' => $greek, 'plural_name' => $greekPlural],
            ])->save();
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string, 3: ?string}>
     */
    private function ingredients(): array
    {
        return [
            'chickpeas-tin' => ['tin of chickpeas', 'tins of chickpeas', 'κονσέρβα ρεβίθια', 'κονσέρβες ρεβίθια'],
            'olive-oil' => ['olive oil', null, 'ελαιόλαδο', null],
            'smoked-paprika' => ['smoked paprika', null, 'καπνιστή πάπρικα', null],
            'greek-yogurt' => ['Greek yogurt', null, 'ελληνικό γιαούρτι', null],
            'lemon' => ['lemon', 'lemons', 'λεμόνι', 'λεμόνια'],
            'dill-parsley' => ['dill and parsley', null, 'άνηθος και μαϊντανός', null],
            'ramen-noodles' => ['ramen noodles', null, 'νούντλς ράμεν', null],
            'butter' => ['butter', null, 'βούτυρο', null],
            'white-miso' => ['white miso', null, 'λευκό μίσο', null],
            'lime' => ['lime', 'limes', 'λάιμ', 'λάιμ'],
            'spring-onion' => ['spring onion', 'spring onions', 'φρέσκο κρεμμυδάκι', 'φρέσκα κρεμμυδάκια'],
            'sesame-seeds' => ['toasted sesame seeds', null, 'καβουρδισμένο σουσάμι', null],
            'cherry-tomatoes' => ['ripe cherry tomatoes', null, 'ώριμα ντοματίνια', null],
            'butter-beans-tin' => ['tin of butter beans', 'tins of butter beans', 'κονσέρβα γίγαντες', 'κονσέρβες γίγαντες'],
            'sourdough' => ['thick slice of sourdough', 'thick slices of sourdough', 'χοντρή φέτα ψωμί προζυμιού', 'χοντρές φέτες ψωμί προζυμιού'],
            'basil-pesto' => ['basil pesto', null, 'πέστο βασιλικού', null],
            'garlic' => ['garlic clove', 'garlic cloves', 'σκελίδα σκόρδο', 'σκελίδες σκόρδο'],
            'caster-sugar' => ['caster sugar', null, 'λεπτή κρυσταλλική ζάχαρη', null],
            'eggs' => ['egg', 'eggs', 'αυγό', 'αυγά'],
            'plain-flour' => ['plain flour', null, 'αλεύρι για όλες τις χρήσεις', null],
            'orange' => ['orange', 'oranges', 'πορτοκάλι', 'πορτοκάλια'],
            'baking-powder' => ['baking powder', null, 'μπέικιν πάουντερ', null],
            'baby-potatoes' => ['baby potatoes', null, 'μικρές πατάτες', null],
            'parsley-bunch' => ['small bunch of parsley', 'small bunches of parsley', 'μικρό ματσάκι μαϊντανός', 'μικρά ματσάκια μαϊντανός'],
            'chives-bunch' => ['small bunch of chives', 'small bunches of chives', 'μικρό ματσάκι σχοινόπρασο', 'μικρά ματσάκια σχοινόπρασο'],
            'capers' => ['capers', null, 'κάπαρη', null],
            'dijon-mustard' => ['Dijon mustard', null, 'μουστάρδα Ντιζόν', null],
            'strong-white-flour' => ['strong white flour', null, 'σκληρό αλεύρι για ψωμί', null],
            'instant-yeast' => ['instant yeast', null, 'στιγμιαία μαγιά', null],
            'milk' => ['milk', null, 'γάλα', null],
            'pear' => ['ripe pear', 'ripe pears', 'ώριμο αχλάδι', 'ώριμα αχλάδια'],
            'ground-cardamom' => ['ground cardamom', null, 'κάρδαμο τριμμένο', null],
        ];
    }
}
