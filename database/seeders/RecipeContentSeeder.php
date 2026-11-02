<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Unit;
use Database\Seeders\Concerns\ProtectsPublishedRecipes;
use Illuminate\Database\Seeder;

/**
 * Adds bilingual structured ingredients and cooking steps to the sample recipes.
 */
class RecipeContentSeeder extends Seeder
{
    use ProtectsPublishedRecipes;

    public function run(): void
    {
        if (! $this->sampleRecipesAllowed()) {
            return;
        }

        $this->call(IngredientSeeder::class);

        $units = Unit::query()->pluck('id', 'code');

        foreach ($this->recipes() as $slug => $content) {
            $recipe = Recipe::query()->whereTranslation('slug', $slug, 'en')->first();

            if ($recipe === null || $this->isLive($recipe) || $recipe->recipeIngredients()->exists() || $recipe->steps()->exists()) {
                continue;
            }

            foreach ($content['ingredients'] as $index => $line) {
                $ingredient = $this->ingredient($line['code']);

                $recipeIngredient = $recipe->recipeIngredients()->create([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $line['qty'] ?? null,
                    'unit_id' => isset($line['unit']) ? $units[$line['unit']] : null,
                    'scaling_mode' => $line['scaling'] ?? 'linear',
                    'sort_order' => $index,
                ]);

                $recipeIngredient->fill([
                    'en' => ['preparation_note' => $line['note'][0] ?? null, 'quantity_text' => $line['text'][0] ?? null],
                    'el' => ['preparation_note' => $line['note'][1] ?? null, 'quantity_text' => $line['text'][1] ?? null],
                ])->save();
            }

            foreach ($content['steps'] as $index => [$english, $greek]) {
                $step = $recipe->steps()->create(['step_number' => $index + 1]);
                $step->fill(['en' => ['instruction' => $english], 'el' => ['instruction' => $greek]])->save();
            }
        }
    }

