<?php
/**
 * menu_public.php — the restaurant menu as the public site publishes it.
 *
 * The dishes and their prices already exist in one place
 * (portal/includes/menu_data.php, which the guest portal renders). This file is
 * the second half of that arrangement: it decides what the *public* page shows,
 * in what order, with which photograph, and what it says about portion size.
 * Prices are read from the portal catalogue by dish name, never retyped, so the
 * two can never quote different figures for the same plate.
 *
 * Three rules hold this together, and `obsidian brain/07 Testing/scripts/
 * menu_data_checks.php` enforces all three:
 *
 *   1. Every dish named below exists in the catalogue. A typo is reported, not
 *      silently dropped.
 *   2. Every dish in the catalogue is either published here or listed in
 *      $publicMenuWithheld with the reason — so nothing goes missing by accident.
 *   3. Every published dish has a Tagalog description and a serving size.
 *
 * ── Editing the menu ─────────────────────────────────────────────────────────
 * To change a portion, edit its preset in $publicMenuServes. To hide a dish,
 * move its name into $publicMenuWithheld. To add one, add it to a section below
 * and give it an entry in $publicMenuItems. To use a real photograph from the
 * Marle kitchen, drop it into pics/menu/ as {slug}.jpg and {slug}.webp, point
 * the dish's 'photo' at that slug, and delete the slug from $publicMenuPhotos so
 * the stock-photo credit line disappears.
 *
 * Requires: nothing but the catalogue it includes itself.
 * Provides: $publicMenuSections, $publicMenuSignature, $publicMenuProblems,
 *           $publicMenuWithheld, $publicMenuPhotos, $publicMenuServes.
 */

require_once dirname(__DIR__, 2) . '/portal/includes/menu_data.php';

if (!isset($menuItems) || !is_array($menuItems)) {
    // The catalogue is part of the checkout, so this is a broken deployment
    // rather than a normal state. The page prints no menu instead of a fatal.
    $publicMenuSections  = [];
    $publicMenuSignature = [];
    $publicMenuProblems  = ['portal/includes/menu_data.php did not provide $menuItems'];
    return;
}

// ── Prices and the portal's own descriptions, by dish name ──────────────────
$publicMenuPrice = [];
$publicMenuDesc  = [];
foreach ($menuItems as $menuItem) {
    $menuItemName = trim((string)($menuItem['name'] ?? ''));
    if ($menuItemName === '') {
        continue;
    }
    $publicMenuPrice[$menuItemName] = (int)($menuItem['price'] ?? 0);
    $publicMenuDesc[$menuItemName]  = (string)($menuItem['desc'] ?? '');
}

// ── Portion sizes ───────────────────────────────────────────────────────────
// The portal catalogue records no serving size, so the public page says it in
// words. These are deliberately conservative — a filipino sharing dish is not a
// single plate — and they are here, in one table, precisely because they are an
// estimate the hotel should correct.
$publicMenuServes = [
    'one'        => ['Serves 1',      'Para sa 1'],
    'two'        => ['Good for 2',    'Para sa 2'],
    'two-three'  => ['Good for 2–3',  'Para sa 2–3'],
    'three-four' => ['Good for 3–4',  'Para sa 3–4'],
    'glass'      => ['One glass',     'Isang baso'],
    'bottle'     => ['One bottle',    'Isang bote'],
];

