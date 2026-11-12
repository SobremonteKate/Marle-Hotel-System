<?php
/**
 * sentiment_insights.php — the half of Marle AI that was missing.
 *
 * WHAT THIS IS FOR
 * ----------------
 * The sentiment pipeline classifies individual reviews and stores the result in
 * `review_sentiment_results` (279 rows at the time of writing): sentiment,
 * sentiment_score, and a `theme` from a fixed enum — cleanliness, staff,
 * location, amenities, value, food, noise, maintenance, other. Nothing read it.
 * The only surface was the per-review modal on the Reviews page, and the
 * dashboard aggregates reviews without ever touching sentiment. So the project
 * could say "this review is negative"; it could not say *what the guests are
 * unhappy about, whether it is getting worse, or which one to fix first*.
 *
 * This include answers exactly that, and nothing else:
 *   - the theme x sentiment matrix for a window (what guests complain about);
 *   - the negative share now versus the previous window of equal length
 *     (whether it is getting better or worse);
 *   - a ranked "fix first" list — negative count, most recent first;
 *   - the single most recent negative review, with its room and the guest's own
 *     words, so the panel ends in something a person can act on.
 *
 * IT REUSES THE SERVICE RATHER THAN RE-AGGREGATING
 * ------------------------------------------------
 * `LunaSentimentService::summary()` already computes the window totals, the
 * three sentiment counts, their percentages and the average score, so those
 * numbers come from one place. What it cannot give is the *split* of a theme by
 * sentiment (its `themes` key counts a theme, not a theme's negatives) — that is
 * the one aggregate this file adds, in a single GROUP BY.
 *
 * DATA IS AGGREGATED IN SQL, AND THE WINDOW COMES FROM THE HOTEL'S CLOCK
 * ---------------------------------------------------------------------
 * The window bounds are `marle_hotel_date(-N, $conn)`, which asks MySQL for
 * CURDATE() ± N days — the same clock that stamped `review_date`, never PHP's
 * and never the viewer's (includes/server_clock.php). Every value is bound, and
 * no date is ever rendered from PHP's own "now".
 *
 * NO JAVASCRIPT. The panel is server-rendered: it works before any script runs,
 * it cannot drift from the numbers in the database, and it adds nothing for the
 * language switch's script-stability check to catch.
 *
 * TRANSLATION: `ui_t()` inline pairs, not the phrase table. ui_i18n.php says
 * new markup should carry its translation at the point of use, and this panel is
 * new — so it does. The one thing left untranslated is the theme name itself,
 * which is an enum value out of the database (the same carve-out the table
 * already makes for stored room types and machine codes).
 */

require_once __DIR__ . '/server_clock.php';
require_once __DIR__ . '/../luna_sentiment_service.php';

if (!function_exists('si_t')) {
    /**
     * Inline English | Tagalog pair. Delegates to ui_i18n's ui_t() when the page
     * is running under the switch; falls back to English so this include is
     * still safe anywhere it is not (a script, a test, a future page).
     */
    function si_t(string $en, string $tl): string
    {
        return function_exists('ui_t') ? ui_t($en, $tl) : $en;
    }
}

