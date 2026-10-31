<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()
            ->where('is_admin', true)
            ->orderBy('id')
            ->first()
            ?? User::query()->firstOrCreate(
                ['email' => 'recipe-author@example.test'],
                ['name' => 'Editorial Team', 'password' => Str::random(64)],
            );

        $recipes = [
            [
                'en' => [
                    'title' => 'Crispy chickpeas & herby yogurt',
                    'slug' => 'crispy-chickpeas-herby-yogurt',
                    'summary' => 'A lemony, crunchy bowl that turns a tin of chickpeas into dinner worth sitting down for.',
                    'introduction' => 'Warm spices, cool yogurt and plenty of fresh herbs make this pantry-friendly supper feel like a small celebration.',
                    'seo_title' => 'Crispy chickpeas & herby yogurt',
                    'seo_description' => 'A lemony, crunchy bowl that turns a tin of chickpeas into dinner worth sitting down for.',
                ],
                'el' => [
                    'title' => 'Τραγανά ρεβίθια με γιαούρτι και μυρωδικά',
                    'slug' => 'crispy-chickpeas-herby-yogurt-el',
                    'summary' => 'Ένα λεμονάτο, τραγανό πιάτο που μετατρέπει τα ρεβίθια σε ένα δείπνο για να απολαύσετε με την ησυχία σας.',
                    'introduction' => 'Ζεστά μπαχαρικά, δροσερό γιαούρτι και άφθονα φρέσκα μυρωδικά κάνουν αυτό το εύκολο βραδινό ένα μικρό γιορτινό γεύμα.',
                    'seo_title' => 'Τραγανά ρεβίθια με γιαούρτι και μυρωδικά',
                    'seo_description' => 'Ρεβίθια με λεμόνι, δροσερό γιαούρτι και φρέσκα μυρωδικά για ένα ξεχωριστό δείπνο.',
                ],
                'category' => 'Weeknight',
                'tags' => ['vegetarian'],
                'prep_minutes' => 10,
                'cook_minutes' => 25,
                'servings' => 2,
                'difficulty' => 'Easy',
                'image_url' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1400&q=85',
                'ingredients' => ['2 tins chickpeas, drained and dried', '2 tbsp olive oil', '1 tsp smoked paprika', '200 g thick Greek yogurt', '1 lemon, zested and juiced', 'A handful each of dill and parsley'],
                'instructions' => ['Heat the oven to 220°C / 425°F. Toss the chickpeas with oil, paprika and salt.', 'Roast for 25 minutes, shaking the tray once, until crisp and deeply golden.', 'Stir the yogurt with lemon zest, a squeeze of juice and chopped herbs. Spoon onto plates and tumble over the warm chickpeas.'],
                'is_featured' => true,
            ],
            [
                'en' => [
                    'title' => 'Brown butter miso noodles',
                    'slug' => 'brown-butter-miso-noodles',
                    'summary' => 'Silky noodles with nutty brown butter, mellow miso and a bright squeeze of lime.',
                    'introduction' => 'A quick, deeply savory noodle bowl for the nights when the cupboard is more inspiring than the fridge.',
                    'seo_title' => 'Brown butter miso noodles',
                    'seo_description' => 'Silky noodles meet nutty brown butter, mellow miso and a bright squeeze of lime.',
                ],
                'el' => [
                    'title' => 'Νουντλς με καμένο βούτυρο και μίσο',
                    'slug' => 'brown-butter-miso-noodles-el',
                    'summary' => 'Μεταξένια νουντλς με καρυδάτο καμένο βούτυρο, ήπιο μίσο και μια ζωηρή πινελιά λάιμ.',
                    'introduction' => 'Ένα γρήγορο, βαθιά γευστικό μπολ με νουντλς για τις βραδιές που το ντουλάπι εμπνέει περισσότερο από το ψυγείο.',
                    'seo_title' => 'Νουντλς με καμένο βούτυρο και μίσο',
                    'seo_description' => 'Νουντλς με καμένο βούτυρο, μίσο και φρέσκο λάιμ για ένα γρήγορο γεύμα.',
                ],
                'category' => 'Weeknight',
                'tags' => ['quick'],
                'prep_minutes' => 8,
                'cook_minutes' => 12,
                'servings' => 2,
                'difficulty' => 'Easy',
                'image_url' => 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=1200&q=85',
                'ingredients' => ['200 g ramen noodles', '60 g unsalted butter', '1 tbsp white miso', '1 lime', '2 spring onions, sliced', 'Toasted sesame seeds'],
                'instructions' => ['Cook the noodles in salted water until just tender. Reserve a mug of cooking water.', 'Brown the butter in a pan until it smells nutty. Whisk in miso and a splash of noodle water.', 'Toss the noodles through the sauce. Finish with lime, spring onions and sesame.'],
                'is_featured' => false,
            ],
            [
                'en' => [
                    'title' => 'Roasted tomato & butter bean toast',
                    'slug' => 'roasted-tomato-butter-bean-toast',
                    'summary' => 'Jammy tomatoes and creamy beans piled high on sourdough with a little green sauce.',
                    'introduction' => 'Slow-roasting coaxes out the sweetness in tomatoes; butter beans make the whole thing substantial enough for lunch.',
                    'seo_title' => 'Roasted tomato & butter bean toast',
                    'seo_description' => 'Jammy roasted tomatoes and creamy butter beans piled onto sourdough with green sauce.',
                ],
                'el' => [
                    'title' => 'Φρυγανισμένο ψωμί με ψητές ντομάτες και φασόλια',
                    'slug' => 'roasted-tomato-butter-bean-toast-el',
                    'summary' => 'Μελωμένες ντομάτες και κρεμώδη φασόλια πάνω σε προζυμένιο ψωμί με λίγη πράσινη σάλτσα.',
                    'introduction' => 'Το αργό ψήσιμο αναδεικνύει τη γλύκα της ντομάτας, ενώ τα φασόλια κάνουν το πιάτο χορταστικό για το μεσημεριανό.',
                    'seo_title' => 'Ψητές ντομάτες και φασόλια σε ψωμί',
                    'seo_description' => 'Ψητές ντομάτες και κρεμώδη φασόλια σε φρυγανισμένο προζυμένιο ψωμί.',
                ],
                'category' => 'Vegetarian',
                'tags' => ['vegetarian', 'budget-friendly'],
                'prep_minutes' => 10,
                'cook_minutes' => 35,
                'servings' => 4,
                'difficulty' => 'Easy',
                'image_url' => 'https://images.unsplash.com/photo-1498837167922-ddd27525d352?auto=format&fit=crop&w=1200&q=85',
                'ingredients' => ['500 g ripe cherry tomatoes', '2 tins butter beans, drained', '3 tbsp olive oil', '4 thick slices sourdough', '2 tbsp basil pesto', '1 garlic clove'],
                'instructions' => ['Heat the oven to 200°C / 400°F. Put tomatoes and beans in a roasting dish with oil and salt.', 'Roast for 35 minutes until the tomatoes collapse and the beans are glossy.', 'Toast the sourdough and rub with garlic. Spoon over the tomato beans and finish with pesto.'],
                'is_featured' => false,
            ],
            [
                'en' => [
                    'title' => 'Olive oil citrus cake',
                    'slug' => 'olive-oil-citrus-cake',
                    'summary' => 'A tender, golden cake with orange zest, grassy olive oil and a crackly sugar top.',
                    'introduction' => 'This simple loaf keeps beautifully and is just as at home beside morning coffee as it is after dinner.',
                    'seo_title' => 'Olive oil citrus cake',
                    'seo_description' => 'A tender citrus loaf with orange zest, fruity olive oil and a crackly sugar top.',
                ],
                'el' => [
                    'title' => 'Κέικ με ελαιόλαδο και εσπεριδοειδή',
                    'slug' => 'olive-oil-citrus-cake-el',
                    'summary' => 'Ένα αφράτο, χρυσαφένιο κέικ με ξύσμα πορτοκαλιού, αρωματικό ελαιόλαδο και τραγανή ζαχαρένια κρούστα.',
                    'introduction' => 'Αυτό το απλό κέικ διατηρείται υπέροχα και ταιριάζει εξίσου με τον πρωινό καφέ ή μετά το δείπνο.',
                    'seo_title' => 'Κέικ με ελαιόλαδο και εσπεριδοειδή',
                    'seo_description' => 'Αφράτο κέικ με πορτοκάλι, ελαιόλαδο και τραγανή ζαχαρένια κρούστα.',
                ],
                'category' => 'Baking',
                'tags' => ['baking', 'vegetarian'],
                'prep_minutes' => 15,
                'cook_minutes' => 45,
                'servings' => 8,
                'difficulty' => 'Easy',
                'image_url' => 'https://images.unsplash.com/photo-1519869325930-281384150729?auto=format&fit=crop&w=1200&q=85',
                'ingredients' => ['200 g caster sugar', '3 eggs', '150 ml fruity olive oil', '200 g plain flour', '2 oranges, zested and juiced', '1 tsp baking powder'],
                'instructions' => ['Heat the oven to 175°C / 350°F and line a loaf tin with baking paper.', 'Whisk sugar and eggs until pale. Slowly whisk in the olive oil, orange zest and juice.', 'Fold in flour and baking powder. Bake for 45 minutes, until a skewer comes out clean.'],
                'is_featured' => false,
            ],
            [
                'en' => [
                    'title' => 'Green goddess potato salad',
                    'slug' => 'green-goddess-potato-salad',
                    'summary' => 'Warm new potatoes dressed in a punchy, herb-packed dressing with capers and crunch.',
                    'introduction' => 'A generous bowl for picnics, lunches and all the in-between meals of a sunny weekend.',
                    'seo_title' => 'Green goddess potato salad',
                    'seo_description' => 'Warm potatoes with a punchy green herb dressing, capers and plenty of crunch.',
                ],
                'el' => [
                    'title' => 'Πατατοσαλάτα με πράσινα μυρωδικά',
                    'slug' => 'green-goddess-potato-salad-el',
                    'summary' => 'Ζεστές πατάτες με ζωηρή σάλτσα γεμάτη μυρωδικά, κάπαρη και τραγανή υφή.',
                    'introduction' => 'Ένα γενναιόδωρο μπολ για πικνίκ, μεσημεριανά και όλα τα μικρά γεύματα ενός ηλιόλουστου Σαββατοκύριακου.',
                    'seo_title' => 'Πατατοσαλάτα με πράσινα μυρωδικά',
                    'seo_description' => 'Ζεστές πατάτες με σάλτσα μυρωδικών, κάπαρη και τραγανή υφή.',
                ],
                'category' => 'Vegetarian',
                'tags' => ['vegetarian', 'gluten-free'],
                'prep_minutes' => 15,
                'cook_minutes' => 20,
                'servings' => 4,
                'difficulty' => 'Easy',
                'image_url' => 'https://images.unsplash.com/photo-1518977676601-b53f82aba655?auto=format&fit=crop&w=1200&q=85',
                'ingredients' => ['750 g baby potatoes', '100 g Greek yogurt', '1 small bunch parsley', '1 small bunch chives', '2 tbsp capers', '1 tbsp Dijon mustard'],
                'instructions' => ['Boil the potatoes in well-salted water until tender, about 20 minutes.', 'Blend yogurt, herbs, mustard, capers and a splash of water into a vivid green dressing.', 'Drain the potatoes, halve while warm and fold through the dressing. Season generously.'],
                'is_featured' => false,
            ],
            [
                'en' => [
                    'title' => 'Cardamom pear breakfast buns',
                    'slug' => 'cardamom-pear-breakfast-buns',
                    'summary' => 'Soft, spiced buns with buttery pear tucked between the layers. Best eaten warm.',
                    'introduction' => 'A lazy-morning bake that fills the kitchen with cardamom and gives you a very good reason to put the kettle on.',
                    'seo_title' => 'Cardamom pear breakfast buns',
                    'seo_description' => 'Soft breakfast buns with buttery pear and cardamom, best enjoyed warm.',
                ],
                'el' => [
                    'title' => 'Πρωινά ψωμάκια με κάρδαμο και αχλάδι',
                    'slug' => 'cardamom-pear-breakfast-buns-el',
                    'summary' => 'Αφράτα, αρωματικά ψωμάκια με βουτυράτο αχλάδι ανάμεσα στις στρώσεις. Απολαύστε τα ζεστά.',
                    'introduction' => 'Ένα χαλαρό πρωινό ψήσιμο που γεμίζει την κουζίνα με άρωμα κάρδαμου και δίνει τέλεια αφορμή να βράσετε νερό για τσάι.',
                    'seo_title' => 'Πρωινά ψωμάκια με κάρδαμο και αχλάδι',
                    'seo_description' => 'Αφράτα ψωμάκια πρωινού με αχλάδι και κάρδαμο, ιδανικά ζεστά.',
                ],
                'category' => 'Brunch',
                'tags' => ['baking', 'vegetarian'],
                'prep_minutes' => 25,
                'cook_minutes' => 30,
                'servings' => 8,
                'difficulty' => 'A little project',
                'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=1200&q=85',
                'ingredients' => ['500 g strong white flour', '7 g instant yeast', '250 ml warm milk', '75 g soft butter', '2 ripe pears, diced', '1 tsp ground cardamom'],
                'instructions' => ['Mix flour, yeast, milk, butter and a pinch of salt into a soft dough. Knead for 8 minutes.', 'Leave covered for 1 hour, until doubled. Roll out, scatter over pear and cardamom, then roll up.', 'Cut into 8 buns and bake at 190°C / 375°F for 30 minutes until golden.'],
                'is_featured' => false,
            ],
        ];

        foreach ($recipes as $recipeData) {
            $tagCodes = $recipeData['tags'];
            unset($recipeData['tags']);

            $recipe = Recipe::query()
                ->whereTranslation('slug', $recipeData['en']['slug'], 'en')
                ->first() ?? new Recipe;

            if (! $recipe->exists) {
                $recipe->author()->associate($author);
            }

            $recipe->fill([
                ...$recipeData,
                'status' => 'published',
                'rest_minutes' => 0,
                'base_servings' => $recipeData['servings'],
                'published_at' => now(),
            ]);
            $recipe->save();

            $category = Category::query()
                ->where('code', Str::slug($recipeData['category']))
                ->firstOrFail();

            $recipe->categories()->syncWithoutDetaching([$category->getKey()]);

            foreach ($tagCodes as $tagCode) {
                $tag = Tag::query()->where('code', $tagCode)->firstOrFail();
                $recipe->tags()->syncWithoutDetaching([$tag->getKey()]);
            }
        }
    }
}