    private function ingredient(string $code): Ingredient
    {
        return Ingredient::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array<string, array{ingredients: list<array<string, mixed>>, steps: list<array{0: string, 1: string}>}>
     */
    private function recipes(): array
    {
        return [
            'crispy-chickpeas-herby-yogurt' => [
                'ingredients' => [
                    ['code' => 'chickpeas-tin', 'qty' => 2, 'note' => ['drained and dried', 'στραγγισμένα και στεγνωμένα']],
                    ['code' => 'olive-oil', 'qty' => 2, 'unit' => 'tbsp'],
                    ['code' => 'smoked-paprika', 'qty' => 1, 'unit' => 'tsp'],
                    ['code' => 'greek-yogurt', 'qty' => 200, 'unit' => 'g', 'note' => ['thick', 'στραγγιστό']],
                    ['code' => 'lemon', 'qty' => 1, 'note' => ['zested and juiced', 'ξύσμα και χυμός']],
                    ['code' => 'dill-parsley', 'scaling' => 'fixed', 'text' => ['A handful each of', 'Μια χούφτα από']],
                ],
                'steps' => [
                    ['Heat the oven to 220°C / 425°F. Toss the chickpeas with oil, paprika and salt.', 'Ζεστάνετε το φούρνο στους 220°C. Ανακατέψτε τα ρεβίθια με λάδι, πάπρικα και αλάτι.'],
                    ['Roast for 25 minutes, shaking the tray once, until crisp and deeply golden.', 'Ψήστε για 25 λεπτά, κουνώντας το ταψί μία φορά, μέχρι να γίνουν τραγανά και βαθιά χρυσαφένια.'],
                    ['Stir the yogurt with lemon zest, a squeeze of juice and chopped herbs. Spoon onto plates and tumble over the warm chickpeas.', 'Ανακατέψτε το γιαούρτι με ξύσμα λεμονιού, λίγο χυμό και ψιλοκομμένα μυρωδικά. Σερβίρετε στα πιάτα και ρίξτε από πάνω τα ζεστά ρεβίθια.'],
                ],
            ],
            'brown-butter-miso-noodles' => [
                'ingredients' => [
                    ['code' => 'ramen-noodles', 'qty' => 200, 'unit' => 'g'],
                    ['code' => 'butter', 'qty' => 60, 'unit' => 'g', 'note' => ['unsalted', 'ανάλατο']],
                    ['code' => 'white-miso', 'qty' => 1, 'unit' => 'tbsp'],
                    ['code' => 'lime', 'qty' => 1],
                    ['code' => 'spring-onion', 'qty' => 2, 'note' => ['sliced', 'ψιλοκομμένα']],
                    ['code' => 'sesame-seeds', 'scaling' => 'fixed', 'text' => ['To finish', 'Για το τελείωμα']],
                ],
                'steps' => [
                    ['Cook the noodles in salted water until just tender. Reserve a mug of cooking water.', 'Βράστε τα νούντλς σε αλατισμένο νερό μέχρι να μαλακώσουν. Κρατήστε μια κούπα από το νερό του βρασμού.'],
                    ['Brown the butter in a pan until it smells nutty. Whisk in miso and a splash of noodle water.', 'Ροδίστε το βούτυρο σε μια κατσαρόλα μέχρι να μυρίσει καρυδάτο. Χτυπήστε μέσα το μίσο και λίγο από το νερό των νούντλς.'],
                    ['Toss the noodles through the sauce. Finish with lime, spring onions and sesame.', 'Ανακατέψτε τα νούντλς με τη σάλτσα. Τελειώστε με λάιμ, κρεμμυδάκι και σουσάμι.'],
                ],
            ],
            'roasted-tomato-butter-bean-toast' => [
                'ingredients' => [
                    ['code' => 'cherry-tomatoes', 'qty' => 500, 'unit' => 'g'],
                    ['code' => 'butter-beans-tin', 'qty' => 2, 'note' => ['drained', 'στραγγισμένοι']],
                    ['code' => 'olive-oil', 'qty' => 3, 'unit' => 'tbsp'],
                    ['code' => 'sourdough', 'qty' => 4],
                    ['code' => 'basil-pesto', 'qty' => 2, 'unit' => 'tbsp'],
                    ['code' => 'garlic', 'qty' => 1],
                ],
                'steps' => [
                    ['Heat the oven to 200°C / 400°F. Put tomatoes and beans in a roasting dish with oil and salt.', 'Ζεστάνετε το φούρνο στους 200°C. Βάλτε τις ντομάτες και τα φασόλια σε ταψί με λάδι και αλάτι.'],
                    ['Roast for 35 minutes until the tomatoes collapse and the beans are glossy.', 'Ψήστε για 35 λεπτά, μέχρι να σπάσουν οι ντομάτες και να γυαλίσουν τα φασόλια.'],
                    ['Toast the sourdough and rub with garlic. Spoon over the tomato beans and finish with pesto.', 'Φρυγανίστε το ψωμί και τρίψτε το με σκόρδο. Απλώστε από πάνω τα φασόλια με τις ντομάτες και τελειώστε με πέστο.'],
                ],
            ],
            'olive-oil-citrus-cake' => [
                'ingredients' => [
                    ['code' => 'caster-sugar', 'qty' => 200, 'unit' => 'g'],
                    ['code' => 'eggs', 'qty' => 3],
                    ['code' => 'olive-oil', 'qty' => 150, 'unit' => 'ml', 'note' => ['fruity', 'φρουτώδες']],
                    ['code' => 'plain-flour', 'qty' => 200, 'unit' => 'g'],
                    ['code' => 'orange', 'qty' => 2, 'note' => ['zested and juiced', 'ξύσμα και χυμός']],
                    ['code' => 'baking-powder', 'qty' => 1, 'unit' => 'tsp'],
                ],
                'steps' => [
                    ['Heat the oven to 175°C / 350°F and line a loaf tin with baking paper.', 'Ζεστάνετε το φούρνο στους 175°C και στρώστε ένα φόρμα κέικ με αντικολλητικό χαρτί.'],
                    ['Whisk sugar and eggs until pale. Slowly whisk in the olive oil, orange zest and juice.', 'Χτυπήστε τη ζάχαρη με τα αυγά μέχρι να ανοίξουν στο χρώμα. Προσθέστε αργά το ελαιόλαδο, το ξύσμα και το χυμό πορτοκαλιού.'],
                    ['Fold in flour and baking powder. Bake for 45 minutes, until a skewer comes out clean.', 'Ανακατέψτε απαλά το αλεύρι και το μπέικιν πάουντερ. Ψήστε για 45 λεπτά, μέχρι να βγει καθαρό ένα ξυλάκι.'],
                ],
            ],
            'green-goddess-potato-salad' => [
                'ingredients' => [
                    ['code' => 'baby-potatoes', 'qty' => 750, 'unit' => 'g'],
                    ['code' => 'greek-yogurt', 'qty' => 100, 'unit' => 'g'],
                    ['code' => 'parsley-bunch', 'qty' => 1],
                    ['code' => 'chives-bunch', 'qty' => 1],
                    ['code' => 'capers', 'qty' => 2, 'unit' => 'tbsp'],
                    ['code' => 'dijon-mustard', 'qty' => 1, 'unit' => 'tbsp'],
                ],
                'steps' => [
                    ['Boil the potatoes in well-salted water until tender, about 20 minutes.', 'Βράστε τις πατάτες σε καλά αλατισμένο νερό μέχρι να μαλακώσουν, περίπου 20 λεπτά.'],
                    ['Blend yogurt, herbs, mustard, capers and a splash of water into a vivid green dressing.', 'Χτυπήστε στο μπλέντερ το γιαούρτι, τα μυρωδικά, τη μουστάρδα, την κάπαρη και λίγο νερό για να γίνει μια έντονα πράσινη σάλτσα.'],
                    ['Drain the potatoes, halve while warm and fold through the dressing. Season generously.', 'Στραγγίστε τις πατάτες, κόψτε τες στη μέση όσο είναι ζεστές και ανακατέψτε με τη σάλτσα. Αλατοπιπερώστε γενναιόδωρα.'],
                ],
            ],
            'cardamom-pear-breakfast-buns' => [
                'ingredients' => [
                    ['code' => 'strong-white-flour', 'qty' => 500, 'unit' => 'g'],
                    ['code' => 'instant-yeast', 'qty' => 7, 'unit' => 'g'],
                    ['code' => 'milk', 'qty' => 250, 'unit' => 'ml', 'note' => ['warm', 'ζεστό']],
                    ['code' => 'butter', 'qty' => 75, 'unit' => 'g', 'note' => ['soft', 'μαλακό']],
                    ['code' => 'pear', 'qty' => 2, 'note' => ['diced', 'κομμένα σε κύβους']],
                    ['code' => 'ground-cardamom', 'qty' => 1, 'unit' => 'tsp'],
                ],
                'steps' => [
                    ['Mix flour, yeast, milk, butter and a pinch of salt into a soft dough. Knead for 8 minutes.', 'Ανακατέψτε το αλεύρι, τη μαγιά, το γάλα, το βούτυρο και μια πρέζα αλάτι σε μαλακή ζύμη. Ζυμώστε για 8 λεπτά.'],
                    ['Leave covered for 1 hour, until doubled. Roll out, scatter over pear and cardamom, then roll up.', 'Αφήστε καλυμμένη για 1 ώρα, μέχρι να διπλασιαστεί. Ανοίξτε φύλλο, πασπαλίστε με αχλάδι και κάρδαμο και τυλίξτε το.'],
                    ['Cut into 8 buns and bake at 190°C / 375°F for 30 minutes until golden.', 'Κόψτε σε 8 ρολά και ψήστε στους 190°C για 30 λεπτά, μέχρι να ροδίσουν.'],
                ],
            ],
        ];
    }
}
