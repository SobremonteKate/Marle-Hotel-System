<?php
/**
 * room_tariff.php — the one place the public site learns what it may promise.
 *
 * Included by home.php and by the help concierge. Counts and sleeping
 * capacities come from the same table the booking engine uses, and prices are
 * the engine's own constants (includes/booking_pricing.php), so nothing typed
 * into a page can drift from the receipt.
 *
 * Requires: $conn (mysqli) and site_t() from includes/site_i18n.php.
 * Defines:  $roomOrder, $roomStats, $totalRooms, $roomMedia, $roomBlurbs,
 *           $roomRates, $ratesForJs, $peso, $minHourly, $minNightly,
 *           $roomLabel, $roomCards, $firstCard. Each card carries a `gallery`
 *           of four photographs for the room viewer (see below), the list of
 *           what is actually in that room, and the per-hour extension rate.
 */

if (isset($roomCards)) {
    return;
}

// ── Room inventory for the tariff folio ─────────────────────────────────────
// Counts and sleeping capacities come from the same table the booking engine
// uses, so the page cannot promise a room or a capacity the front desk does
// not have. If the query ever fails the folio still renders — with the types
// listed and the counts omitted — instead of taking the homepage down.
$roomOrder = ['Without Garage', 'With Garage', 'Suite', 'Family'];
$roomStats = [];
$statsStmt = $conn->prepare(
    "SELECT rooms AS room_type,
            COUNT(*) AS room_count,
            MAX(max_persons) AS sleeps,
            MIN(room_number) AS first_room
     FROM   roomstbl
     GROUP BY rooms"
);
if ($statsStmt) {
    $statsStmt->execute();
    $statsRes = $statsStmt->get_result();
    while ($statRow = $statsRes->fetch_assoc()) {
        $roomStats[$statRow['room_type']] = $statRow;
    }
    $statsStmt->close();
}
$totalRooms = 0;
foreach ($roomStats as $statRow) {
    $totalRooms += (int)$statRow['room_count'];
}

$roomMedia = [
    'Without Garage' => ['pics/withoutGarage.jpg', site_t('A standard room at Marle Hotel', 'Isang karaniwang kwarto sa Marle Hotel')],
    'With Garage'    => ['pics/withGarage.jpg',    site_t('A room with its own garage at Marle Hotel', 'Kwartong may sariling garahe sa Marle Hotel')],
    'Suite'          => ['pics/suite.jpg',         site_t('A suite at Marle Hotel', 'Isang suite sa Marle Hotel')],
    'Family'         => ['pics/family.png',        site_t('A family room at Marle Hotel', 'Isang family room sa Marle Hotel')],
];

$roomBlurbs = [
    'Without Garage' => [
        'A quiet, air-conditioned room with a double bed — the simplest way to stay the night in Silang.',
        'Tahimik at may aircon na kwarto na may double bed — ang pinakasimpleng paraan para magpalipas ng gabi sa Silang.',
    ],
    'With Garage' => [
        'The same comfortable room, with your car behind a private roller door.',
        'Parehong komportableng kwarto, may sariling garahe para sa iyong sasakyan.',
    ],
    'Suite' => [
        'More floor, a sitting corner, and a bathtub in selected suites.',
        'Mas maluwang, may sulok na upuan, at bathtub sa piling suite.',
    ],
    'Family' => [
        'Space for six, a few steps from the restaurant — built for reunions and long weekends.',
        'Kasya ang anim, ilang hakbang lang mula sa restaurant — para sa pagsasama-sama at mahabang weekend.',
    ],
];

// Prices are never typed into this page: they are the engine's own constants
// (includes/booking_pricing.php), so the tariff and the receipt agree.
$roomRates = [];
$ratesForJs = [];
foreach ($roomOrder as $type) {
    $roomRates[$type] = [
        'three_hour' => (float)(MARLE_BASE_RATE[$type] ?? 0),
        'extension'  => (float)(MARLE_EXTENSION_RATE[$type] ?? 0),
        'nightly'    => marle_nightly_rate($type),
    ];
    $ratesForJs[$type] = [
        'threeHour' => (float)(MARLE_BASE_RATE[$type] ?? 0),
        'extension' => (float)(MARLE_EXTENSION_RATE[$type] ?? 0),
        'nightly'   => marle_nightly_rate($type),
    ];
}
$peso = static fn(float $amount): string => "\u{20B1}" . number_format($amount);
$minHourly  = $roomRates ? min(array_column($roomRates, 'three_hour')) : 0.0;
$minNightly = $roomRates ? min(array_column($roomRates, 'nightly')) : 0.0;

// Display names are translated; the stored room type stays English because it
// is the value the booking engine, the badge poller and the front desk use.
$roomLabel = [
    'Without Garage' => site_t('Without Garage', 'Walang Garahe'),
    'With Garage'    => site_t('With Garage', 'May Garahe'),
    'Suite'          => 'Suite',
    'Family'         => site_t('Family', 'Pampamilya'),
];

