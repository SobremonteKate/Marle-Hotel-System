<?php
require_once __DIR__ . '/includes/server_clock.php';   // marle_hotel_date(): the hotel's date, not PHP's
require_once __DIR__ . '/includes/language_detect.php';

final class LunaSentimentService
{
    private const MODEL = 'qwen/qwen3.8-27b';
    private const LATEST_LIMIT = 100;
    // Reviews scanned before language detection and the requested limit are applied.
    private const SCAN_LIMIT = 400;
    private const BATCH_SIZE = 20;
    private const MAX_WORDS = 200;
    private const THEMES = [
        'cleanliness', 'staff', 'location', 'amenities', 'value',
        'food', 'noise', 'maintenance', 'other',
    ];

    public static function analyze(mysqli $conn, string $apiKey, int $limit = 100, ?string $category = null, ?string $period = null, ?string $language = null): array
    {
        $limit = max(1, min(100, $limit));
        $category = in_array($category, ['hotel', 'restaurant', 'amenities', 'service'], true) ? $category : null;
        $language = in_array($language, ['english', 'tagalog'], true) ? $language : null;
        [$from, $to] = self::periodBounds($period, $conn);
        // Detect language BEFORE limiting so the requested limit describes how many
        // matching reviews are returned, not how many reviews were searched.
        $latestReviews = self::latestReviews($conn, self::SCAN_LIMIT, $category, $from, $to);
        if ($language !== null) {
            $latestReviews = array_values(array_filter(
                $latestReviews,
                static fn(array $review): bool => self::detectLanguage((string)$review['review_text']) === $language
            ));
        }
        $latestReviews = array_slice($latestReviews, 0, $limit);
        $pendingReviews = array_values(array_filter(
            $latestReviews,
            static fn(array $review): bool => !(bool)$review['has_result']
        ));

        $storedCount = count($latestReviews) - count($pendingReviews);
        $processedCount = 0;
        $errors = [];

        foreach (array_chunk($pendingReviews, self::BATCH_SIZE) as $batch) {
            $result = self::analyzeBatch($apiKey, $batch);
            if (!$result['success']) {
                $errors[] = $result['error'];
                continue;
            }

            self::storeResults($conn, $batch, $result['results']);
            $processedCount += count($result['results']);
        }

        $selectedResults = self::latestStoredResults($conn, self::SCAN_LIMIT, $category, $from, $to);
        if ($language !== null) {
            $selectedResults = array_values(array_filter(
                $selectedResults,
                static fn(array $review): bool => self::detectLanguage((string)$review['review_text']) === $language
            ));
        }
        $selectedResults = array_slice($selectedResults, 0, $limit);

        return [
            'success' => count($errors) === 0,
            'scope_count' => count($latestReviews),
            'stored_count' => $storedCount + $processedCount,
            'newly_processed' => $processedCount,
            'skipped_count' => $storedCount,
            'batch_size' => self::BATCH_SIZE,
            'errors' => $errors,
            'summary' => self::summary($conn, $from, $to),
            'results' => $selectedResults,
            'report' => self::buildReport($selectedResults, $period, $category, $language),
            'filters' => ['limit' => $limit, 'category' => $category, 'period' => $period, 'language' => $language, 'from' => $from, 'to' => $to],
        ];
    }

    /**
     * Public passthrough so verification scripts and future callers can reuse
     * the same language classifier without duplicating its marker lists.
     */
    public static function classifyLanguage(string $text): string
    {
        return self::detectLanguage($text);
    }

