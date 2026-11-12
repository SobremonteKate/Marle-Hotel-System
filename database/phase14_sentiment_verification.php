<?php
// Focused verification for the sentiment language detection, limit semantics,
// and recommendation quality. Read-only against the live database.
// Usage: php database/phase14_sentiment_verification.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../luna_sentiment_service.php';

$failures = 0;
$pass = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($detail !== '' ? '  [' . $detail . ']' : '') . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

// 1) Seeded Tagalog examples must classify as tagalog.
$tagalogCases = [
    'Napakaganda ng aming stay. Malinis ang kuwarto, komportable ang kama, at napaka-accommodating ng staff.',
    'Mahusay at malinis ang lahat ng amenities. Nag-enjoy kami sa pool at sa tahimik na lounge.',
    'Maayos at malinis ang kuwarto, pero medyo maingay sa hallway kapag gabi.',
    'Sobrang sarap ng breakfast at mabilis ang service. Fresh ang pagkain at sulit ang buong experience.',
    'Hindi na kami ulit sa hotel na ito. Ang bathroom, hindi natuyo ang sahig pagkatapos linisin.',
    'Yung kama hindi kumportable at medyo maingay ang aircon kagabi.',
    'Sana may mas malaking parking area para sa mga guests.',
    'Masarap ang dinner at maalaga ang staff. Medyo maalat lang ang adobo.',
    'Komportable ang kuwarto at mababait ang staff. Medyo mabagal lang ang Wi-Fi tuwing gabi.',
    'Nag-enjoy ang mga bata sa pool. Sana may towel na mas malaki sa poolside.',
    'Ang ganda ng view pero medyo mahal ng presyo ng pagkain sa restaurant.',
];
foreach ($tagalogCases as $case) {
    $got = LunaSentimentService::classifyLanguage($case);
    $pass('tagalog: ' . mb_substr($case, 0, 46) . '…', $got === 'tagalog', $got);
}

// 2) English false positives, including reviews that contain the word "at".
$englishCases = [
    'One of the best hotel experiences we\'ve ever had. The room was spotless and the bed was so comfortable.',
    'The food was delicious and arrived hot, with warm service that made our anniversary special at the restaurant.',
    'Very disappointed with our stay. The AC was broken and the pool was dirty at check-in.',
    'The restaurant menu was limited and the wait time was long. Great location though.',
    'Great overall experience. The housekeeping was thorough and staff was friendly at the front desk.',
    'The front desk team was helpful and arranged our request quickly. Room service at midnight was fast.',
    'Average stay at best. The room was clean but the breakfast at the buffet ran out early.',
    'Room was decent but the service was a bit slow. The food was average at the hotel restaurant.',
];
foreach ($englishCases as $case) {
    $got = LunaSentimentService::classifyLanguage($case);
    $pass('english: ' . mb_substr($case, 0, 46) . '…', $got === 'english', $got);
}

// 3) Mixed Tagalog-English reviews must still be detected.
$mixedCases = [
    'The room was clean pero medyo maingay ang hallway.',
    'Staff were very friendly and magalang, sobrang bait nila sa front desk.',
    'Great breakfast, masarap ang food at mabilis ang service.',
];
foreach ($mixedCases as $case) {
    $got = LunaSentimentService::classifyLanguage($case);
    $pass('mixed: ' . mb_substr($case, 0, 46) . '…', $got === 'tagalog', $got);
}

// 4) Colloquial / punctuation-heavy spellings must be caught.
$colloquialCases = [
    'so BRAang ganda!!! ng room, sobrang linis at ang bango pa...',
    'Hmm, ok naman... pero ang wifi, grabe ang bagal?!',
    'Wala kaming matinong tulog kasi ang ingay ng aircon, sobrang lakas.',
    'Di kami na-enjoy yung stay, ang liit ng banyo at walang mainit na tubig.',
    'Magkano po ba ang extra person charge sa front desk?',
    'Pwede po ba magpa-late checkout? Ang ganda ng stay namin, salamat po.',
];
foreach ($colloquialCases as $case) {
    $got = LunaSentimentService::classifyLanguage($case);
    $pass('colloquial: ' . mb_substr($case, 0, 46) . '…', $got === 'tagalog', $got);
}