// ── The photographs, and the credit each one legally requires ───────────────
// Cropped to 640×480 and re-encoded; sources and licences are recorded in
// pics/menu/CREDITS.md. A dish with no entry here simply prints as a line.
$publicMenuPhotos = [
    'adobo' => [
        'jpg' => 'adobo', 'alt' => ['Chicken adobo simmered in soy and vinegar', 'Adobong manok sa toyo at suka'],
        'by' => 'dbgg1979 on flickr', 'licence' => 'CC BY 2.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Chicken_adobo.jpg',
    ],
    'kare-kare' => [
        'jpg' => 'kare-kare', 'alt' => ['Kare-kare with oxtail, vegetables and bagoong', 'Kare-kare na may buntot ng baka, gulay at bagoong'],
        'by' => 'Ymirazudis', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Kare-kare_at_La_Herencia_Comida.jpg',
    ],
    'sinigang' => [
        'jpg' => 'sinigang', 'alt' => ['Prawn sinigang in a bowl', 'Sinigang na hipon sa mangkok'],
        'by' => 'Ralff Nestor Nacor', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Sinigang_na_Hipon_in_bowl,_Aug_2024.jpg',
    ],
    'lumpiang-shanghai' => [
        'jpg' => 'lumpiang-shanghai', 'alt' => ['Lumpiang Shanghai on a banana leaf', 'Lumpiang Shanghai sa dahon ng saging'],
        'by' => 'Ralff Nestor Nacor', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Lumpiang_Shanghai,_Aug_2024.jpg',
    ],
    'chicken-inasal' => [
        'jpg' => 'chicken-inasal', 'alt' => ['Chicken inasal with rice and dipping sauce', 'Inihaw na manok na may kanin at sawsawan'],
        'by' => 'Ralff Nestor Nacor', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Chicken_Inasal,_Mar_2024.jpg',
    ],
    'sisig' => [
        'jpg' => 'sisig', 'alt' => ['Sizzling sisig with egg on a hot plate', 'Sisig sa mainit na plato, may itlog'],
        'by' => 'Ej Afable', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Sizzling_Sisig.jpg',
    ],
    'bulalo' => [
        'jpg' => 'bulalo', 'alt' => ['Bulalo beef marrow soup in a hot pot', 'Bulalo sa hot pot'],
        'by' => 'LReformado', 'licence' => 'CC BY 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:BULALO.jpg',
    ],
    'halo-halo' => [
        'jpg' => 'halo-halo', 'alt' => ['Halo-halo in a tall glass', 'Halo-halo sa baso'],
        'by' => 'Chammy17', 'licence' => 'CC BY-SA 4.0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Halo-halo_at_mama_nang%27s_halo-halo.jpg',
    ],
    'leche-flan' => [
        'jpg' => 'leche-flan', 'alt' => ['Leche flan sliced in a dish', 'Leche flan na hiniwa sa plato'],
        'by' => 'Obsidian Soul', 'licence' => 'CC0',
        'href' => 'https://commons.wikimedia.org/wiki/File:Leche_flan_(Philippines)_01.jpg',
    ],
];