    public static function summary(mysqli $conn, ?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT sentiment, theme, sentiment_score, review_date
                FROM review_sentiment_results';
        $params = [];
        $types = '';
        $conditions = [];

        if ($from !== null && $from !== '') {
            $conditions[] = 'review_date >= ?';
            $params[] = $from;
            $types .= 's';
        }
        if ($to !== null && $to !== '') {
            $conditions[] = 'review_date <= ?';
            $params[] = $to;
            $types .= 's';
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY review_date DESC, id DESC';

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $sentiments = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $themes = [];
        $scoreTotal = 0.0;
        foreach ($rows as $row) {
            $sentiments[$row['sentiment']]++;
            $themes[$row['theme']] = ($themes[$row['theme']] ?? 0) + 1;
            $scoreTotal += (float)$row['sentiment_score'];
        }
        arsort($themes);

        return [
            'count' => count($rows),
            'average_score' => $rows ? round($scoreTotal / count($rows), 3) : null,
            'sentiments' => $sentiments,
            'percentages' => array_map(
                static fn(int $count): float => $rows ? round(($count / count($rows)) * 100, 2) : 0.0,
                $sentiments
            ),
            'themes' => $themes,
            'from' => $from,
            'to' => $to,
        ];
    }

    private static function latestReviews(mysqli $conn, int $limit, ?string $category, ?string $from, ?string $to): array
    {
        $sql = "SELECT r.id, r.review_text, r.review_date, r.rating,
                       COALESCE(s.sentiment, '') AS stored_sentiment,
                       CASE WHEN s.review_id IS NOT NULL
                                  AND CONVERT(s.review_text_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(r.review_text USING utf8mb4) COLLATE utf8mb4_general_ci
                                  AND s.review_date = r.review_date
                            THEN 1 ELSE 0 END AS has_result
                FROM reviewstbl r
                LEFT JOIN review_sentiment_results s ON s.review_id = r.id";
        [$where, $params, $types] = self::reviewFilters($category, $from, $to);
        $sql .= $where . " ORDER BY r.review_date DESC, r.id DESC LIMIT " . $limit;
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function latestStoredResults(mysqli $conn, int $limit, ?string $category, ?string $from, ?string $to): array
    {
        $sql = "SELECT r.id, r.guest_name, r.room_no, r.category, r.review_text, r.review_date,
                       s.sentiment, s.sentiment_score, s.theme, s.processed_at
                FROM reviewstbl r
                JOIN review_sentiment_results s ON s.review_id = r.id";
        [$where, $params, $types] = self::reviewFilters($category, $from, $to);
        $sql .= $where . " ORDER BY r.review_date DESC, r.id DESC LIMIT " . $limit;
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function reviewFilters(?string $category, ?string $from, ?string $to): array
    {
        $conditions = [];
        $params = [];
        $types = '';
        if ($category !== null) {
            $conditions[] = 'CONVERT(r.category USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci';
            $params[] = $category;
            $types .= 's';
        }
        if ($from !== null) {
            $conditions[] = 'r.review_date >= ?';
            $params[] = $from;
            $types .= 's';
        }
        if ($to !== null) {
            $conditions[] = 'r.review_date <= ?';
            $params[] = $to;
            $types .= 's';
        }
        return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params, $types];
    }

    /**
     * The window a report covers, measured on the HOTEL's calendar — the same days the
     * reviews being counted were filed on (includes/server_clock.php). PHP's own clock is
     * six hours behind the database here, so between midnight and 06:00 "today" was
     * yesterday and the week/month/year windows shifted with it.
     */
    private static function periodBounds(?string $period, ?mysqli $conn = null): array
    {
        $today = marle_hotel_date(0, $conn);
        $base  = strtotime($today);
        return match ($period) {
            'today' => [$today, $today],
            'week' => [date('Y-m-d', strtotime('monday this week', $base)), $today],
            'month' => [date('Y-m-01', $base), $today],
            'year' => [date('Y-01-01', $base), $today],
            default => [null, null],
        };
    }

    private static function buildReport(array $rows, ?string $period, ?string $category, ?string $language = null): array
    {
        $sentiments = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $themes = [];
        $categoryStats = [];
        $scoreTotal = 0.0;
        foreach ($rows as $row) {
            $sentiment = (string)$row['sentiment'];
            $theme = (string)$row['theme'];
            $reviewCategory = (string)$row['category'];
            $score = (float)$row['sentiment_score'];
            $sentiments[$sentiment]++;
            $themes[$theme] = ($themes[$theme] ?? 0) + 1;
            $scoreTotal += $score;
            if (!isset($categoryStats[$reviewCategory])) {
                $categoryStats[$reviewCategory] = ['count' => 0, 'score' => 0.0, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'themes' => []];
            }
            $categoryStats[$reviewCategory]['count']++;
            $categoryStats[$reviewCategory]['score'] += $score;
            $categoryStats[$reviewCategory][$sentiment]++;
            $categoryStats[$reviewCategory]['themes'][$theme] = ($categoryStats[$reviewCategory]['themes'][$theme] ?? 0) + 1;
        }
        arsort($themes);

        $categoryBreakdown = [];
        foreach ($categoryStats as $name => $stats) {
            arsort($stats['themes']);
            $categoryBreakdown[$name] = [
                'count' => $stats['count'],
                'average_score' => round($stats['score'] / $stats['count'], 3),
                'positive' => $stats['positive'],
                'neutral' => $stats['neutral'],
                'negative' => $stats['negative'],
                'top_theme' => array_key_first($stats['themes']),
            ];
        }

        $positiveThemes = [];
        $negativeThemes = [];
        foreach ($rows as $row) {
            if ((float)$row['sentiment_score'] >= 0.2) {
                $positiveThemes[$row['theme']] = ($positiveThemes[$row['theme']] ?? 0) + 1;
            }
            if ((float)$row['sentiment_score'] <= -0.2) {
                $negativeThemes[$row['theme']] = ($negativeThemes[$row['theme']] ?? 0) + 1;
            }
        }
        arsort($positiveThemes);
        arsort($negativeThemes);

        $strengths = [];
        foreach (array_slice($positiveThemes, 0, 3, true) as $theme => $count) {
            $strengths[] = ucfirst($theme) . ' is a recurring strength in ' . $count . ' review' . ($count === 1 ? '' : 's') . '.';
        }
        $concerns = [];
        foreach (array_slice($negativeThemes, 0, 3, true) as $theme => $count) {
            $concerns[] = ucfirst($theme) . ' appears as a concern in ' . $count . ' review' . ($count === 1 ? '' : 's') . '.';
        }

        // Evidence-based recommendations: every entry names the dominant negative
        // theme, the affected review count/percentage, the relevant category, a
        // concrete operational action, and a suggested follow-up verification.
        $categoryLabels = ['hotel' => 'Hotel', 'restaurant' => 'Restaurant', 'amenities' => 'Amenities', 'service' => 'Service'];
        $themeActions = [
            'cleanliness' => ['Cleanliness', 'Run a focused housekeeping sweep of the rooms and areas mentioned, then add the spot to the daily checklist.'],
            'staff' => ['Staff', 'Hold a short coaching huddle with the team on the specific service gap, and rehearse a script for the situation guests described.'],
            'food' => ['Food', 'Check food temperature, plating and portion consistency during the busiest hours, and brief the kitchen line before the next peak.'],
            'noise' => ['Noise', 'Log the noisy rooms and hours, reinforce quiet-hours signage, and offer affected guests a room move or earplugs at the desk.'],
            'maintenance' => ['Maintenance', 'Open a work order for the reported item, assign an owner, and track it until it is closed and re-checked.'],
            'amenities' => ['Amenities', 'Inspect the amenity daily, restock supplies, and post a sign if it must be taken out of service.'],
            'value' => ['Value', 'Review what guests receive for the price paid, and clarify what is included before booking and at check-in.'],
            'location' => ['Location', 'Improve arrival directions on the booking confirmation and set location expectations before check-in.'],
            'other' => ['Other', 'Read the affected reviews together, name the recurring issue, and assign one owner to propose a fix within the week.'],
        ];
        $themeFollowUps = [
            'cleanliness' => 're-inspect the affected rooms within 48 hours and confirm the checklist is signed off',
            'staff' => 'review the next week of guest feedback to confirm the service gap is closing',
            'food' => 'taste-test the same menu items during the next peak service and compare guest comments',
            'noise' => 'check the noise log after one week and confirm complaints have stopped',
            'maintenance' => 'verify the work order is closed and the fix holds for two weeks',
            'amenities' => 're-check stock and condition daily for one week',
            'value' => 'compare the next month of value-related comments against this baseline',
            'location' => 'ask the next arrivals at check-in whether directions were clear',
            'other' => 'follow up with the assigned owner at the next weekly meeting',
        ];

        $actionPlan = [];
        foreach (array_slice($negativeThemes, 0, 3, true) as $theme => $count) {
            [$label, $action] = $themeActions[$theme] ?? ['Other', $themeActions['other'][1]];
            $affectedCategories = [];
            foreach ($rows as $row) {
                if ($row['theme'] === $theme && (float)$row['sentiment_score'] <= -0.2) {
                    $rowCategory = (string)$row['category'];
                    $affectedCategories[$rowCategory !== '' ? $rowCategory : 'hotel'] = ($affectedCategories[$rowCategory !== '' ? $rowCategory : 'hotel'] ?? 0) + 1;
                }
            }
            arsort($affectedCategories);
            $topCategory = array_key_first($affectedCategories);
            $categoryLabel = $categoryLabels[$topCategory] ?? ucfirst((string)$topCategory);
            $actionPlan[] = sprintf(
                '%s: %d review%s (%.1f%% of this scope) flag this issue under %s. %s To verify: %s.',
                $label,
                $count,
                $count === 1 ? '' : 's',
                $count / max(1, count($rows)) * 100,
                $categoryLabel,
                $action,
                $themeFollowUps[$theme] ?? $themeFollowUps['other']
            );
        }
        if (!$actionPlan) {
            $actionPlan[] = 'No recurring negative theme in this scope. Keep the strongest practices consistent and continue monitoring new feedback.';
        }

        $count = count($rows);
        $average = $count ? round($scoreTotal / $count, 3) : null;
        $overall = $average === null ? 'N/A' : ($average > 0.2 ? 'Positive' : ($average < -0.2 ? 'Negative' : 'Neutral'));
        $scopeLabel = $period ? 'the selected ' . $period . ' period' : 'the selected latest-review scope';
        if ($language !== null) {
            $scopeLabel .= ' in ' . ucfirst($language);
        }

        return [
            'overall_sentiment' => $overall,
            'sentiment_score' => $average === null ? null : round(($average + 1) * 50),
            'summary' => $count
                ? "Marle AI reviewed {$count} guest reviews from {$scopeLabel}. The average sentiment score was {$average}, with {$sentiments['positive']} positive, {$sentiments['neutral']} neutral, and {$sentiments['negative']} negative reviews. The patterns below identify where the hotel is performing well and where management attention can have the greatest impact."
                : 'No stored review results match the selected filters yet.',
            'strengths' => $strengths ?: ['There are not enough positive stored results in this scope to identify a reliable strength yet.'],
            'concerns' => $concerns ?: ['There are no recurring negative themes in this selected scope. Continue monitoring new feedback.'],
            'category_breakdown' => $categoryBreakdown,
            'top_themes' => $themes,
            'insights' => [
                'Scope' => $category ? ucfirst($category) . ' reviews' : 'All categories',
                'Reviews analyzed' => $count,
                'Average score' => $average,
                'Most frequent themes' => array_slice(array_keys($themes), 0, 3),
            ],
            'action_plan' => $actionPlan,
            'recommendation' => $actionPlan[0],
            'category' => $category,
            'period' => $period,
            'language' => $language,
        ];
    }

    /**
     * Weighted Tagalog/English detection.
     *
     * Normalizes text before matching so colloquial spelling, punctuation and
     * case do not hide markers, then scores Tagalog markers by weight. Marker
     * weights are designed so a single strong function word is enough while
     * ambiguous English words (e.g. "at") carry no weight at all.
     */
    /**
     * Language verdict for a review.
     *
     * Delegates to includes/language_detect.php — a real Naive-Bayes classifier
     * over character n-grams and word unigrams (trained with Taglish), which
     * replaced the old hand-written keyword list. Kept as a method so the
     * service's own callers (and the verification scripts) are unaffected.
     */
    private static function detectLanguage(string $text): string
    {
        return marle_detect_language($text);
    }

    private static function analyzeBatch(string $apiKey, array $batch): array
    {
        $response = self::callGroq($apiKey, $batch, false);
        $validated = self::validateResponse($response, $batch);
        if ($validated['success']) {
            return $validated;
        }

        $response = self::callGroq($apiKey, $batch, true);
        $validated = self::validateResponse($response, $batch);
        if ($validated['success']) {
            return $validated;
        }

        $results = [];
        $missing = $batch;
        foreach ($batch as $review) {
            // The model intermittently returns an empty body or merges duplicate
            // texts under bursty rate limits — give each review a few tries.
            $singleValidated = ['success' => false];
            for ($attempt = 0; $attempt < 3 && !$singleValidated['success']; $attempt++) {
                $singleResponse = self::callGroq($apiKey, [$review], true);
                $singleValidated = self::validateResponse($singleResponse, [$review]);
                if (!$singleValidated['success']) {
                    usleep(500000);
                }
            }
            if ($singleValidated['success']) {
                $results = array_merge($results, $singleValidated['results']);
                $missing = array_values(array_filter(
                    $missing,
                    static fn(array $candidate): bool => (int)$candidate['id'] !== (int)$review['id']
                ));
            }
        }

        if ($missing) {
            $missingIds = implode(', ', array_map(static fn(array $row): string => (string)$row['id'], $missing));
            return ['success' => false, 'error' => 'Marle AI could not validate review IDs: ' . $missingIds];
        }

        return ['success' => true, 'results' => $results];
    }

    private static function callGroq(string $apiKey, array $batch, bool $strict): string
    {
        $lines = [];
        foreach ($batch as $review) {
            $text = self::truncateWords((string)$review['review_text']);
            $rating = isset($review['rating']) && (int)$review['rating'] > 0
                ? ' — guest star rating: ' . (int)$review['rating'] . '/5'
                : '';
            $lines[] = $review['id'] . '. ' . json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $rating;
        }

        $systemPrompt = <<<'PROMPT'
You are Marle AI, a sentiment analysis engine for hotel guest reviews.

TASK
You will receive a numbered batch of hotel reviews. For each review, classify:
1. Overall sentiment: "positive", "neutral", or "negative"
2. Sentiment score: a float from -1.0 (very negative) to 1.0 (very positive)
3. Primary theme: pick ONE from this fixed list only —
["cleanliness", "staff", "location", "amenities", "value", "food", "noise", "maintenance", "other"]

LANGUAGE
Reviews arrive in English, Filipino/Tagalog, Taglish (mixed), or colloquial slang
with informal spelling (e.g. "npaka" = napaka, "nku" = naku, "k" = ako, "nman" = naman).
You MUST decode the slang and translate the meaning in your head before classifying.
Never let unfamiliar spelling push you toward "neutral" — decode first, then judge.

TAGALOG / TAGLISH INTERPRETATION GUIDE
- "napaka-/npaka- + adjective" = very + adjective (intensifier, never neutral by itself)
- "sungit" = rude, mean, surly (NEGATIVE when describing staff or people)
- "suplada/suplado" = snobbish, standoffish (NEGATIVE)
- "mabantot" = smelly/stale (NEGATIVE, cleanliness)
- "sira" = broken (NEGATIVE, maintenance)
- "tagal" / "ang tagal" = took very long (NEGATIVE for service speed)
- "mahal" = expensive (often NEGATIVE for value; check context)
- "sulit" = worth it (POSITIVE)
- "masarap" = delicious (POSITIVE)
- "malinis" / "maayos" = clean / tidy (POSITIVE)
- "mabait" / "magalang" / "accommodating" = kind / respectful / accommodating (POSITIVE)
- "grabe", "sobrang", "napaka" intensify whatever follows — keep the polarity, raise the strength
- "hindi" / "hindi kami" + positive word negates it (e.g. "hindi masarap" = not tasty = NEGATIVE)
- "ok lang", "okay naman", "pwede na" = acceptable but unremarkable (NEUTRAL)
- "sana" + wish = unmet expectation (usually NEGATIVE)
- Complaint markers: "kaso", "pero" introducing a problem, "kahit" + unmet request, "ulit" (a repeated problem)
- "walang kwenta" / "walang silbi" = worthless / useless (NEGATIVE); "sayang" = a waste (NEGATIVE)
- Indifference markers ("kebs lang", "keri lang", "meh", "bahala na", "ewan") set a NEUTRAL
  baseline when they stand alone — but they do NOT cancel an explicit problem word in the same
  review: "kebs lang ... walang kwenta" is still NEGATIVE, because the waste/shortcoming is stated.

EACH REVIEW MAY INCLUDE A GUEST STAR RATING (1–5) after the text.
Use it as a supporting signal when the text is ambiguous: 1–2 stars lean negative,
4–5 stars lean positive. When the text meaning is clear, the text dominates the rating.

RULES
- Output ONLY valid JSON. No explanations, no preamble, no markdown formatting, no code fences.
- Do not repeat or quote the review text back in your output.
- Do not skip any review ID, even if the review is very short or ambiguous — make your best judgment.
- If a review mixes sentiments, classify by the DOMINANT sentiment expressed. A single strong
  complaint outweighs polite filler; do not average a clear complaint into "neutral".
- Short slangy complaints are still complaints: "npaka sungit ng front desk nku" = the front
  desk was very rude = NEGATIVE, theme "staff".
- Sarcastic praise is NEGATIVE, not positive: "galing galing nman ha", "wow ha ang tagal",
  "sobrang bilis talaga" (annoyed tone), "sana all" — Filipinos often complain by praising
  ironically. Detect the mismatch between praise words and the situation described.
- Keep output strictly to the schema below. No extra fields.

OUTPUT FORMAT
Return a single JSON array:
[
  {"id": 1, "sentiment": "positive", "score": 0.8, "theme": "staff"}
]
PROMPT;

        $strictInstruction = $strict ? "\nReturn ONLY the JSON array, nothing else." : '';
        $userPrompt = 'Analyze the following ' . count($batch) . " hotel reviews. Return JSON only, following the schema in the system prompt.\n\n"
            . implode("\n", $lines) . $strictInstruction;

        $requestBody = json_encode([
            'model' => self::MODEL,
            'max_tokens' => 700,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $status !== 200) {
            return '';
        }
        $decoded = json_decode((string)$response, true);
        return (string)($decoded['choices'][0]['message']['content'] ?? '');
    }

    private static function validateResponse(string $raw, array $batch): array
    {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```$/', '', $raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Marle AI returned invalid JSON.'];
        }

        $expectedIds = array_map(static fn(array $row): int => (int)$row['id'], $batch);
        $receivedIds = [];
        foreach ($decoded as $item) {
            if (!is_array($item)
                || !isset($item['id'], $item['sentiment'], $item['score'], $item['theme'])
                || !in_array($item['sentiment'], ['positive', 'neutral', 'negative'], true)
                || !is_numeric($item['score'])
                || (float)$item['score'] < -1.0
                || (float)$item['score'] > 1.0
                || !in_array($item['theme'], self::THEMES, true)) {
                return ['success' => false, 'error' => 'Marle AI returned an invalid result item.'];
            }
            $id = (int)$item['id'];
            if (in_array($id, $receivedIds, true)) {
                return ['success' => false, 'error' => 'Marle AI returned a duplicate review ID.'];
            }
            $receivedIds[] = $id;
        }

        sort($expectedIds);
        sort($receivedIds);
        if ($expectedIds !== $receivedIds) {
            return ['success' => false, 'error' => 'Marle AI returned incomplete review IDs.'];
        }

        return ['success' => true, 'results' => $decoded];
    }

    private static function storeResults(mysqli $conn, array $batch, array $results): void
    {
        $reviewsById = [];
        foreach ($batch as $review) {
            $reviewsById[(int)$review['id']] = $review;
        }

        $stmt = $conn->prepare(
            'INSERT INTO review_sentiment_results
                (review_id, review_text_snapshot, review_date, sentiment, sentiment_score, theme, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                review_text_snapshot = VALUES(review_text_snapshot),
                review_date = VALUES(review_date),
                sentiment = VALUES(sentiment),
                sentiment_score = VALUES(sentiment_score),
                theme = VALUES(theme),
                processed_at = NOW()'
        );

        $conn->begin_transaction();
        try {
            foreach ($results as $result) {
                $review = $reviewsById[(int)$result['id']];
                $reviewId = (int)$review['id'];
                $text = (string)$review['review_text'];
                $date = (string)$review['review_date'];
                $sentiment = (string)$result['sentiment'];
                $score = round((float)$result['score'], 3);
                $theme = (string)$result['theme'];
                $stmt->bind_param('isssds', $reviewId, $text, $date, $sentiment, $score, $theme);
                $stmt->execute();
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        $stmt->close();
    }

    private static function truncateWords(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return '';
        }
        return implode(' ', array_slice($words, 0, self::MAX_WORDS));
    }
}
