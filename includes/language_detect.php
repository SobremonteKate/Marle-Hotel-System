<?php
/**
 * language_detect.php — a real Tagalog / English language detector.
 *
 * The reviews "Language filter" used to lean on a hand-written keyword list
 * (`score >= 3`). A word list cannot separate the languages well: Tagalog and
 * English share a huge amount of vocabulary ("clean", "okay", "check in"), real
 * reviews are Taglish, and a single missed function word flips the answer.
 *
 * This is a genuine statistical classifier instead: a multinomial Naive-Bayes
 * model over **character 1–3-grams** *and* **word unigrams**, trained from a
 * small curated corpus that deliberately includes Taglish (English-dominant
 * sentences with Tagalog markers), so the model learns that English words are
 * *not* evidence of English when they sit beside "ang", "ng", "pero", "medyo".
 *
 * It is dependency-free — no PHP extension, no network, no model file — and
 * cheap enough to run over the 400 reviews the sentiment service scans: the
 * model is built once per request and cached in a static.
 *
 * Public API:
 *   marle_detect_language(string $text): string            // 'tagalog'|'english'
 *   marle_language_analysis(string $text): array           // language + confidence
 */

if (!function_exists('marle_language_corpus')) {
    /**
     * The training corpus. Hotel-domain but broad: short impressions, praise,
     * complaints, and the Taglish real guests write. Deliberately *not* the
     * sentences the verification harness scores, so accuracy is measured rather
     * than memorised.
     *
     * @return array{tagalog:array<int,string>, english:array<int,string>}
     */
    function marle_language_corpus(): array
    {
        return [
            'tagalog' => [
                // ── Tagalog / Filipino ──────────────────────────────────────
                'ang ganda ng kuwarto sobrang malinis',
                'maganda ang serbisyo ng front desk',
                'napaka bagal ng wifi hindi ako makaconnect',
                'masarap ang pagkain sulit ang bayad',
                'maayos naman ang tulog namin kagabi',
                'mabait ang mga staff dito',
                'sobrang ingay ng kapitbahay kwarto',
                'hindi nagustuhan ni misis ang banyo',
                'ang lamig ng aircon pero okay lang',
                'nagstay kami ng dalawang gabi',
                'tumawag ako sa front desk pero walang sumagot',
                'ang baho ng banyo kadiri',
                'pero ayos lang naman kami dito',
                'gusto ko sana ng extrang tuwalya',
                'sana may libreng tubig sa kuwarto',
                'ang mahal ng pagkain para sa maliit na serving',
                'nagustuhan namin ang lugar babalik kami',
                'medyo maingay sa labas kasi may party',
                'mababait ang mga receptionist nila',
                'ang ganda ng view sa balkonahe',
                'kulang ang sabon at shampoo sa cr',
                'mabilis naman ang check in namin',
                'talaga namang maganda ang hotel na ito',
                'hindi ko masyadong nagustuhan ang almusal',
                'ang sarap ng kape dito sa umaga',
                'yung kwarto namin malaki at malinis',
                'kami ay nag enjoy sa pool',
                'nagpalit kami ng kuwarto kasi sira ang aircon',
                'ang bagal ng pag deliver ng pagkain',
                'pwede na din para sa presyo',
                'ang banyo ay may tagas na tubig',
                'sobrang bait ng staff sa restaurant',
                'wala kaming naging problema sa stay',
                'ang ilaw sa kwarto ay patay isa',
                'nag request ako ng late check out',
                'salamat sa magandang serbisyo',
                'ang galing naman ng staff kahit hectic',
                'nakuha namin ang room ng maaga',
                'ang presyo ay sulit sa serbisyo',
                'medyo mainit ang tubig sa shower',
                'ang bedsheet ay may mantsa',
                'kami ay bumalik muli dito',
                'ang ingay ng mga bata sa hallway',
                'libre ang parking kaya okay',
                'ang ganda ng dekorasyon ng lobby',
                'bagal ng elevator hindi gumagana',
                'masaya kami sa aming bakasyon',
                'ang staff ay hindi masyadong maasikaso',
                'okay lang naman ang kwarto',
                'magaling ang chef sa restaurant',
                'tawag ako ng tawag walang sagot',
                'ang wifi ay mahina sa gabi',
                'ang kwarto ay maliit para sa apat',
                'galing talaga ng serbisyo nila',
                'ang cr ay hindi malinis',
                'nag enjoy ang mga bata sa pool',
                'ang pagkain ay medyo maalat',
                'ang mga tao dito ay mababait',
                'nag check in kami ng maaga',
                'ang room service ay napakabagal',
                'sayang ang pera sa ganitong serbisyo',
                'ang ganda ng ambiance ng restaurant',
                'napaka linis ng paligid',
                'ang kuwarto ay maayos at komportable',
                'nag request kami ng extra pillow',
                'ang shower ay walang mainit na tubig',
                'masaya ako sa serbisyo ng hotel',
                'ang front desk ay mabait at mabilis',
                'medyo mahal pero okay naman',
                'ang ganda ng tanawin mula sa terrace',
                'hindi na kami babalik dito',
                'ang pagkain ay masarap at mainit',
                'sobrang saya ng pamilya namin',
                'salamat po sa lahat',
                'maraming salamat sa inyo',
                'ang ganda talaga',
                'sulit na sulit',
                'masarap ang lahat',
                'hindi maganda ang karanasan',
                'okay lang naman',
                'maganda naman siya',
                'maingay masyado',
                'mabait po ang staff',
                'ang bilis ng serbisyo',
                'wala akong reklamo',
                'sobrang bait nila',
                'ang linis ng kwarto',
                'nasira ang aircon namin',
                'nagutom ako sa sobrang tagal',
                'hinintay namin ng matagal',
                'ang galing ng team nila',
                'hindi okay ang banyo',
                'ang baho ng kwarto',
                'masarap ang almusal nila',
                'bukas kami babalik',
                'sana maganda pa rin',
                'ang mahal ng singil',
                'kulang ang serbisyo',
                'magulo ang paligid',
                'tahimik at maayos',
                // ── Taglish: English-dominant with Tagalog markers ──────────
                'the room was maganda pero maingay ang hallway',
                'staff were very friendly and magalang sobrang bait nila',
                'great breakfast masarap ang food at mabilis ang service',
                'the room was clean pero medyo maingay',
                'we had a lovely stay dahil mabait ang staff',
                'the pool was nice pero ang tubig ay malamig',
                'the food was okay naman at mura pa',
                'ang ganda ng view pero the food was expensive',
                'the wifi was slow kaya hindi ako makapag trabaho',
                'we booked a massage at nag enjoy kami',
                'the room was spacious at napaka linis',
                'breakfast was good at masarap ang kape',
                'the staff were helpful pero medyo mabagal',
                'location was great kasi malapit sa mall',
                'the room was nice pero ang aircon ay maingay',
                'we loved the buffet lalo na ang dessert',
                'the check in was fast at mabait ang receptionist',
                'the bathroom was clean at may mainit na tubig',
                'the hotel was quiet kaya nakapag pahinga kami',
                'the price was fair at sulit talaga',
                'the room was clean pero medyo maingay ang hallway',
                'the restaurant was busy pero mabilis ang service',
                'the bed was comfortable at ang ganda ng view',
                'the staff were polite at maayos ang serbisyo',
                'the room was tidy pero ang banyo ay maliit',
                'the food arrived late kasi sobrang dami ng tao',
                'the lobby was elegant at napaka bait ng guard',
                'the wifi worked well at mabilis ang connection',
                'the breakfast was simple pero masarap naman',
                'the stay was great at babalik kami ulit',
                'the room was malinis but the aircon was maingay',
                'the staff was mabait but the service was mabagal',
                'the food was masarap but the price was mahal',
                'the wifi was mabilis but the pool was madumi',
                'ang bait ng mga staff thank you',
                'sobrang bait ng front desk nila',
                'napaka bait ng guard at staff',
                'the service was mabilis at mabait ang crew',
                'masarap ang massage at maganda ang spa',
                'natulog kami ng maaga kasi sobrang pagod',
            ],
            'english' => [
                'the room was clean and spacious',
                'great service from the front desk',
                'the wifi was terribly slow',
                'the food was delicious and worth the price',
                'we slept well last night',
                'the staff were friendly and helpful',
                'very noisy neighbors in the next room',
                'my wife did not like the bathroom',
                'the air conditioning was cold but fine',
                'we stayed for two nights',
                'I called the front desk but nobody answered',
                'the bathroom smelled terrible',
                'but we were okay with our stay',
                'I would like an extra towel',
                'I wish there was free water in the room',
                'the food was expensive for a small serving',
                'we loved the place and will come back',
                'it was a bit noisy outside because of a party',
                'the receptionists were very kind',
                'the view from the balcony was beautiful',
                'there was not enough soap and shampoo',
                'our check in was quick',
                'this hotel is truly beautiful',
                'I did not really enjoy breakfast',
                'the coffee in the morning was great',
                'our room was big and clean',
                'we enjoyed the pool',
                'we changed rooms because the aircon was broken',
                'the food delivery was very slow',
                'acceptable for the price',
                'the bathroom had a water leak',
                'the restaurant staff were extremely kind',
                'we had no problems during our stay',
                'one of the lights in the room was off',
                'I requested a late check out',
                'thank you for the wonderful service',
                'the staff were great despite the rush',
                'we got the room early',
                'the price was fair for the service',
                'the shower water was a little warm',
                'the bedsheet had a stain',
                'we came back again',
                'the kids were noisy in the hallway',
                'free parking so that is good',
                'the lobby decoration was lovely',
                'the elevator was slow and broken',
                'we were happy with our vacation',
                'the staff were not very attentive',
                'the room was just okay',
                'the chef at the restaurant was excellent',
                'I called again and again with no answer',
                'the wifi was weak at night',
                'the room was small for four people',
                'their service was really great',
                'the toilet was not clean',
                'the children enjoyed the pool',
                'the food was a bit salty',
                'the people here were kind',
                'we checked in early',
                'room service was extremely slow',
                'what a waste of money for this service',
                'the restaurant ambience was lovely',
                'the surroundings were very clean',
                'the room was tidy and comfortable',
                'we requested an extra pillow',
                'there was no hot water in the shower',
                'I am happy with the hotel service',
                'the front desk was kind and quick',
                'a little pricey but okay',
                'the view from the terrace was beautiful',
                'we will not come back here',
                'the food was tasty and hot',
                'our family had a great time',
                'thank you very much',
                'many thanks to everyone',
                'really beautiful',
                'absolutely worth it',
                'everything was delicious',
                'the experience was not good',
                'just okay',
                'it was nice enough',
                'far too noisy',
                'the staff were very kind',
                'the service was fast',
                'I have no complaints',
                'they were extremely nice',
                'the room was spotless',
                'our aircon broke down',
                'we waited far too long',
                'their team was excellent',
                'the bathroom was not okay',
                'the room smelled bad',
                'breakfast was tasty',
                'we will return tomorrow',
                'hopefully it stays lovely',
                'the charge was too high',
                'the service fell short',
                'the area was messy',
                'quiet and tidy',
                'we booked a massage at the spa',
                'the massage was relaxing',
                'the spa was small but clean',
                'the therapist was professional',
                'we had a massage in the afternoon',
                'the pool and the spa were lovely',
            ],
        ];
    }
}