// ── What the kitchen says about each dish ───────────────────────────────────
// tl    — the Tagalog description (the catalogue's English one is used as-is)
// serves— a key in $publicMenuServes
// photo — a key in $publicMenuPhotos, for the signature dishes
// veg   — a leaf mark beside the name
$publicMenuItems = [
    // Filipino
    'Adobo'             => ['tl' => 'Manok o baboy sa pinaghalong toyo at suka.', 'serves' => 'two-three', 'photo' => 'adobo', 'veg' => false],
    'Kare-Kare'         => ['tl' => 'Nilagang may peanut butter, sinasabayan ng bagoong.', 'serves' => 'three-four', 'photo' => 'kare-kare', 'veg' => false],
    'Prawn Sinigang'    => ['tl' => 'Sabaw ng sampalok, malalaking hipon, kangkong at labanos.', 'serves' => 'two-three', 'photo' => 'sinigang', 'veg' => false],
    'Bulalo'            => ['tl' => 'Mainit na sabaw ng baka at utak ng buto.', 'serves' => 'three-four', 'photo' => 'bulalo', 'veg' => false],
    'Sisig'             => ['tl' => 'Tinadtad na baboy na may itlog, sa mainit na plato.', 'serves' => 'two-three', 'photo' => 'sisig', 'veg' => false],
    'Chicken Inasal'    => ['tl' => 'Inihaw na manok, may atsara at kanin.', 'serves' => 'one', 'photo' => 'chicken-inasal', 'veg' => false],
    'Beef Tapa'         => ['tl' => 'May sinangag at itlog.', 'serves' => 'one', 'veg' => false],
    'Lumpiang Shanghai' => ['tl' => 'Maliliit na lumpiang baboy, may matamis na sarsa.', 'serves' => 'two-three', 'photo' => 'lumpiang-shanghai', 'veg' => false],
    'Laing'             => ['tl' => 'Dahon ng gabi sa malinamnam na gata.', 'serves' => 'two', 'veg' => false],
    'Tofu Sisig'        => ['tl' => 'Walang karne, pero kaparehong sarap ng klasiko.', 'serves' => 'two', 'veg' => true],

    // Appetizers
    'Calamari'          => ['tl' => 'Malutong na pusit, may garlic aioli.', 'serves' => 'two', 'veg' => false],
    'Chicken Wings'     => ['tl' => 'Pumili ng buffalo, honey garlic o soy garlic.', 'serves' => 'two', 'veg' => false],
    'Bruschetta'        => ['tl' => 'Inihaw na tinapay, kamatis at basil.', 'serves' => 'two', 'veg' => true],
    'Mozzarella Sticks' => ['tl' => 'May kasamang marinara sauce.', 'serves' => 'two', 'veg' => true],
    'Burrata Garden Salad' => ['tl' => 'Sariwang burrata, kamatis, basil at balsamiko.', 'serves' => 'one', 'veg' => true],
    'Caprese Salad'     => ['tl' => 'Kamatis, sariwang basil at mozzarella.', 'serves' => 'one', 'veg' => true],

    // Mains
    'Chicken Alfredo'   => ['tl' => 'Pasta sa creaming puting sarsa.', 'serves' => 'one', 'veg' => false],
    'Grilled Pork Chop' => ['tl' => 'May mashed potato o kanin.', 'serves' => 'one', 'veg' => false],
    'Fish and Chips'    => ['tl' => 'Piniritong isda na may harina, kasama ang fries.', 'serves' => 'one', 'veg' => false],
    'Spaghetti Bolognese' => ['tl' => 'Malinamnam na sarsa ng karne, pinakuluan nang matagal.', 'serves' => 'one', 'veg' => false],

    // Vegetarian
    'Vegetable Stir Fry' => ['tl' => 'Iba\'t ibang gulay sa magaan na toyo.', 'serves' => 'two', 'veg' => true],
    'Mushroom Risotto'  => ['tl' => 'Malapot na arborio rice na may kabute.', 'serves' => 'one', 'veg' => true],
    'Vegetable Curry'   => ['tl' => 'Masustansyang curry sa gata ng niyog.', 'serves' => 'two', 'veg' => true],

    // Desserts
    'Classic Leche Flan' => ['tl' => 'Makinis na leche flan, may itim na caramel at vanilla.', 'serves' => 'one', 'photo' => 'leche-flan', 'veg' => false],
    'Halo-Halo'         => ['tl' => 'Yelo, leche flan at ube.', 'serves' => 'one', 'photo' => 'halo-halo', 'veg' => false],
    'Chocolate Lava Cake' => ['tl' => 'Mainit na chocolate cake, malambot ang loob.', 'serves' => 'one', 'veg' => false],
    'Cheesecake'        => ['tl' => 'Klasikong New York style o blueberry.', 'serves' => 'one', 'veg' => false],
    'Banana Split'      => ['tl' => 'Ice cream sundae na pwedeng hatiin.', 'serves' => 'two', 'veg' => false],
    'Ube Ice Cream'     => ['tl' => 'Sorbetes na ube, simple pero paborito.', 'serves' => 'one', 'veg' => false],

    // Drinks
    'Coca-Cola'         => ['tl' => 'Klasikong pampalamig na cola.', 'serves' => 'glass', 'veg' => false],
    'Sprite'            => ['tl' => 'Malamig na lemon-lime soda.', 'serves' => 'glass', 'veg' => false],
    'Iced Tea'          => ['tl' => 'Lemon o peach ang piliin.', 'serves' => 'glass', 'veg' => false],
    'Fresh Lemonade'    => ['tl' => 'Sariwang pinigang limon.', 'serves' => 'glass', 'veg' => false],
    'Four Seasons Juice' => ['tl' => 'Pinaghalong juice ng tropikal na prutas.', 'serves' => 'glass', 'veg' => false],
    'Brewed Coffee'     => ['tl' => 'Bagong roasting araw-araw.', 'serves' => 'glass', 'veg' => false],
    'Cappuccino'        => ['tl' => 'Espresso na may steamed milk foam.', 'serves' => 'glass', 'veg' => false],
    'Cafe Latte'        => ['tl' => 'Espresso na may steamed gatas.', 'serves' => 'glass', 'veg' => false],
    'Hot Chocolate'     => ['tl' => 'Malapot at creaming tsokolate.', 'serves' => 'glass', 'veg' => false],
    'Green Tea'         => ['tl' => 'Mainit at nakakapagpalubag ng loob na tsaa.', 'serves' => 'glass', 'veg' => false],
    'Iced Coffee'       => ['tl' => 'Malamig na brewed coffee.', 'serves' => 'glass', 'veg' => false],
    'Iced Latte'        => ['tl' => 'Espresso at gatas sa yelo.', 'serves' => 'glass', 'veg' => false],
    'Caramel Macchiato' => ['tl' => 'Espresso, vanilla at caramel.', 'serves' => 'glass', 'veg' => false],
    'Mocha Frappuccino' => ['tl' => 'Blended na kape at tsokolate sa yelo.', 'serves' => 'glass', 'veg' => false],
    'Vanilla Milkshake' => ['tl' => 'Creaming shake ng vanilla ice cream.', 'serves' => 'glass', 'veg' => false],
    'Mojito'            => ['tl' => 'Mint, dayap at soda water.', 'serves' => 'glass', 'veg' => false],
    'Pina Colada'       => ['tl' => 'Pinya at niyog na mocktail.', 'serves' => 'glass', 'veg' => false],
    'Blue Lagoon'       => ['tl' => 'Citrus na may asul na syrup.', 'serves' => 'glass', 'veg' => false],
    'Strawberry Shake'  => ['tl' => 'Matamis na strawberry shake.', 'serves' => 'glass', 'veg' => false],
    'Mango Shake'       => ['tl' => 'Sariwang hinog na mangga, blended.', 'serves' => 'glass', 'veg' => false],
    'San Miguel Beer'   => ['tl' => 'Klasikong local na pale pilsen o light.', 'serves' => 'bottle', 'veg' => false],
    'Red Wine'          => ['tl' => 'House red wine, bawat baso.', 'serves' => 'glass', 'veg' => false],
    'White Wine'        => ['tl' => 'House white wine, bawat baso.', 'serves' => 'glass', 'veg' => false],
    'Margarita'         => ['tl' => 'Klasikong tequila cocktail.', 'serves' => 'glass', 'veg' => false],
    'Whiskey'           => ['tl' => 'Premium whiskey na may yelo.', 'serves' => 'glass', 'veg' => false],
];

