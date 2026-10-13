<?php

namespace App\Support;

use App\Models\ShippyDocument;
use App\Models\ShippyFaq;
use Illuminate\Support\Facades\Cache;

/**
 * What the Owner taught Shippy in Website → Shippy (v1.3.0): the FAQs and the Markdown documents that are switched
 * on, prepared once and kept in the cache until the Owner changes any of them. A guest's question is compared with it
 * by its words alone, with no model and no other service:
 *
 * - an FAQ whose question (or one of its other wordings) is clearly the guest's question answers as written;
 * - a document section whose heading is clearly the guest's question answers with its text;
 * - otherwise the few entries that share words with the question go to Gemini as reference notes.
 *
 * "Clearly" means nearly the same words and no other entry nearly as close: a question between two entries is left
 * to Gemini. A document can never restate a menu price: the published menu is the only source of those.
 *
 * @phpstan-import-type Answer from ShippyKnowledge
 *
 * @phpstan-type Active array{
 *     faqs: list<array{question: string, answer: string, phrasings: list<list<string>>}>,
 *     sections: list<array{title: string, heading: string, text: string, terms: list<string>}>,
 *     index: array<string, list<int>>,
 * }
 */
class ShippyKnowledgeBase
{
    public const CACHE = 'shippy:knowledge';

    /** How alike two sets of words must be to answer at once, and how far ahead of the next entry. */
    public const INSTANT = 0.7;

    public const MARGIN = 0.15;

    /** The notes sent to Gemini with one question: how many, how long each and the share of the question's words one must have. */
    public const MAX_NOTES = 3;

    public const MAX_NOTE = 500;

    private const RELEVANT = 1 / 3;

    /**
     * Words that mean the same thing in a guest's question, by the one they are counted as.
     *
     * @var array<string, list<string>>
     */
    private const SAME = [
        'deliver' => ['delivery', 'deliveries', 'padeliver', 'magdeliver', 'nagdeliver', 'nagdedeliver', 'magpadeliver'],
        'bayad' => ['payment', 'pay', 'magbayad', 'pambayad', 'bayaran'],
        'pet' => ['aso', 'dog', 'pusa', 'cat', 'alaga'],
        'parking' => ['park', 'paradahan', 'magpark', 'parkingan'],
        'reserve' => ['reservation', 'reserba', 'pareserve', 'magpareserve', 'book', 'booking'],
        'cr' => ['restroom', 'banyo', 'toilet', 'washroom'],
        'aircon' => ['airconditioned', 'airconditioning', 'ac'],
        'promo' => ['discount', 'diskwento', 'sale', 'voucher'],
        'kid' => ['bata', 'children', 'child', 'baby'],
        'spicy' => ['maanghang', 'anghang'],
        'smoking' => ['smoke', 'yosi', 'vape', 'sigarilyo'],
        'alcohol' => ['alak', 'beer', 'inuman'],
        'allergy' => ['allergen', 'allergic'],
        'price' => ['magkano', 'presyo', 'hm', 'much', 'cost', 'halaga'],
        'accept' => ['tumatanggap', 'tanggap', 'accepted', 'allowed', 'allow'],
        'bulk' => ['maramihan', 'catering', 'cater', 'handaan', 'party'],
        'holiday' => ['pista', 'pasko', 'christmas'],
        'wifi' => ['internet'],
        'where' => ['saan', 'nasaan'],
        'when' => ['kailan', 'kelan'],
    ];

    /** @var array<string, string>|null */
    private static ?array $same = null;

    public function __construct(private ShippyKnowledge $knowledge) {}

    /** Drops the prepared knowledge, so the next question reads what the Owner just saved. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * The approved answer of the one FAQ that is clearly the guest's question.
     *
     * @return Answer|null
     */
    public function faq(string $message): ?array
    {
        $faqs = $this->active()['faqs'];
        $found = $this->closest(self::terms($message), array_column($faqs, 'phrasings'));

        return $found === null ? null : ['text' => $faqs[$found]['answer'], 'items' => [], 'actions' => []];
    }

    /**
     * The text of the one document section whose heading is clearly the guest's question.
     *
     * @return Answer|null
     */
    public function section(string $message): ?array
    {
        $sections = $this->active()['sections'];
        $found = $this->closest(self::terms($message), array_map(fn (array $section): array => [$section['terms']], $sections));
        $text = $found === null ? '' : $this->clip($this->withoutMenuPrices($sections[$found]['text']), ShippyAssistant::MAX_REPLY);

        return $text === '' ? null : ['text' => $text, 'items' => [], 'actions' => []];
    }

