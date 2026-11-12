<?php

function demo_guest_name(int $sequence): string
{
    $names = [
        'Maria Santos', 'Juan Dela Cruz', 'Angela Reyes', 'Miguel Garcia',
        'Patricia Mendoza', 'Carlo Aquino', 'Beatriz Navarro', 'Jose Ramirez',
        'Camille Flores', 'Rafael Bautista', 'Sofia Castillo', 'Daniel Cruz',
        'Nicole Villanueva', 'Mark Manalo', 'Andrea Santiago', 'Paolo Torres',
        'Erika Mercado', 'Luis Fernandez', 'Kristine Salazar', 'Gabriel Ramos',
        'Hannah Lim', 'Adrian Pascual', 'Leah Gonzales', 'Marco Evangelista',
        'Mikaela Dominguez', 'Enzo Herrera', 'Chloe Rivera', 'Nathaniel Valdez',
        'Jasmine Padilla', 'Andre Cabrera', 'Alyssa de Leon', 'Vincent Marquez',
        'Clarisse Ong', 'Jericho Fernandez', 'Maureen Tan', 'Christian Soriano',
        'Rina Velasco', 'Joshua Mariano', 'Therese Aquino', 'Nicolas Espiritu',
    ];

    return $names[crc32('guest:' . $sequence) % count($names)];
}

function demo_review_text(string $category, int $rating, int $sequence): string
{
    $reviews = [
        'hotel' => [
            3 => [
                'The room was clean and comfortable, though the hallway noise was noticeable at night.',
                'Maayos at malinis ang kuwarto, pero medyo maingay sa hallway kapag gabi.',
                'The room met our basic needs, although the bathroom fixtures could use an update.',
            ],
            4 => [
                'The room was spacious and clean, and the bed was comfortable. Check-in took a little longer than expected.',
                'Komportable ang kuwarto at mababait ang staff. Medyo mabagal lang ang Wi-Fi tuwing gabi.',
                'The hotel was peaceful and well maintained. I would have liked a few more power outlets near the bed.',
            ],
            5 => [
                'The room was spotless, quiet, and comfortable, and the staff made check-in quick and welcoming.',
                'Napakaganda ng aming stay. Malinis ang kuwarto, komportable ang kama, at napaka-accommodating ng staff.',
                'Excellent hotel experience from arrival to checkout. The room was clean, relaxing, and exactly as described.',
            ],
        ],
        'restaurant' => [
            3 => [
                'The meal was fine, but the soup arrived lukewarm and the wait was longer than expected.',
                'Ayos naman ang pagkain at sapat ang servings, pero hindi pantay ang timpla ng ilang dishes.',
                'Breakfast had enough choices, but some dishes were already cool when we arrived.',
            ],
            4 => [
                'The food was tasty and portions were generous. Service was friendly, although the dining area was busy.',
                'Masarap ang dinner at maalaga ang staff. Medyo maalat lang ang isang dish pero maganda ang overall experience.',
                'A pleasant breakfast with fresh fruit and good coffee. The service could be a bit faster during peak hours.',
            ],
            5 => [
                'The food was delicious and arrived hot, with warm service that made the meal feel special.',
                'Sobrang sarap ng breakfast at mabilis ang service. Fresh ang pagkain at sulit ang buong experience.',
                'A memorable dinner with flavorful dishes, generous portions, and attentive staff.',
            ],
        ],
        'amenities' => [
            3 => [
                'The pool area was usable but crowded, and a few of the loungers needed cleaning.',
                'Okay ang mga amenities para sa maikling stay, pero maliit ang gym at may isang sirang equipment.',
                'The facilities were adequate for a short stay, but the pool towels were not always available.',
            ],
            4 => [
                'The pool was clean and enjoyable, and the parking was convenient. The lounge area filled up quickly.',
                'Malinis ang pool at maayos ang parking. Maliit lang ang lounge area pero naging convenient pa rin ang stay.',
                'The amenities added value to the stay, especially the quiet lounge and easy parking.',
            ],
            5 => [
                'The pool was clean, the Wi-Fi was reliable, and the parking area made the whole stay very convenient.',
                'Mahusay at malinis ang lahat ng amenities. Nag-enjoy kami sa pool at sa tahimik na lounge area.',
                'Everything we used was clean and ready, from the pool area to the parking facilities. A very relaxing stay.',
            ],
        ],
        'service' => [
            3 => [
                'The staff were polite, but our extra towels took quite a while to arrive.',
                'Maayos at magalang ang staff, pero matagal bago dumating ang extra towels na nirequest namin.',
                'Staff were courteous and the issue was resolved, but the response time could be improved.',
            ],
            4 => [
                'The front desk team was helpful and arranged our request quickly, with only a small delay at checkout.',
                'Friendly at professional ang staff. Maingat at mabilis nilang inayos ang room service request namin.',
                'Good service overall, with clear communication and a helpful team during our stay.',
            ],
            5 => [
                'The staff were warm, attentive, and quick to help whenever we needed anything.',
                'Napakaganda ng serbisyo mula front desk hanggang housekeeping. Ramdam naming welcome kami sa hotel.',
                'Every request was handled promptly and courteously, making the stay easy and stress-free.',
            ],
        ],
    ];

    $categoryReviews = $reviews[$category] ?? $reviews['hotel'];
    $ratingReviews = $categoryReviews[$rating] ?? $categoryReviews[4];
    $selectionKey = crc32($category . ':' . $rating . ':' . $sequence);
    return $ratingReviews[$selectionKey % count($ratingReviews)];
}