// ── Deliberately not on the public menu ─────────────────────────────────────
// A dish the catalogue lists but the public page must not quote. Say why, so
// the next person does not read this as an oversight.
$publicMenuWithheld = [
    // ₱123 against ₱420 for prawn sinigang: a missing digit, not a price. It
    // comes back the moment the front desk confirms the figure.
    'Wagyu Beef Steak' => 'price in the portal catalogue looks like a placeholder (₱123)',
];

// ── The order the menu is read in ───────────────────────────────────────────
$publicMenuSpec = [
    [
        'slug' => 'filipino', 'en' => 'Filipino', 'tl' => 'Filipino',
        'intro' => ['The dishes the kitchen is known for.', 'Ang mga putaheng kilala ang kusina.'],
        'items' => ['Adobo', 'Kare-Kare', 'Prawn Sinigang', 'Bulalo', 'Sisig', 'Chicken Inasal',
                    'Beef Tapa', 'Lumpiang Shanghai', 'Laing', 'Tofu Sisig'],
    ],
    [
        'slug' => 'appetizers', 'en' => 'Appetizers', 'tl' => 'Pampagana',
        'intro' => ['Something to start the table.', 'Pampagana bago ang pangunahing putahe.'],
        'items' => ['Calamari', 'Chicken Wings', 'Bruschetta', 'Mozzarella Sticks',
                    'Burrata Garden Salad', 'Caprese Salad'],
    ],
    [
        'slug' => 'mains', 'en' => 'Mains', 'tl' => 'Pangunahing putahe',
        'intro' => ['Plated for one, cooked to order.', 'Para sa isa, niluluto kapag inorder.'],
        'items' => ['Chicken Alfredo', 'Grilled Pork Chop', 'Fish and Chips', 'Spaghetti Bolognese'],
    ],
    [
        'slug' => 'vegetarian', 'en' => 'Vegetarian', 'tl' => 'Gulay',
        'intro' => ['No meat, no compromise.', 'Walang karne, walang kompromiso.'],
        'items' => ['Vegetable Stir Fry', 'Mushroom Risotto', 'Vegetable Curry'],
    ],
    [
        'slug' => 'desserts', 'en' => 'Desserts', 'tl' => 'Panghimagas',
        'intro' => ['The sweet end of the meal.', 'Matamis na pangwakas ng kainan.'],
        'items' => ['Classic Leche Flan', 'Halo-Halo', 'Chocolate Lava Cake', 'Cheesecake',
                    'Banana Split', 'Ube Ice Cream'],
    ],
    [
        'slug' => 'drinks', 'en' => 'Drinks', 'tl' => 'Inumin',
        'intro' => ['Coffee, cold drinks and something stronger.', 'Kape, malamig na inumin, at mas matapang.'],
        'items' => ['Coca-Cola', 'Sprite', 'Iced Tea', 'Fresh Lemonade', 'Four Seasons Juice',
                    'Brewed Coffee', 'Cappuccino', 'Cafe Latte', 'Hot Chocolate', 'Green Tea',
                    'Iced Coffee', 'Iced Latte', 'Caramel Macchiato', 'Mocha Frappuccino',
                    'Vanilla Milkshake', 'Mojito', 'Pina Colada', 'Blue Lagoon', 'Strawberry Shake',
                    'Mango Shake', 'San Miguel Beer', 'Red Wine', 'White Wine', 'Margarita', 'Whiskey'],
    ],
];