if (!function_exists('marle_sentiment_insights')) {
    /**
     * Everything the panel shows, gathered in four small queries.
     *
     * @param int $days Length of the window, and of the comparison window before it.
     * @return array{
     *   days:int, from:?string, to:?string, total:int, negative_share:?float,
     *   previous:array{total:int, negative:int, share:?float}, trend:?string,
     *   average_score:?float, sentiments:array<string,int>, themes:array<int,array>,
     *   priorities:array<int,array>, latest_negative:?array, has_data:bool
     * }
     */
    function marle_sentiment_insights(mysqli $conn, int $days = 90): array
    {
        $days = max(1, $days);

        // A panel is not worth a page. mysqli runs in exception mode on this
        // project (a bad statement throws rather than returning false), and with
        // display_errors off a thrown error would truncate the Reviews page at
        // the point of failure — which is exactly what the first version of this
        // file did. So the whole aggregation is guarded: on any failure the
        // panel says so in one line and the page it sits on renders whole.
        try {
            return marle_sentiment_insights_query($conn, $days);
        } catch (Throwable $e) {
            error_log('sentiment insights: ' . $e->getMessage());
            return [
                'days' => $days, 'from' => null, 'to' => null,
                'total' => 0, 'average_score' => null,
                'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                'negative_share' => null,
                'previous' => ['total' => 0, 'negative' => 0, 'share' => null],
                'trend' => null, 'themes' => [], 'priorities' => [],
                'latest_negative' => null, 'has_data' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('marle_sentiment_insights_query')) {
    /** The queries behind marle_sentiment_insights(); split out so the wrapper
     *  above can turn any failure into a one-line notice. */
    function marle_sentiment_insights_query(mysqli $conn, int $days): array
    {
        $to   = marle_hotel_date(0, $conn);                 // the hotel's today
        $from = marle_hotel_date(-$days, $conn);            // window start, in SQL
        $prevTo   = marle_hotel_date(-$days - 1, $conn);    // day before the window
        $prevFrom = marle_hotel_date(-$days * 2, $conn);    // equal window before it

        // ── The window totals: reused from the service, not re-derived here ──
        $summary = LunaSentimentService::summary($conn, $from, $to);
        $total   = (int)($summary['count'] ?? 0);
        $sentiments = $summary['sentiments'] ?? ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $negative = (int)($sentiments['negative'] ?? 0);

        // ── Theme x sentiment, plus how recent each theme's last negative is ──
        // One pass. `last_negative` is what lets the ranking be honest about
        // recency: two themes with the same number of complaints are not equally
        // urgent if one of them was complained about last week and the other
        // stopped eight months ago.
        $themes = [];
        $sql = 'SELECT theme,
                       SUM(sentiment = \'negative\') AS negative,
                       SUM(sentiment = \'neutral\')  AS neutral,
                       SUM(sentiment = \'positive\') AS positive,
                       MAX(CASE WHEN sentiment = \'negative\' THEN review_date END) AS last_negative,
                       ROUND(AVG(sentiment_score), 2) AS average_score
                  FROM review_sentiment_results
                 WHERE review_date BETWEEN ? AND ?
              GROUP BY theme
              -- The aggregates are repeated rather than ordered by their
              -- aliases: MariaDB refuses an alias for a group function used
              -- inside an ORDER BY expression, and that is what broke the first
              -- version of this query (the error names the alias directly).
              ORDER BY SUM(sentiment = \'negative\') DESC, COUNT(*) DESC';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $row) {
                $themeTotal = (int)$row['negative'] + (int)$row['neutral'] + (int)$row['positive'];
                $themes[] = [
                    'theme'         => (string)$row['theme'],
                    'negative'      => (int)$row['negative'],
                    'neutral'       => (int)$row['neutral'],
                    'positive'      => (int)$row['positive'],
                    'total'         => $themeTotal,
                    'negative_share' => $themeTotal > 0 ? round(((int)$row['negative'] / $themeTotal) * 100, 1) : 0.0,
                    'last_negative' => $row['last_negative'] !== null ? (string)$row['last_negative'] : null,
                    'average_score' => $row['average_score'] !== null ? (float)$row['average_score'] : null,
                ];
            }
        }

        // ── The window before this one, for the only trend that matters ──
        $previous = ['total' => 0, 'negative' => 0, 'share' => null];
        $sql = 'SELECT COUNT(*) AS total, SUM(sentiment = \'negative\') AS negative
                  FROM review_sentiment_results
                 WHERE review_date BETWEEN ? AND ?';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ss', $prevFrom, $prevTo);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $previous['total']    = (int)$row['total'];
                $previous['negative'] = (int)($row['negative'] ?? 0);
                if ($previous['total'] > 0) {
                    $previous['share'] = round(($previous['negative'] / $previous['total']) * 100, 1);
                }
            }
        }

        $negativeShare = $total > 0 ? round(($negative / $total) * 100, 1) : null;

        // Improvement is the only direction worth a word here: a rising negative
        // share is a problem, a falling one is the panel's one piece of good
        // news, and a sub-point move is noise rather than a trend.
        $trend = null;
        if ($negativeShare !== null && $previous['share'] !== null) {
            $delta = round($negativeShare - $previous['share'], 1);
            if ($delta <= -1.0)     { $trend = 'improving'; }
            elseif ($delta >= 1.0)  { $trend = 'worsening'; }
            else                    { $trend = 'steady'; }
        }

        // ── What to fix first: negative volume, most recent first ──
        // The rule is deliberately boring so it can be defended: rank by how many
        // guests complained, and let recency separate the ties.
        $ranked = array_values(array_filter($themes, static fn(array $t): bool => $t['negative'] > 0));
        usort($ranked, static function (array $a, array $b): int {
            if ($a['negative'] !== $b['negative']) {
                return $b['negative'] <=> $a['negative'];
            }
            return strcmp((string)$b['last_negative'], (string)$a['last_negative']);
        });
        $priorities = array_slice($ranked, 0, 3);

        // ── The most recent complaint, in the guest's own words ──
        $latestNegative = null;
        $sql = 'SELECT s.theme, s.sentiment_score, s.review_date, r.room_no, r.guest_name, r.rating, r.review_text
                  FROM review_sentiment_results s
                  JOIN reviewstbl r ON r.id = s.review_id
                 WHERE s.sentiment = \'negative\' AND s.review_date BETWEEN ? AND ?
              ORDER BY s.review_date DESC, s.id DESC
                 LIMIT 1';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $text = trim((string)$row['review_text']);
                $latestNegative = [
                    'theme'  => (string)$row['theme'],
                    'room_no' => (string)$row['room_no'],
                    'guest_name' => (string)$row['guest_name'],
                    'rating' => (int)$row['rating'],
                    'score'  => (float)$row['sentiment_score'],
                    // A card is not a review page: enough to recognise it, with
                    // the full text one click away on the list below.
                    'snippet' => mb_strlen($text) > 220 ? mb_substr($text, 0, 220) . '…' : $text,
                    'when'   => (string)$row['review_date'],
                ];
            }
        }

        return [
            'days'           => $days,
            'error'          => null,
            'from'           => $from,
            'to'             => $to,
            'total'          => $total,
            'average_score'  => $summary['average_score'] ?? null,
            'sentiments'     => $sentiments,
            'negative_share' => $negativeShare,
            'previous'       => $previous,
            'trend'          => $trend,
            'themes'         => $themes,
            'priorities'     => $priorities,
            'latest_negative' => $latestNegative,
            'has_data'       => $total > 0,
        ];
    }
}

if (!function_exists('si_theme_label')) {
    /** `cleanliness` => `Cleanliness`. The enum value is stored lowercase. */
    function si_theme_label(string $theme): string
    {
        return ucfirst(str_replace('_', ' ', $theme));
    }
}

if (!function_exists('si_theme_tl')) {
    /**
     * Tagalog label for a theme. Returned as a plain string so the fallback for
     * a theme added to the enum later is the stored value, not an empty cell.
     */
    function si_theme_tl(string $theme): string
    {
        $map = [
            'cleanliness' => 'Kalinisan',
            'staff'       => 'Mga Kawani',
            'location'    => 'Lokasyon',
            'amenities'   => 'Mga Pasilidad',
            'value'       => 'Halaga',
            'food'        => 'Pagkain',
            'noise'       => 'Ingay',
            'maintenance' => 'Maintenance',
            'other'       => 'Iba pa',
        ];
        return $map[$theme] ?? si_theme_label($theme);
    }
}

if (!function_exists('marle_render_sentiment_insights')) {
    /**
     * The panel, as HTML. Server-rendered, no JavaScript, no dependence on any
     * page-specific CSS beyond Bootstrap 5.3, which every staff page already
     * loads — the one small style block is scoped to `.si-panel`.
     */
    function marle_render_sentiment_insights(array $i): string
    {
        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $days = (int)$i['days'];

        // Theme label: translated when the switch is on, stored value otherwise.
        $themeLabel = static function (string $theme): string {
            return strtolower($theme) === $theme && function_exists('ui_is_tl') && ui_is_tl()
                ? si_theme_tl($theme)
                : (function_exists('ui_is_tl') && ui_is_tl() ? si_theme_tl($theme) : si_theme_label($theme));
        };

        // The comparison phrase says "the window before" rather than naming the
        // length: $days is a parameter, and a hard-coded "90 days" here was the
        // first version's bug — it would have lied the moment a caller asked for
        // 30.
        $trendText = null; $trendClass = 'text-muted'; $trendIcon = 'bi-dash-lg';
        if ($i['trend'] === 'improving') { $trendText = si_t('fewer complaints than the window before', 'mas kaunting reklamo kaysa nakaraang panahon'); $trendClass = 'text-success'; $trendIcon = 'bi-arrow-down-right'; }
        if ($i['trend'] === 'worsening') { $trendText = si_t('more complaints than the window before', 'mas maraming reklamo kaysa nakaraang panahon'); $trendClass = 'text-danger'; $trendIcon = 'bi-arrow-up-right'; }
        if ($i['trend'] === 'steady')    { $trendText = si_t('about the same as the window before', 'halos kapareho ng nakaraang panahon'); }

        ob_start();
        ?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         SENTIMENT INSIGHTS — what the classified reviews add up to
         Server-rendered by includes/sentiment_insights.php (no JavaScript).
    ═══════════════════════════════════════════════════════════════════════ -->
    <style>
        .si-panel .si-tile { border: 0; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .si-panel .si-big { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .si-panel .si-label { font-size: .74rem; letter-spacing: .06em; text-transform: uppercase; color: #8a8175; font-weight: 600; }
        .si-panel .si-bar { height: 8px; border-radius: 999px; background: #efe7e2; overflow: hidden; display: flex; }
        .si-panel .si-bar > span { display: block; height: 100%; }
        .si-panel .si-neg { background: #dc3545; } .si-panel .si-neu { background: #ffb60a; } .si-panel .si-pos { background: #198754; }
        .si-panel table.si-table td, .si-panel table.si-table th { vertical-align: middle; }
        .si-panel .si-rank { width: 26px; height: 26px; border-radius: 50%; background: #933310; color: #fff; font-size: .78rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
    </style>

    <div class="content p-4 si-panel">
        <div class="container">

            <div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-2">
                <div>
                    <h5 class="mb-1 fw-bold"><?= $h(si_t('Sentiment Insights', 'Mga Pananaw sa Damdamin')) ?></h5>
                    <p class="text-muted mb-0 small">
                        <?= $h(si_t('Every classified review, grouped by what it was about.', 'Bawat klasipikadong puna, pinagsama ayon sa paksa.')) ?>
                    </p>
                </div>
                <span class="badge rounded-pill" style="background:#f8f4f1;color:#8a8175;font-weight:600;">
                    <?= $h(si_t('Last ', 'Huling ') . $days . si_t(' days', ' araw')) ?>
                </span>
            </div>

        <?php if (!empty($i['error'])): ?>
            <!-- The aggregation failed. Say so where the numbers would be, and
                 leave the reason visible rather than swallowing it — the page
                 around this panel is unaffected and keeps working. -->
            <div class="card si-tile">
                <div class="card-body py-3">
                    <p class="mb-0 small text-muted">
                        <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                        <?= $h(si_t('The insights could not be computed right now.', 'Hindi makwenta ang mga pananaw sa ngayon.')) ?>
                    </p>
                    <p class="mb-0 text-muted" style="font-size:.72rem;"><?= $h($i['error']) ?></p>
                </div>
            </div>
        <?php elseif (!$i['has_data']): ?>
            <div class="card si-tile">
                <div class="card-body text-center py-4">
                    <i class="bi bi-bar-chart-line text-muted" style="font-size:1.6rem;"></i>
                    <p class="mb-0 mt-2 text-muted">
                        <?= $h(si_t('No classified reviews in this window yet. Run the sentiment analysis below.', 'Wala pang klasipikadong puna sa panahong ito. Patakbuhin ang pagsusuri ng damdamin sa ibaba.')) ?>
                    </p>
                </div>
            </div>
        <?php else: ?>

            <!-- ── Three tiles: how much was read, how bad it is, and which theme leads ── -->
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card si-tile h-100">
                        <div class="card-body">
                            <div class="si-label"><?= $h(si_t('Reviews analysed', 'Mga punang sinuri')) ?></div>
                            <div class="si-big mt-2"><?= number_format($i['total']) ?></div>
                            <div class="text-muted small mt-2">
                                <?= $h(si_t('positive', 'positibo')) ?> <?= (int)$i['sentiments']['positive'] ?>
                                · <?= $h(si_t('neutral', 'neutral')) ?> <?= (int)$i['sentiments']['neutral'] ?>
                                · <?= $h(si_t('negative', 'negatibo')) ?> <?= (int)$i['sentiments']['negative'] ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="card si-tile h-100">
                        <div class="card-body">
                            <div class="si-label"><?= $h(si_t('Negative share', 'Bahagdan ng negatibo')) ?></div>
                            <div class="si-big mt-2"><?= $i['negative_share'] === null ? '—' : $h($i['negative_share']) . '%' ?></div>
                            <?php if ($trendText !== null): ?>
                                <div class="small mt-2 <?= $trendClass ?>">
                                    <i class="bi <?= $trendIcon ?>"></i> <?= $h($trendText) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small mt-2"><?= $h(si_t('no earlier window to compare', 'walang naunang panahon na maihahambing')) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="card si-tile h-100">
                        <div class="card-body">
                            <div class="si-label"><?= $h(si_t('Fix first', 'Unahing ayusin')) ?></div>
                            <?php if ($i['priorities']): ?>
                                <div class="si-big mt-2"><?= $h($themeLabel($i['priorities'][0]['theme'])) ?></div>
                                <div class="text-muted small mt-2">
                                    <?= (int)$i['priorities'][0]['negative'] ?>
                                    <?= $h(si_t('complaints in this window', 'reklamo sa panahong ito')) ?>
                                </div>
                            <?php else: ?>
                                <div class="si-big mt-2 text-success"><?= $h(si_t('Nothing', 'Wala')) ?></div>
                                <div class="text-muted small mt-2"><?= $h(si_t('no negative review in this window', 'walang negatibong puna sa panahong ito')) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">

                <!-- ── Theme by sentiment: where the complaints actually are ── -->
                <div class="col-12 col-lg-7">
                    <div class="card si-tile h-100">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3"><?= $h(si_t('What guests talk about', 'Pinag-uusapan ng mga bisita')) ?></h6>
                            <div class="table-responsive">
                                <table class="table table-sm si-table align-middle mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th><?= $h(si_t('Theme', 'Paksa')) ?></th>
                                            <th class="text-end"><?= $h(si_t('Reviews', 'Puna')) ?></th>
                                            <th style="min-width:120px;"><?= $h(si_t('Negative', 'Negatibo')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($i['themes'] as $t): ?>
                                        <?php
                                            $negPct = $t['total'] > 0 ? round(($t['negative'] / $t['total']) * 100, 1) : 0;
                                            $neuPct = $t['total'] > 0 ? round(($t['neutral'] / $t['total']) * 100, 1) : 0;
                                            $posPct = max(0, round(100 - $negPct - $neuPct, 1));
                                        ?>
                                        <tr>
                                            <!-- The theme name is an enum value from the database
                                                 (see the note at the top of this file), so it is
                                                 rendered as stored rather than run through the table. -->
                                            <td class="fw-semibold"><?= $h($themeLabel($t['theme'])) ?></td>
                                            <td class="text-end text-muted"><?= (int)$t['total'] ?></td>
                                            <td>
                                                <div class="si-bar" role="img"
                                                     aria-label="<?= $h($t['negative'] . ' of ' . $t['total']) ?>">
                                                    <span class="si-neg" style="width:<?= $negPct ?>%"></span>
                                                    <span class="si-neu" style="width:<?= $neuPct ?>%"></span>
                                                    <span class="si-pos" style="width:<?= $posPct ?>%"></span>
                                                </div>
                                                <div class="small text-muted mt-1">
                                                    <?= (int)$t['negative'] ?> / <?= (int)$t['total'] ?>
                                                    (<?= $h($negPct) ?>%)
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── The advice, and the review that prompted it ── -->
                <div class="col-12 col-lg-5">
                    <div class="card si-tile h-100">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3"><?= $h(si_t('Fix first', 'Unahing ayusin')) ?></h6>

                            <?php if ($i['priorities']): ?>
                                <ol class="list-unstyled mb-4">
                                    <?php foreach ($i['priorities'] as $n => $p): ?>
                                        <li class="d-flex gap-2 align-items-start mb-2">
                                            <span class="si-rank"><?= $n + 1 ?></span>
                                            <span>
                                                <span class="fw-semibold"><?= $h($themeLabel($p['theme'])) ?></span>
                                                <span class="text-muted small d-block">
                                                    <?= (int)$p['negative'] ?> <?= $h(si_t('negative', 'negatibo')) ?>
                                                    <?php if ($p['last_negative'] !== null): ?>
                                                        · <?= $h(si_t('latest ', 'pinakahuli ')) . $h(date('M j, Y', strtotime($p['last_negative']))) ?>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                                <p class="text-muted small mb-4">
                                    <?= $h(si_t('Ranked by how many guests complained; ties go to the most recent.', 'Nakapangkat ayon sa dami ng reklamo; ang pinakahuli ang nauuna kapag pareho.')) ?>
                                </p>
                            <?php else: ?>
                                <p class="text-success mb-4">
                                    <i class="bi bi-check-circle"></i>
                                    <?= $h(si_t('No negative review in this window.', 'Walang negatibong puna sa panahong ito.')) ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($i['latest_negative']): ?>
                                <h6 class="fw-bold mb-2"><?= $h(si_t('Most recent complaint', 'Pinakahuling reklamo')) ?></h6>
                                <div class="border rounded-3 p-3" style="background:#fcfaf8;">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small text-muted">
                                            <?= $h(si_t('Room ', 'Silid ')) ?><?= $h($i['latest_negative']['room_no']) ?>
                                            · <?= $h($themeLabel($i['latest_negative']['theme'])) ?>
                                        </span>
                                        <span class="small text-muted">
                                            <?= $h(date('M j, Y', strtotime($i['latest_negative']['when']))) ?>
                                        </span>
                                    </div>
                                    <p class="mb-2 small fst-italic">“<?= $h($i['latest_negative']['snippet']) ?>”</p>
                                    <div class="small text-muted">
                                        <?= (int)$i['latest_negative']['rating'] ?> ★ ·
                                        <?= $h(si_t('sentiment score ', 'iskor ng damdamin ')) ?><?= $h($i['latest_negative']['score']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>

        </div>
    </div>
        <?php
        return (string)ob_get_clean();
    }
}