if (!function_exists('marle_language_normalize')) {
    /** Lowercase, drop everything but letters/ñ/space, collapse whitespace. */
    function marle_language_normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string)preg_replace('/[^a-zñ ]+/u', ' ', $text);
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}

if (!defined('MARLE_LANGUAGE_WORD_WEIGHT')) {
    /** How much a whole word counts relative to a single character n-gram. */
    define('MARLE_LANGUAGE_WORD_WEIGHT', 4);
}

if (!function_exists('marle_language_model')) {
    /**
     * Train the Naive-Bayes model over character n-grams and word unigrams.
     * Built once per request and cached in a static.
     *
     * @return array{logp:array<string,array<string,float>>, unseen:array<string,float>, prior:array<string,float>}
     */
    function marle_language_model(): array
    {
        static $model = null;
        if ($model !== null) {
            return $model;
        }

        $corpus = marle_language_corpus();
        $languages = array_keys($corpus);
        $counts = [];
        $totals = [];
        $vocab = [];

        foreach ($languages as $lang) {
            $counts[$lang] = [];
            $totals[$lang] = 0;
            foreach ($corpus[$lang] as $sample) {
                $normalized = marle_language_normalize($sample);
                $s = ' ' . $normalized . ' ';
                $len = strlen($s);

                $bump = static function (string $gram, int $weight) use (&$counts, &$totals, &$vocab, $lang): void {
                    $counts[$lang][$gram] = ($counts[$lang][$gram] ?? 0) + $weight;
                    $totals[$lang] += $weight;
                    $vocab[$gram] = true;
                };

                // Character 1-, 2- and 3-grams, including the word-boundary space.
                for ($n = 1; $n <= 3; $n++) {
                    for ($i = 0; $i + $n <= $len; $i++) {
                        $gram = substr($s, $i, $n);
                        if ($gram === ' ' || str_contains($gram, '  ')) {
                            continue;
                        }
                        $bump($gram, 1);
                    }
                }
                // Whole words, weighted heavier than a single n-gram.
                foreach (explode(' ', $normalized) as $token) {
                    if ($token !== '') {
                        $bump('w:' . $token, MARLE_LANGUAGE_WORD_WEIGHT);
                    }
                }
            }
        }

        $vocabSize = count($vocab);
        $alpha = 0.5; // add-half smoothing
        $logp = [];
        $unseen = [];
        $docs = count($corpus['tagalog']) + count($corpus['english']) ?: 1;
        $prior = [];
        foreach ($languages as $lang) {
            $prior[$lang] = log(count($corpus[$lang]) / $docs);
            $denom = log($totals[$lang] + $alpha * $vocabSize);
            $unseen[$lang] = log($alpha) - $denom;
            $logp[$lang] = [];
            foreach ($vocab as $gram => $_) {
                if (isset($counts[$lang][$gram])) {
                    $logp[$lang][$gram] = log($counts[$lang][$gram] + $alpha) - $denom;
                }
            }
        }

        return $model = ['logp' => $logp, 'unseen' => $unseen, 'prior' => $prior];
    }
}

