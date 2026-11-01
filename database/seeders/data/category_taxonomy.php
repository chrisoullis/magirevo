<?php

/*
 * Default recipe category taxonomy: 12 parent categories with one level of subcategories.
 * `code` is the stable identifier used by CategorySeeder; names and slugs are per locale.
 */

return [
    ['code' => 'breakfast_brunch', 'name' => ['en' => 'Breakfast & Brunch', 'el' => 'Πρωινό & Μπραντς'], 'slug' => ['en' => 'breakfast-brunch', 'el' => 'proino-mprants'], 'sort_order' => 10, 'is_active' => true, 'children' => [
        ['code' => 'eggs_omelettes', 'name' => ['en' => 'Eggs & Omelettes', 'el' => 'Αυγά & Ομελέτες'], 'slug' => ['en' => 'eggs-omelettes', 'el' => 'augha-omeletes'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'pancakes_crepes', 'name' => ['en' => 'Pancakes & Crepes', 'el' => 'Τηγανίτες & Κρέπες'], 'slug' => ['en' => 'pancakes-crepes', 'el' => 'tighanites-krepes'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'breakfast_bowls', 'name' => ['en' => 'Breakfast Bowls', 'el' => 'Μπολ Πρωινού'], 'slug' => ['en' => 'breakfast-bowls', 'el' => 'mpol-proinou'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'breakfast_pastries', 'name' => ['en' => 'Breakfast Pastries', 'el' => 'Αρτοσκευάσματα Πρωινού'], 'slug' => ['en' => 'breakfast-pastries', 'el' => 'artoskefasmata-proinou'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'appetizers_snacks', 'name' => ['en' => 'Appetizers & Snacks', 'el' => 'Ορεκτικά & Σνακ'], 'slug' => ['en' => 'appetizers-snacks', 'el' => 'orektika-snak'], 'sort_order' => 20, 'is_active' => true, 'children' => [
        ['code' => 'cold_appetizers', 'name' => ['en' => 'Cold Appetizers', 'el' => 'Κρύα Ορεκτικά'], 'slug' => ['en' => 'cold-appetizers', 'el' => 'krya-orektika'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'hot_appetizers', 'name' => ['en' => 'Hot Appetizers', 'el' => 'Ζεστά Ορεκτικά'], 'slug' => ['en' => 'hot-appetizers', 'el' => 'zesta-orektika'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'finger_food', 'name' => ['en' => 'Finger Food', 'el' => 'Μικρές Μπουκιές'], 'slug' => ['en' => 'finger-food', 'el' => 'mikres-mpukies'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'savory_snacks', 'name' => ['en' => 'Savory Snacks', 'el' => 'Αλμυρά Σνακ'], 'slug' => ['en' => 'savory-snacks', 'el' => 'almira-snak'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'soups', 'name' => ['en' => 'Soups', 'el' => 'Σούπες'], 'slug' => ['en' => 'soups', 'el' => 'soupes'], 'sort_order' => 30, 'is_active' => true, 'children' => [
        ['code' => 'creamy_soups', 'name' => ['en' => 'Creamy Soups', 'el' => 'Σούπες Βελουτέ'], 'slug' => ['en' => 'creamy-soups', 'el' => 'soupes-belute'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'broth_based_soups', 'name' => ['en' => 'Broth-Based Soups', 'el' => 'Σούπες με Ζωμό'], 'slug' => ['en' => 'broth-based-soups', 'el' => 'soupes-me-zomo'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'legume_soups', 'name' => ['en' => 'Legume Soups', 'el' => 'Σούπες Οσπρίων'], 'slug' => ['en' => 'legume-soups', 'el' => 'soupes-osprion'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'cold_soups', 'name' => ['en' => 'Cold Soups', 'el' => 'Κρύες Σούπες'], 'slug' => ['en' => 'cold-soups', 'el' => 'kryes-soupes'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'salads', 'name' => ['en' => 'Salads', 'el' => 'Σαλάτες'], 'slug' => ['en' => 'salads', 'el' => 'salates'], 'sort_order' => 40, 'is_active' => true, 'children' => [
        ['code' => 'green_vegetable_salads', 'name' => ['en' => 'Green & Vegetable Salads', 'el' => 'Πράσινες Σαλάτες & Λαχανικών'], 'slug' => ['en' => 'green-vegetable-salads', 'el' => 'prasines-salates-lakhanikwn'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'pasta_grain_salads', 'name' => ['en' => 'Pasta & Grain Salads', 'el' => 'Σαλάτες Ζυμαρικών & Δημητριακών'], 'slug' => ['en' => 'pasta-grain-salads', 'el' => 'salates-zimarikwn-dimitriakwn'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'legume_salads', 'name' => ['en' => 'Legume Salads', 'el' => 'Σαλάτες Οσπρίων'], 'slug' => ['en' => 'legume-salads', 'el' => 'salates-osprion'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'main_course_salads', 'name' => ['en' => 'Main-Course Salads', 'el' => 'Χορταστικές Σαλάτες'], 'slug' => ['en' => 'main-course-salads', 'el' => 'xortastikes-salates'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'main_dishes', 'name' => ['en' => 'Main Dishes', 'el' => 'Κυρίως Πιάτα'], 'slug' => ['en' => 'main-dishes', 'el' => 'kirios-piata'], 'sort_order' => 50, 'is_active' => true, 'children' => [
        ['code' => 'chicken_poultry', 'name' => ['en' => 'Chicken & Poultry', 'el' => 'Κοτόπουλο & Πουλερικά'], 'slug' => ['en' => 'chicken-poultry', 'el' => 'kotopulo-pulerika'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'beef_veal', 'name' => ['en' => 'Beef & Veal', 'el' => 'Μοσχάρι & Βοδινό'], 'slug' => ['en' => 'beef-veal', 'el' => 'moskhari-bodino'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'pork', 'name' => ['en' => 'Pork', 'el' => 'Χοιρινό'], 'slug' => ['en' => 'pork', 'el' => 'xirino'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'lamb_goat', 'name' => ['en' => 'Lamb & Goat', 'el' => 'Αρνί & Κατσίκι'], 'slug' => ['en' => 'lamb-goat', 'el' => 'arni-katsiki'], 'sort_order' => 40, 'is_active' => true],
        ['code' => 'fish', 'name' => ['en' => 'Fish', 'el' => 'Ψάρια'], 'slug' => ['en' => 'fish', 'el' => 'psaria'], 'sort_order' => 50, 'is_active' => true],
        ['code' => 'seafood', 'name' => ['en' => 'Seafood', 'el' => 'Θαλασσινά'], 'slug' => ['en' => 'seafood', 'el' => 'thalassina'], 'sort_order' => 60, 'is_active' => true],
        ['code' => 'pasta_noodles', 'name' => ['en' => 'Pasta & Noodles', 'el' => 'Ζυμαρικά & Νουντλς'], 'slug' => ['en' => 'pasta-noodles', 'el' => 'zimarika-nuntls'], 'sort_order' => 70, 'is_active' => true],
        ['code' => 'rice_grains', 'name' => ['en' => 'Rice & Grains', 'el' => 'Ρύζι & Δημητριακά'], 'slug' => ['en' => 'rice-grains', 'el' => 'ryzi-dimitriaka'], 'sort_order' => 80, 'is_active' => true],
        ['code' => 'legume_dishes', 'name' => ['en' => 'Legume Dishes', 'el' => 'Φαγητά με Όσπρια'], 'slug' => ['en' => 'legume-dishes', 'el' => 'faghita-me-ospria'], 'sort_order' => 90, 'is_active' => true],
        ['code' => 'vegetable_dishes', 'name' => ['en' => 'Vegetable Dishes', 'el' => 'Λαδερά & Φαγητά Λαχανικών'], 'slug' => ['en' => 'vegetable-dishes', 'el' => 'ladera-faghita-lakhanikwn'], 'sort_order' => 100, 'is_active' => true],
    ]],
    ['code' => 'side_dishes', 'name' => ['en' => 'Side Dishes', 'el' => 'Συνοδευτικά'], 'slug' => ['en' => 'side-dishes', 'el' => 'sinodeftika'], 'sort_order' => 60, 'is_active' => true, 'children' => [
        ['code' => 'potato_sides', 'name' => ['en' => 'Potato Sides', 'el' => 'Συνοδευτικά Πατάτας'], 'slug' => ['en' => 'potato-sides', 'el' => 'sinodeftika-patatas'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'vegetable_sides', 'name' => ['en' => 'Vegetable Sides', 'el' => 'Συνοδευτικά Λαχανικών'], 'slug' => ['en' => 'vegetable-sides', 'el' => 'sinodeftika-lakhanikwn'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'rice_grain_sides', 'name' => ['en' => 'Rice & Grain Sides', 'el' => 'Συνοδευτικά Ρυζιού & Δημητριακών'], 'slug' => ['en' => 'rice-grain-sides', 'el' => 'sinodeftika-riziou-dimitriakwn'], 'sort_order' => 30, 'is_active' => true],
    ]],
    ['code' => 'savory_pies_pizza', 'name' => ['en' => 'Savory Pies & Pizza', 'el' => 'Αλμυρές Πίτες & Πίτσα'], 'slug' => ['en' => 'savory-pies-pizza', 'el' => 'almires-pites-pitsa'], 'sort_order' => 70, 'is_active' => true, 'children' => [
        ['code' => 'traditional_savory_pies', 'name' => ['en' => 'Traditional Savory Pies', 'el' => 'Παραδοσιακές Αλμυρές Πίτες'], 'slug' => ['en' => 'traditional-savory-pies', 'el' => 'paradosiakes-almires-pites'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'savory_tarts_quiches', 'name' => ['en' => 'Savory Tarts & Quiches', 'el' => 'Αλμυρές Τάρτες & Κις'], 'slug' => ['en' => 'savory-tarts-quiches', 'el' => 'almires-tartes-kis'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'small_savory_pies', 'name' => ['en' => 'Small Savory Pies', 'el' => 'Αλμυρά Πιτάκια'], 'slug' => ['en' => 'small-savory-pies', 'el' => 'almira-pitakia'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'pizza', 'name' => ['en' => 'Pizza', 'el' => 'Πίτσα'], 'slug' => ['en' => 'pizza', 'el' => 'pitsa'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'bread_dough', 'name' => ['en' => 'Bread & Dough', 'el' => 'Ψωμιά & Ζύμες'], 'slug' => ['en' => 'bread-dough', 'el' => 'psomia-zymes'], 'sort_order' => 80, 'is_active' => true, 'children' => [
        ['code' => 'bread', 'name' => ['en' => 'Bread', 'el' => 'Ψωμιά'], 'slug' => ['en' => 'bread', 'el' => 'psomia'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'bread_rolls_buns', 'name' => ['en' => 'Bread Rolls & Buns', 'el' => 'Ψωμάκια'], 'slug' => ['en' => 'bread-rolls-buns', 'el' => 'psomakia'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'flatbreads', 'name' => ['en' => 'Flatbreads', 'el' => 'Πλακέ Πίτες'], 'slug' => ['en' => 'flatbreads', 'el' => 'plake-pites'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'basic_dough_recipes', 'name' => ['en' => 'Basic Dough Recipes', 'el' => 'Βασικές Ζύμες'], 'slug' => ['en' => 'basic-dough-recipes', 'el' => 'basikes-zymes'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'desserts_sweets', 'name' => ['en' => 'Desserts & Sweets', 'el' => 'Γλυκά & Επιδόρπια'], 'slug' => ['en' => 'desserts-sweets', 'el' => 'glika-epidorpia'], 'sort_order' => 90, 'is_active' => true, 'children' => [
        ['code' => 'cakes_muffins', 'name' => ['en' => 'Cakes & Muffins', 'el' => 'Κέικ & Μάφιν'], 'slug' => ['en' => 'cakes-muffins', 'el' => 'keik-mafin'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'cookies_biscuits', 'name' => ['en' => 'Cookies & Biscuits', 'el' => 'Μπισκότα & Κουλουράκια'], 'slug' => ['en' => 'cookies-biscuits', 'el' => 'mpiskota-kulurakia'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'cheesecakes', 'name' => ['en' => 'Cheesecakes', 'el' => 'Τσιζκέικ'], 'slug' => ['en' => 'cheesecakes', 'el' => 'tsizkeik'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'sweet_pies_tarts', 'name' => ['en' => 'Sweet Pies & Tarts', 'el' => 'Γλυκές Πίτες & Τάρτες'], 'slug' => ['en' => 'sweet-pies-tarts', 'el' => 'glikes-pites-tartes'], 'sort_order' => 40, 'is_active' => true],
        ['code' => 'creams_mousses', 'name' => ['en' => 'Creams & Mousses', 'el' => 'Κρέμες & Μους'], 'slug' => ['en' => 'creams-mousses', 'el' => 'kremes-mus'], 'sort_order' => 50, 'is_active' => true],
        ['code' => 'no_bake_desserts', 'name' => ['en' => 'No-Bake Desserts', 'el' => 'Γλυκά Χωρίς Ψήσιμο'], 'slug' => ['en' => 'no-bake-desserts', 'el' => 'glika-xoris-psisimo'], 'sort_order' => 60, 'is_active' => true],
        ['code' => 'ice_cream_frozen_desserts', 'name' => ['en' => 'Ice Cream & Frozen Desserts', 'el' => 'Παγωτά & Παγωμένα Γλυκά'], 'slug' => ['en' => 'ice-cream-frozen-desserts', 'el' => 'paghota-paghomena-glika'], 'sort_order' => 70, 'is_active' => true],
        ['code' => 'traditional_syrup_desserts', 'name' => ['en' => 'Traditional & Syrup Desserts', 'el' => 'Παραδοσιακά & Σιροπιαστά Γλυκά'], 'slug' => ['en' => 'traditional-syrup-desserts', 'el' => 'paradosiaka-siropiasta-glika'], 'sort_order' => 80, 'is_active' => true],
    ]],
    ['code' => 'sauces_dips', 'name' => ['en' => 'Sauces & Dips', 'el' => 'Σάλτσες & Ντιπ'], 'slug' => ['en' => 'sauces-dips', 'el' => 'saltses-ntip'], 'sort_order' => 100, 'is_active' => true, 'children' => [
        ['code' => 'cooking_sauces', 'name' => ['en' => 'Cooking Sauces', 'el' => 'Σάλτσες Μαγειρικής'], 'slug' => ['en' => 'cooking-sauces', 'el' => 'saltses-maghirikis'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'dips_spreads', 'name' => ['en' => 'Dips & Spreads', 'el' => 'Ντιπ & Αλείμματα'], 'slug' => ['en' => 'dips-spreads', 'el' => 'ntip-alimmata'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'dressings_vinaigrettes', 'name' => ['en' => 'Dressings & Vinaigrettes', 'el' => 'Ντρέσινγκ & Βινεγκρέτ'], 'slug' => ['en' => 'dressings-vinaigrettes', 'el' => 'ntresinghk-bineghkret'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'marinades_spice_mixes', 'name' => ['en' => 'Marinades & Spice Mixes', 'el' => 'Μαρινάδες & Μείγματα Μπαχαρικών'], 'slug' => ['en' => 'marinades-spice-mixes', 'el' => 'marinades-mighmata-mpakharikwn'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'drinks', 'name' => ['en' => 'Drinks', 'el' => 'Ροφήματα & Ποτά'], 'slug' => ['en' => 'drinks', 'el' => 'rofimata-pota'], 'sort_order' => 110, 'is_active' => true, 'children' => [
        ['code' => 'coffee_tea', 'name' => ['en' => 'Coffee & Tea', 'el' => 'Καφές & Τσάι'], 'slug' => ['en' => 'coffee-tea', 'el' => 'kafes-tsai'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'smoothies_milkshakes', 'name' => ['en' => 'Smoothies & Milkshakes', 'el' => 'Σμούθι & Μιλκσέικ'], 'slug' => ['en' => 'smoothies-milkshakes', 'el' => 'smouthi-milkseik'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'juices_lemonades', 'name' => ['en' => 'Juices & Lemonades', 'el' => 'Χυμοί & Λεμονάδες'], 'slug' => ['en' => 'juices-lemonades', 'el' => 'ximi-lemonades'], 'sort_order' => 30, 'is_active' => true],
        ['code' => 'cocktails_mocktails', 'name' => ['en' => 'Cocktails & Mocktails', 'el' => 'Κοκτέιλ με ή χωρίς Αλκοόλ'], 'slug' => ['en' => 'cocktails-mocktails', 'el' => 'kokteil-me-i-khoris-alkool'], 'sort_order' => 40, 'is_active' => true],
    ]],
    ['code' => 'preserves_homemade_basics', 'name' => ['en' => 'Preserves & Homemade Basics', 'el' => 'Σπιτικές Παρασκευές & Συντηρήσεις'], 'slug' => ['en' => 'preserves-homemade-basics', 'el' => 'spitikes-paraskefes-sintirisis'], 'sort_order' => 120, 'is_active' => true, 'children' => [
        ['code' => 'jams_fruit_preserves', 'name' => ['en' => 'Jams & Fruit Preserves', 'el' => 'Μαρμελάδες & Γλυκά του Κουταλιού'], 'slug' => ['en' => 'jams-fruit-preserves', 'el' => 'marmelades-glika-tu-kutaliou'], 'sort_order' => 10, 'is_active' => true],
        ['code' => 'pickles_ferments', 'name' => ['en' => 'Pickles & Ferments', 'el' => 'Τουρσιά & Ζυμώσεις'], 'slug' => ['en' => 'pickles-ferments', 'el' => 'tursia-zimwsis'], 'sort_order' => 20, 'is_active' => true],
        ['code' => 'stocks_cooking_basics', 'name' => ['en' => 'Stocks & Cooking Basics', 'el' => 'Ζωμοί & Βασικές Παρασκευές'], 'slug' => ['en' => 'stocks-cooking-basics', 'el' => 'zomi-basikes-paraskefes'], 'sort_order' => 30, 'is_active' => true],
    ]],
];