// ── What is actually in each room ────────────────────────────────────────────
// Only what the page already promises: the blurbs above, and the amenities
// section's own "selected rooms" wording. Nothing here should be greener than
// the front desk is prepared to honour — the bathtub line stays qualified
// because the site cannot say which suites have one.
$roomAmenities = [
    'Without Garage' => [
        ['Air-conditioned', 'May aircon'],
        ['Double bed', 'Double bed'],
        ['Free Wi-Fi', 'Libreng Wi-Fi'],
    ],
    'With Garage' => [
        ['Air-conditioned', 'May aircon'],
        ['Double bed', 'Double bed'],
        ['Private garage', 'Sariling garahe'],
        ['Free Wi-Fi', 'Libreng Wi-Fi'],
    ],
    'Suite' => [
        ['Air-conditioned', 'May aircon'],
        ['Sitting corner', 'Sulok na upuan'],
        ['Bathtub in selected suites', 'Bathtub sa piling suite'],
        ['Free Wi-Fi', 'Libreng Wi-Fi'],
    ],
    'Family' => [
        ['Air-conditioned', 'May aircon'],
        ['Space for six', 'Kasya ang anim'],
        ['Free Wi-Fi', 'Libreng Wi-Fi'],
    ],
];

// ── The room viewer's four photographs ─────────────────────────────────────
// The tariff photograph, then the three details a guest asks about at the desk:
// how many sleep here, the kitchen, and the bathroom. The files live in
// pics/rooms/ and are optional — a missing one renders as a captioned plate —
// so dropping a real hotel photograph in with the same name is the whole of the
// upgrade. CREDITS.md beside them records where the current ones came from;
// CC BY and CC BY-SA require the credit the viewer prints under the photograph.
$roomSlug = [
    'Without Garage' => 'without-garage',
    'With Garage'    => 'with-garage',
    'Suite'          => 'suite',
    'Family'         => 'family',
];
$roomPhotoCredits = [
    'with-garage-capacity.jpg' => ['by' => 'G. Edward Johnson', 'lic' => 'CC BY 4.0',    'href' => 'https://creativecommons.org/licenses/by/4.0/'],
    'family-capacity.jpg'      => ['by' => 'Famartin',          'lic' => 'CC BY-SA 4.0', 'href' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
    'with-garage-bath.jpg'     => ['by' => 'Missvain',          'lic' => 'CC BY 4.0',    'href' => 'https://creativecommons.org/licenses/by/4.0/'],
    'family-bath.jpg'          => ['by' => 'Missvain',          'lic' => 'CC BY 4.0',    'href' => 'https://creativecommons.org/licenses/by/4.0/'],
    'suite-bath.jpg'           => ['by' => 'JIP',               'lic' => 'CC BY-SA 4.0', 'href' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
];
$roomPhotoDir = __DIR__ . '/../pics/rooms/';
$roomDetail = static function (string $file, string $alt, string $label) use ($roomPhotoDir, $roomPhotoCredits): array {
    return [
        'src'    => is_file($roomPhotoDir . $file) ? 'pics/rooms/' . $file : '',
        'alt'    => $alt,
        'label'  => $label,
        'credit' => $roomPhotoCredits[$file] ?? null,
    ];
};

$roomCards = [];
foreach ($roomOrder as $type) {
    $stat    = $roomStats[$type] ?? ['room_count' => 0, 'sleeps' => 0, 'first_room' => ''];
    $count   = (int)$stat['room_count'];
    $sleeps  = (int)$stat['sleeps'];
    $meta    = site_t('Sleeps', 'Hanggang') . ' ' . $sleeps
             . ' · ' . $count . ' ' . site_t('rooms', 'kwarto');
    $slug    = $roomSlug[$type] ?? '';
    $label   = $roomLabel[$type] ?? $type;
    $roomCards[$type] = [
        'type'    => $type,
        'label'   => $label,
        'count'   => $count,
        'sleeps'  => $sleeps,
        'room'    => (string)($stat['first_room'] ?? ''),
        'image'   => $roomMedia[$type][0],
        'alt'     => $roomMedia[$type][1],
        'blurb'   => site_t($roomBlurbs[$type][0], $roomBlurbs[$type][1]),
        'meta'    => $meta,
        'three'     => $roomRates[$type]['three_hour'],
        'extension' => $roomRates[$type]['extension'],
        'nightly'   => $roomRates[$type]['nightly'],
        // The two halves of the meta line, so the folio can set the capacity as
        // its own chip while `meta` stays the plain sentence the caption uses.
        'sleeps_label' => site_t('Sleeps', 'Hanggang') . ' ' . $sleeps,
        'count_label'  => $count . ' ' . site_t('rooms', 'kwarto'),
        'amenities'    => array_map(
            static fn(array $pair): string => site_t($pair[0], $pair[1]),
            $roomAmenities[$type] ?? []
        ),
        'gallery' => [
            [
                'src'    => $roomMedia[$type][0],
                'alt'    => $roomMedia[$type][1],
                'label'  => site_t('The room', 'Ang kwarto'),
                'credit' => null,
            ],
            $roomDetail(
                $slug . '-capacity.jpg',
                sprintf(site_t('Beds and lighting in the %s room', 'Kama at ilaw sa %s'), $label),
                site_t('Sleeps', 'Hanggang') . ' ' . $sleeps
            ),
            $roomDetail(
                'kitchen.jpg',
                site_t('The kitchen the front desk keeps open', 'Ang kusina na bukas kasama ng front desk'),
                site_t('Kitchen', 'Kusina')
            ),
            $roomDetail(
                $slug . '-bath.jpg',
                sprintf(site_t('The bathroom of the %s room', 'Ang banyo ng %s'), $label),
                site_t('Bathroom', 'Banyo')
            ),
        ],
    ];
}
$firstCard = $roomCards[$roomOrder[0]] ?? null;