    /**
     * The few FAQs and document sections that share words with the question, most alike first: what Gemini is given
     * to read instead of every document.
     *
     * @return list<string>
     */
    public function notes(string $message): array
    {
        $asked = self::terms($message);
        if ($asked === []) {
            return [];
        }
        $active = $this->active();
        $ranked = [];
        foreach ($active['faqs'] as $faq) {
            $shared = max(array_map(fn (array $phrasing): int => $this->shared($asked, $phrasing), $faq['phrasings']) ?: [0]);
            $ranked[] = [$shared, 'Q: '.$faq['question'].' A: '.$faq['answer']];
        }
        $tally = [];
        foreach ($asked as $term) {
            foreach ($active['index'][$term] ?? [] as $section) {
                $tally[$section] = ($tally[$section] ?? 0) + 1;
            }
        }
        foreach ($tally as $section => $shared) {
            $text = $this->withoutMenuPrices($active['sections'][$section]['text']);
            if ($text !== '') {
                $ranked[] = [$shared, $active['sections'][$section]['title'].', '.$active['sections'][$section]['heading'].': '.$text];
            }
        }
        $ranked = array_values(array_filter($ranked, fn (array $entry): bool => $entry[0] >= max(1, (int) ceil(count($asked) * self::RELEVANT))));
        usort($ranked, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(fn (array $entry): string => $this->clip($entry[1], self::MAX_NOTE), array_slice($ranked, 0, self::MAX_NOTES));
    }

    /**
     * The words a text is compared by: its meaningful words, with plurals and common Taglish synonyms counted as one.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        if (self::$same === null) {
            self::$same = [];
            foreach (self::SAME as $word => $others) {
                self::$same += array_fill_keys($others, $word);
            }
        }
        $terms = [];
        foreach (ShippyKnowledge::terms($text) as $term) {
            $term = self::$same[$term] ?? $term;
            $single = strlen($term) > 3 && str_ends_with($term, 's') && ! str_ends_with($term, 'ss') ? substr($term, 0, -1) : $term;
            $terms[] = self::$same[$single] ?? $single;
        }

        return array_values(array_unique($terms));
    }

    /**
     * The active knowledge, prepared once: the words of every FAQ wording and heading, and which sections use a word.
     *
     * @return Active
     */
    private function active(): array
    {
        /** @var Active */
        return Cache::rememberForever(self::CACHE, function (): array {
            $faqs = [];
            foreach (ShippyFaq::query()->where('is_enabled', true)->orderBy('id')->limit(ShippyFaq::MAX_FAQS)->get() as $faq) {
                $phrasings = array_values(array_filter(array_map(self::terms(...), [$faq->question, ...$faq->alternatives])));
                if ($phrasings !== []) {
                    $faqs[] = ['question' => $faq->question, 'answer' => $faq->answer, 'phrasings' => $phrasings];
                }
            }
            $sections = [];
            $index = [];
            foreach (ShippyDocument::query()->where('is_enabled', true)->orderBy('id')->limit(ShippyDocument::MAX_DOCUMENTS)->get() as $document) {
                $title = ShippyMarkdown::title($document->filename);
                foreach (ShippyMarkdown::sections($document->content, $document->filename) as $section) {
                    $terms = self::terms($section['heading']);
                    foreach (array_unique([...$terms, ...self::terms($section['text'])]) as $term) {
                        $index[$term][] = count($sections);
                    }
                    $sections[] = ['title' => $title, 'heading' => $section['heading'], 'text' => $section['text'], 'terms' => $terms];
                }
            }

            return ['faqs' => $faqs, 'sections' => $sections, 'index' => $index];
        });
    }

    /**
     * The one entry that is clearly what was asked: alike enough, and no other entry nearly as alike.
     *
     * @param  list<string>  $asked
     * @param  array<int, list<list<string>>>  $entries  every wording of each entry, as words
     */
    private function closest(array $asked, array $entries): ?int
    {
        if ($asked === []) {
            return null;
        }
        $best = null;
        $top = 0.0;
        $next = 0.0;
        foreach ($entries as $entry => $phrasings) {
            $score = 0.0;
            foreach ($phrasings as $known) {
                $shared = $this->shared($asked, $known);
                $score = max($score, $shared === 0 ? 0.0 : $shared / (count($asked) + count($known) - $shared));
            }
            if ($score > $top) {
                [$next, $top, $best] = [$top, $score, $entry];
            } elseif ($score > $next) {
                $next = $score;
            }
        }

        return $top >= self::INSTANT && $top - $next >= self::MARGIN ? $best : null;
    }

    /**
     * How many of the asked words an entry has; a longer word may be one letter off, so a typo still counts.
     *
     * @param  list<string>  $asked
     * @param  list<string>  $known
     */
    private function shared(array $asked, array $known): int
    {
        $shared = 0;
        foreach ($asked as $term) {
            foreach ($known as $word) {
                if ($term === $word || (strlen($term) >= 5 && abs(strlen($term) - strlen($word)) <= 1 && levenshtein($term, $word) <= 1)) {
                    $shared++;

                    break;
                }
            }
        }

        return $shared;
    }

    /**
     * A document's text without the lines that put a price on a menu item: only the published menu states those, so
     * a document can neither change a price nor keep an old one alive.
     */
    private function withoutMenuPrices(string $text): string
    {
        $kept = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('~(?:₱|\bphp|\bp)\s?\d|\d\s?(?:pesos?|piso|php)\b~iu', $line) !== 1 || $this->knowledge->named($line) === []) {
                $kept[] = $line;
            }
        }

        return implode(' ', $kept);
    }

    /** A text cut at the end of a sentence where one fits, otherwise at the limit. */
    private function clip(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - 1);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));

        return $end > $limit / 2 ? mb_substr($cut, 0, $end + 1) : $cut.'…';
    }
}