// The photographs above the list, in the order they are shown. All nine are
// also present in their section, so filtering by section never hides them.
$publicMenuSignatureOrder = ['Adobo', 'Kare-Kare', 'Prawn Sinigang', 'Bulalo', 'Sisig',
                             'Chicken Inasal', 'Lumpiang Shanghai', 'Classic Leche Flan', 'Halo-Halo'];

// ── Assemble ────────────────────────────────────────────────────────────────
// Anything wrong is collected rather than thrown: a missing dish must not take
// the homepage down, and the check script reads this list.
$publicMenuProblems = [];
$publicMenuRows     = [];

$buildPublicMenuRow = static function (string $name, array $meta) use (
    $publicMenuPrice, $publicMenuDesc, $publicMenuServes, $publicMenuPhotos
): array {
    $servesKey = (string)($meta['serves'] ?? '');
    $serves    = $publicMenuServes[$servesKey] ?? $publicMenuServes['one'];
    $photoKey  = (string)($meta['photo'] ?? '');
    $photo     = null;
    if ($photoKey !== '' && isset($publicMenuPhotos[$photoKey])) {
        $p        = $publicMenuPhotos[$photoKey];
        $base     = 'pics/menu/' . $p['jpg'];
        $photo    = [
            'jpg'     => $base . '.jpg',
            'webp'    => $base . '.webp',
            'alt'     => $p['alt'],
            'by'      => $p['by'],
            'licence' => $p['licence'],
            'href'    => $p['href'],
        ];
    }

    return [
        'name'      => $name,
        'desc'      => [$publicMenuDesc[$name] ?? '', (string)($meta['tl'] ?? '')],
        'price'     => $publicMenuPrice[$name] ?? 0,
        'serves'    => $serves,
        'veg'       => (bool)($meta['veg'] ?? false),
        'photo'     => $photo,
    ];
};

$publicMenuSections = [];
foreach ($publicMenuSpec as $spec) {
    $rows = [];
    foreach ($spec['items'] as $name) {
        if (!isset($publicMenuPrice[$name])) {
            $publicMenuProblems[] = sprintf('%s: "%s" is not in the portal catalogue.', $spec['slug'], $name);
            continue;
        }
        if (!isset($publicMenuItems[$name])) {
            $publicMenuProblems[] = sprintf('%s: "%s" has no $publicMenuItems entry.', $spec['slug'], $name);
            continue;
        }
        if (($publicMenuItems[$name]['tl'] ?? '') === '') {
            $publicMenuProblems[] = sprintf('%s: "%s" has no Tagalog description.', $spec['slug'], $name);
        }
        $row = $buildPublicMenuRow($name, $publicMenuItems[$name]);
        if ($row['price'] <= 0) {
            $publicMenuProblems[] = sprintf('%s: "%s" has no price.', $spec['slug'], $name);
        }
        if ($row['photo'] !== null) {
            foreach ([$row['photo']['jpg'], $row['photo']['webp']] as $file) {
                if (!is_file(__DIR__ . '/../' . $file)) {
                    $publicMenuProblems[] = sprintf('missing photograph %s', $file);
                }
            }
        }
        $rows[$name] = $row;
        $publicMenuRows[$name] = $row;
    }
    $publicMenuSections[] = [
        'slug'  => $spec['slug'],
        'label' => [$spec['en'], $spec['tl']],
        'intro' => $spec['intro'],
        'items' => array_values($rows),
    ];
}

$publicMenuSignature = [];
foreach ($publicMenuSignatureOrder as $name) {
    if (isset($publicMenuRows[$name]) && $publicMenuRows[$name]['photo'] !== null) {
        $publicMenuSignature[] = $publicMenuRows[$name];
        continue;
    }
    $publicMenuProblems[] = sprintf('signature "%s" is not published with a photograph.', $name);
}

// ── Completeness: published + withheld must cover the catalogue ─────────────
foreach (array_keys($publicMenuPrice) as $name) {
    if (isset($publicMenuRows[$name]) || isset($publicMenuWithheld[$name])) {
        continue;
    }
    $publicMenuProblems[] = sprintf('"%s" is in the catalogue but not on the public menu.', $name);
}