// 5) Edge cases: punctuation-only, numbers, empty.
$pass('empty text -> english', LunaSentimentService::classifyLanguage('') === 'english');
$pass('punctuation-only -> english', LunaSentimentService::classifyLanguage('!!! ... ???') === 'english');
$pass('numbers-only -> english', LunaSentimentService::classifyLanguage('5 4 3 2 1') === 'english');
$pass('weird bytes safe', is_string(LunaSentimentService::classifyLanguage("abc \xE2\x00 ang test")));

// 6) Live DB checks: Tagalog reviews beyond the newest 100 window.
$conn = new mysqli('localhost', 'root', '', 'marlehoteldb');
if ($conn->connect_error) {
    exit('DB unavailable: ' . $conn->connect_error . PHP_EOL);
}
$conn->set_charset('utf8mb4');

$total = (int)$conn->query('SELECT COUNT(*) c FROM reviewstbl')->fetch_assoc()['c'];
$latest100 = $conn->query('SELECT review_text FROM reviewstbl ORDER BY review_date DESC, id DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
$tagalogInLatest100 = 0;
foreach ($latest100 as $row) {
    if (LunaSentimentService::classifyLanguage((string)$row['review_text']) === 'tagalog') {
        $tagalogInLatest100++;
    }
}
$beyondWindow = $conn->query('SELECT review_text FROM reviewstbl ORDER BY review_date DESC, id DESC LIMIT 300 OFFSET 100')->fetch_all(MYSQLI_ASSOC);
$tagalogBeyond100 = 0;
foreach ($beyondWindow as $row) {
    if (LunaSentimentService::classifyLanguage((string)$row['review_text']) === 'tagalog') {
        $tagalogBeyond100++;
    }
}

$pass(
    'limit semantics: Tagalog exists beyond newest 100 (service now scans 400)',
    $tagalogBeyond100 > 0,
    "total={$total}, tagalog in latest 100={$tagalogInLatest100}, tagalog beyond 100={$tagalogBeyond100}"
);

// 7) Recommendation quality: dominant negative theme drives an evidence-based plan.
$reflect = new ReflectionClass(LunaSentimentService::class);
$method = $reflect->getMethod('buildReport');
$method->setAccessible(true);
$rows = [
    ['sentiment' => 'negative', 'sentiment_score' => -0.7, 'theme' => 'cleanliness', 'category' => 'hotel'],
    ['sentiment' => 'negative', 'sentiment_score' => -0.6, 'theme' => 'cleanliness', 'category' => 'hotel'],
    ['sentiment' => 'negative', 'sentiment_score' => -0.4, 'theme' => 'cleanliness', 'category' => 'restaurant'],
    ['sentiment' => 'positive', 'sentiment_score' => 0.8, 'theme' => 'staff', 'category' => 'service'],
];
$report = $method->invoke(null, $rows, null, null, null);
$rec = (string)($report['recommendation'] ?? '');
$qualityChecks = [
    'mentions theme Cleanliness' => str_contains($rec, 'Cleanliness'),
    'mentions affected count 3' => str_contains($rec, '3 review'),
    'mentions percentage 75%' => str_contains($rec, '75.0%'),
    'mentions category Hotel' => str_contains($rec, 'Hotel'),
    'has concrete action' => str_contains($rec, 'housekeeping sweep'),
    'has follow-up verification' => str_contains($rec, 'To verify:'),
];
foreach ($qualityChecks as $name => $ok) {
    $pass('recommendation ' . $name, $ok);
}
$pass('action_plan mirrors recommendation', ($report['action_plan'][0] ?? '') === $rec);
$fallback = $method->invoke(null, [['sentiment' => 'positive', 'sentiment_score' => 0.9, 'theme' => 'staff', 'category' => 'hotel']], null, null, null);
$pass('positive-only scope -> monitoring fallback', str_contains((string)$fallback['action_plan'][0], 'No recurring negative theme'));

echo PHP_EOL . ($failures === 0 ? 'ALL CHECKS PASSED' : $failures . ' CHECK(S) FAILED') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