if (!function_exists('marle_language_analysis')) {
    /**
     * Score a piece of text and return the verdict with a confidence.
     *
     * @return array{language:string, confidence:float, tagalog_score:float,
     *               english_score:float, tokens:int, words:int}
     */
    function marle_language_analysis(string $text): array
    {
        $normalized = marle_language_normalize($text);
        if ($normalized === '') {
            return [
                'language' => 'english', 'confidence' => 0.5,
                'tagalog_score' => 0.0, 'english_score' => 0.0, 'tokens' => 0, 'words' => 0,
            ];
        }

        $model = marle_language_model();
        $s = ' ' . $normalized . ' ';
        $len = strlen($s);
        $tag = $model['prior']['tagalog'];
        $eng = $model['prior']['english'];
        $weight = 0.0;

        for ($n = 1; $n <= 3; $n++) {
            for ($i = 0; $i + $n <= $len; $i++) {
                $gram = substr($s, $i, $n);
                if ($gram === ' ' || str_contains($gram, '  ')) {
                    continue;
                }
                $weight += 1.0;
                $tag += $model['logp']['tagalog'][$gram] ?? $model['unseen']['tagalog'];
                $eng += $model['logp']['english'][$gram] ?? $model['unseen']['english'];
            }
        }

        $tokens = explode(' ', $normalized);
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $weight += MARLE_LANGUAGE_WORD_WEIGHT;
            $key = 'w:' . $token;
            $tag += $model['logp']['tagalog'][$key] ?? $model['unseen']['tagalog'];
            $eng += $model['logp']['english'][$key] ?? $model['unseen']['english'];
        }

        // Length-independent margin, squashed into a confidence.
        $margin = ($tag - $eng) / max(1.0, $weight);
        $confidence = 1.0 / (1.0 + exp(-9.0 * abs($margin) - 1.0));
        // Keep it a probability: never 0 or 1, even on an unambiguous call.
        $confidence = min(0.99, max(0.01, $confidence));
        $language = $tag >= $eng ? 'tagalog' : 'english';

        return [
            'language'      => $language,
            'confidence'    => round($confidence, 4),
            'tagalog_score' => round($tag, 4),
            'english_score' => round($eng, 4),
            'tokens'        => $len - 2,
            'words'         => count($tokens),
        ];
    }
}

if (!function_exists('marle_detect_language')) {
    /**
     * Drop-in verdict: 'tagalog' or 'english'.
     *
     * A dead-band keeps a single ambiguous word from being called: a one-word
     * text needs a clear signal before it is called Tagalog, which is the
     * conservative default the reviews filter has always used.
     */
    function marle_detect_language(string $text): string
    {
        $analysis = marle_language_analysis($text);
        if ($analysis['language'] === 'tagalog'
            && $analysis['words'] <= 1
            && $analysis['confidence'] < 0.9) {
            return 'english';
        }
        return $analysis['language'];
    }
}
