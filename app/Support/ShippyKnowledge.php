<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything Shippy, the landing page chat, may say (v1.3.0): the published menu, the scheduled hours, the address,
 * the best sellers that real sales support and the four places a reply may link to. The menu is the landing page's own
 * file, so a name or price here is always the one a visitor reads on the page; the POS catalog, its availability and
 * every customer, order and staff record stay out of reach. The same facts answer on their own (`answer()`) whenever
 * Gemini is not used.
 *
 * @phpstan-type Item array{name: string, price: string, amount: int, prices: list<int>, category: string, img: string|null}
 * @phpstan-type Row array{name: string, price: string, img: string|null}
 * @phpstan-type Answer array{text: string, items: list<Row>, actions: list<string>}
 */
class ShippyKnowledge
{
    public const ADDRESS = '1511, Unit V, Purok 8 Adarna St., Quezon City';

    /**
     * The only destinations a reply may offer. A reply names a key; the address behind it is always this one.
     *
     * @var array<string, array{label: string, href: string}>
     */
    public const ACTIONS = [
        'menu' => ['label' => 'See the menu', 'href' => '#board'],
        'maps' => ['label' => 'Open in Google Maps', 'href' => 'https://maps.app.goo.gl/nivpx3cxuP56wAXo8'],
        'messenger' => ['label' => 'Message on Facebook', 'href' => 'https://www.facebook.com/profile.php?id=100094575655003'],
        'reviews' => ['label' => 'See the reviews', 'href' => '#reviews'],
    ];

    /**
     * The six questions offered as buttons in the chat.
     *
     * @var list<string>
     */
    public const PRESETS = [
        'Open ba kayo ngayon?', 'Ano ang best seller?', 'Magkano ang tapsilog?',
        'Saan kayo located?', 'Lemonade flavors', 'May ₱100 ako, ano pwede?',
    ];

    /** The most product cards one reply carries. */
    public const MAX_ITEMS = 8;

    /** Best sellers are read from this many days of sales, and an item needs this many sold to be named. */
    public const BEST_SELLER_DAYS = 30;

    public const BEST_SELLER_MINIMUM = 10;

    /** The categories that are a meal on their own: what a budget question is answered from. */
    private const MEALS = ['silog', 'pares', 'short'];

    /**
     * The photo of a menu item, from the landing page's own menu cards.
     *
     * @var array<string, string>
     */
    private const PHOTOS = [
        'Tapa Silog' => '822fd717', 'Bangus Silog' => '2f7901fa', 'Chicken Silog' => '1bba3869', 'Liempo Silog' => '2be3bb14',
        'Porkchop Silog' => '8611ee78', 'Longganisa Silog' => 'eebdc746', 'Tocino Silog' => 'd8225368', 'Hungarian Silog' => 'f3b60af7',
        'MIX Silog' => 'a061b274', 'Chicken Fillet Silog' => '7d660de0', 'Ham Silog' => 'b51e6b5d', 'Hotdog Silog' => 'a74425f2',
        'Embutido Silog' => 'cb95c141', 'Maling Silog' => '3c8f2dab', 'Nuggets Silog' => '09894ed2', 'Shanghai Silog' => '7547940c',
        'Siomai Silog' => '5da3d399', 'Burger Steak' => '54e8c470', 'Bulalo' => 'b0bbc3cb', 'Beef Pares with Rice' => '63dcbd0b',
        'Beef Pares Only' => 'fcd12624', 'Lechon Pares with Rice' => '6ae3a027', 'Lechon Pares Only' => '450058c9', 'Beef Mami' => 'c8b27d2f',
        'Pancit Canton' => '8fb7f148', 'Pancit Bihon' => '22d7e1a7', 'Miki Bihon' => 'af229195', 'Miki Guisado' => '56d2c469',
        'Lechon Kawali' => '12cbd644', 'Pork Sisig' => '2f07f761', "Tokwa't Baboy" => '6544153d', 'Chicken Only' => '0ecf9527',
        'Bangus Only' => '6a193b10', 'Porkchop Only' => '93911d07', 'Liempo Only' => 'f0f3cc4a', 'Hungarian Only' => 'd8d56c44',
        'Shanghai Only' => '21029c67', 'Siomai Only' => 'f8953943', 'Silog' => 'd3bcb09c', 'Hotdog Only' => 'ed339048',
        'Maling Only' => '0cd269f2', 'Rice' => 'fd74d8a0', 'Half Rice' => '7304e306', 'Egg' => 'd0bdf098',
        'Pure Lemonade' => '3dafa3d6', 'Lemon + Yakult' => '4774659c', 'Lemon + Calamansi' => 'a8ea1cff', 'Lemon + Cola' => 'd5ef5ce2',
        'Crystal / Nata add-on' => '5c08777b',
    ];

    /**
     * Everyday names for menu items, by `key()`: what guests type and what the POS calls the same dish.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'tapsilog' => ['Tapa Silog'], 'chicksilog' => ['Chicken Silog'], 'chiksilog' => ['Chicken Silog'],
        'bangsilog' => ['Bangus Silog'], 'liemposilog' => ['Liempo Silog'], 'porksilog' => ['Porkchop Silog'],
        'porkchopsilog' => ['Porkchop Silog'], 'tocilog' => ['Tocino Silog'], 'tosilog' => ['Tocino Silog'],
        'longsilog' => ['Longganisa Silog'], 'hungsilog' => ['Hungarian Silog'], 'mixsilog' => ['MIX Silog'],
        'hamsilog' => ['Ham Silog'], 'hotsilog' => ['Hotdog Silog'], 'embusilog' => ['Embutido Silog'],
        'malingsilog' => ['Maling Silog'], 'nuggetsilog' => ['Nuggets Silog'], 'nugget' => ['Nuggets Silog'],
        'shanghaisilog' => ['Shanghai Silog'], 'siomaisilog' => ['Siomai Silog'], 'chickfilletsilog' => ['Chicken Fillet Silog'],
        'manok' => ['Chicken Silog', 'Chicken Only'], 'pancit' => ['Pancit Canton', 'Pancit Bihon'],
        'lechon' => ['Lechon Pares with Rice', 'Lechon Pares Only', 'Lechon Kawali'], 'kawali' => ['Lechon Kawali'],
        'coke' => ['Lemon + Cola'], 'dutchmill' => ['Lemon + Dutch Mill'], 'kaldereta' => ['Caldereta'],
        'sisig' => ['Pork Sisig'], 'mami' => ['Beef Mami'], 'lemonpure' => ['Pure Lemonade'], 'crystal' => ['Crystal / Nata add-on'],
    ];

    /** Words in a guest's question that only point at an item when nothing else matched. */
    private const WEAK_ALIASES = ['rice' => ['Rice', 'Half Rice'], 'kanin' => ['Rice', 'Half Rice'], 'itlog' => ['Egg']];

    /** Words too common in item names to identify one. */
    private const COMMON_WORDS = ['silog', 'only', 'with', 'rice', 'pares', 'lemon', 'lemonade', 'per', 'pc', 'add', 'on', 'bar', 'and'];

    /**
     * What a question can be about, with the words that say so.
     *
     * @var array<string, string>
     */
    private const TOPICS = [
        'thanks' => 'salamat|thanks?|thank you|ty|tnx',
        'who' => 'sino ka|who are you|ano ka',
        'hours' => 'open|bukas|sarado|close|closed|oras|hours?|schedule|what time|anong oras|hanggang|until|opening|closing|magbubukas|nagbubukas|magsasara|nagsasara|monday|tuesday|wednesday|thursday|friday|saturday|sunday|lunes|martes|miyerkules|huwebes|biyernes|sabado|linggo',
        'location' => 'saan|where|location|located|address|map|directions?|papunta|landmark|adarna|banda|lugar',
        'order' => '(?<!short )orders?|deliver|delivery|reserve|reservation|grab|foodpanda|pickup|pick up|takeout|take out|dine|dine-in',
        'payment' => 'gcash|maya|cash|bayad|payment|pay|card',
        'reviews' => 'reviews?|feedback|rate|rating|reklamo|complain',
        'best' => 'best seller|bestseller|best|recommend|recommended|suggest|masarap|popular|sikat|favorite|paborito|specialty|mabenta|pinakamabenta|pinakasikat',
        'menu' => 'menu|presyo|prices?|magkano|how much|hm|list|food|pagkain|kain',
        'greeting' => 'hi|hello|helo|hey|yo|kumusta|musta|uy|hoy|good (?:morning|afternoon|evening)|magandang(?: (?:umaga|tanghali|hapon|gabi|araw))?',
    ];

    /**
     * The menu categories a question can ask for, in the order they are tried.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'drinks' => 'drinks?|inumin|lemonade|juice|lemon|flavors?', 'dessert' => 'desserts?|sweets?|matamis|panghimagas',
        'pares' => 'pares|bowls?|sabaw|soup|mainit', 'ala' => 'ala carte|alacarte|add-?ons?|extra',
        'short' => 'short orders?|ulam|sharing|share|barkada', 'silog' => 'silogs?|almusal|breakfast',
    ];

    /** The words of a budget question besides the amount. */
    private const BUDGET_WORDS = 'budget|php|pesos?|piso|lang|under|below|mura|cheap|kasya|pasok|meron|kong|kainin|makakain|mabibili|bilhin|meals?|pagkain|food|worth';

    /**
     * Taglish words that carry no meaning of their own in a question.
     *
     * @var list<string>
     */
    private const FILLER = [
        'ba', 'bang', 'po', 'ho', 'kayo', 'kau', 'ka', 'ko', 'mo', 'ako', 'kami', 'tayo', 'namin', 'natin', 'ninyo', 'niyo', 'nyo', 'nio',
        'ang', 'ng', 'mga', 'sa', 'si', 'na', 'pa', 'din', 'rin', 'naman', 'nga', 'lang', 'lng', 'yung', 'ung', 'yong', 'at',
        'ano', 'anong', 'anu', 'what', 'whats', 'the', 'a', 'an', 'is', 'are', 'be', 'will', 'do', 'does', 'you', 'your', 'u', 'ur', 'i', 'me', 'my', 'we',
        'of', 'for', 'to', 'in', 'on', 'from', 'and', 'para', 'mag', 'sir', 'maam', 'mam', 'boss', 'please', 'pls', 'paki', 'may', 'meron', 'mayroon', 'have', 'has', 'there', 'kaya',
        'ngayon', 'today', 'now', 'tonight', 'mamaya', 'hello', 'hi', 'hey', 'pwede', 'puwede', 'pede', 'can', 'could',
        'tell', 'know', 'alam', 'tanong', 'ask', 'question', 'lahat', 'all', 'any', 'kung', 'if', 'eh', 'e', 'ha', 'oh', 'ok', 'okay',
    ];

    /** @var list<string>|null */
    private static ?array $vocabulary = null;

    /** @var array<string, array{label: string, note: string, items: list<Item>}>|null */
    private static ?array $menu = null;

    /**
     * The published menu by category, read from the file the landing page renders.
     *
     * @return array<string, array{label: string, note: string, items: list<Item>}>
     */
    public function menu(): array
    {
        if (self::$menu !== null) {
            return self::$menu;
        }
        /** @var array<string, array{label: string, note: string, items: list<array{0: string, 1: string}>}> $published */
        $published = json_decode((string) file_get_contents(resource_path('js/lib/landing-menu.json')), true, flags: JSON_THROW_ON_ERROR);
        $menu = [];
        foreach ($published as $category => $section) {
            $items = [];
            foreach ($section['items'] as [$name, $price]) {
                preg_match_all('/\d+/', $price, $numbers);
                $prices = array_map(intval(...), $numbers[0]);
                $photo = self::PHOTOS[$name] ?? null;
                $items[] = [
                    'name' => $name, 'price' => $price, 'amount' => $prices === [] ? 0 : min($prices), 'prices' => $prices,
                    'category' => $category, 'img' => $photo === null ? null : '/images/landing-v121-'.$photo.'.webp',
                ];
            }
            $menu[$category] = ['label' => $section['label'], 'note' => $section['note'], 'items' => $items];
        }

        return self::$menu = $menu;
    }

    /** @return list<Item> */
    public function items(): array
    {
        return array_merge(...array_values(array_map(fn (array $section): array => $section['items'], $this->menu())));
    }

    /** A name reduced to what identifies it: lower case, common respellings joined, letters and digits only. */
    public static function key(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', str_replace(['letchon', 'pansit'], ['lechon', 'pancit'], mb_strtolower($name)));
    }

    /**
     * The one menu item a name means, or null when it is not on the published menu or could mean several.
     *
     * @return Item|null
     */
    public function find(string $name): ?array
    {
        $key = self::key($name);
        $alias = self::ALIASES[$key] ?? [];
        $wanted = count($alias) === 1 ? self::key($alias[0]) : $key;
        foreach ($this->items() as $item) {
            if (self::key($item['name']) === $wanted) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  Item  $item
     * @return Row
     */
    public function row(array $item): array
    {
        return ['name' => $item['name'], 'price' => $item['price'], 'img' => $item['img']];
    }

    /**
     * The buttons behind a reply's action keys; an unknown key is dropped.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, href: string, external: bool}>
     */
    public function actions(array $keys): array
    {
        $actions = [];
        foreach (array_unique($keys) as $key) {
            if (isset(self::ACTIONS[$key])) {
                $actions[] = ['key' => $key, ...self::ACTIONS[$key], 'external' => ! str_starts_with(self::ACTIONS[$key]['href'], '#')];
            }
        }

        return $actions;
    }

    /**
     * Where the scheduled hours stand at a Manila moment. The schedule is all that is known: whether the store really
     * opened tonight is never claimed.
     *
     * @return array{key: string, text: string, fact: string}
     */
    public function status(CarbonImmutable $now): array
    {
        $now = $now->setTimezone(ReportPeriod::TIMEZONE);

        return match (true) {
            $now->isSunday() => ['key' => 'closed', 'text' => 'Sarado kami tuwing Linggo', 'fact' => 'closed today (Sunday)'],
            $now->isWednesday() => ['key' => 'wednesday', 'text' => 'Miyerkules ngayon: kadalasang sarado kami, pero minsan bukas', 'fact' => 'Wednesday, usually closed and sometimes open'],
            $now->hour >= 18 => ['key' => 'open', 'text' => 'Ayon sa schedule, bukas kami ngayon hanggang 12:00 AM', 'fact' => 'scheduled open now until 12:00 AM'],
            default => ['key' => 'later', 'text' => 'Ayon sa schedule, magbubukas kami mamayang 6:00 PM hanggang 12:00 AM', 'fact' => 'scheduled to open today at 6:00 PM until 12:00 AM'],
        };
    }

    /** The amount of pesos a guest says they have, when the message is a budget question. */
    public function budget(string $message): ?int
    {
        $message = mb_strtolower($message);
        if (preg_match('/(?:₱|php|p)?\s?(\d{2,4})/u', $message, $found) !== 1 || (int) $found[1] < 30) {
            return null;
        }

        return preg_match('/(budget|₱|php|peso|piso|\blang\b|under|below|mura|cheap|kasya|pasok|\bako\b|meron|\bmay\b)/u', $message) === 1
            ? (int) $found[1]
            : null;
    }

    /**
     * The published items that sold the most in the last 30 days, best first: names only, from one bounded aggregate
     * that is kept for six hours. A POS product that is not on the published menu is never named.
     *
     * @return list<string>
     */
    public function bestSellers(): array
    {
        return Cache::remember('shippy:best-sellers', now()->addHours(6), function (): array {
            $sold = [];
            $rows = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNotNull('orders.committed_at')
                ->where('orders.committed_at', '>=', now()->subDays(self::BEST_SELLER_DAYS))
                ->whereIn('orders.commercial_status', [CommercialStatus::Active->value, CommercialStatus::Completed->value])
                ->groupBy('order_items.product_name_snapshot')
                ->orderByDesc('quantity')
                ->limit(40)
                ->get(['order_items.product_name_snapshot AS name', DB::raw('SUM(order_items.quantity) AS quantity')]);
            foreach ($rows as $row) {
                $name = (string) $row->name;
                $item = $this->find($name) ?? $this->find($name.' only') ?? $this->find($name.' with rice');
                if ($item !== null) {
                    $sold[$item['name']] = ($sold[$item['name']] ?? 0) + (int) $row->quantity;
                }
            }
            arsort($sold);

            return array_slice(array_keys(array_filter($sold, fn (int $quantity): bool => $quantity >= self::BEST_SELLER_MINIMUM)), 0, 3);
        });
    }

    /** The facts handed to Gemini with every question: nothing else about the business is ever sent. */
    public function facts(CarbonImmutable $now): string
    {
        $now = $now->setTimezone(ReportPeriod::TIMEZONE);
        $lines = [
            'PONGSKILOG is a Filipino silog and pares eatery. Dine-in and takeout.',
            'Address: '.self::ADDRESS.'.',
            'Hours (Asia/Manila): Monday, Tuesday, Thursday, Friday and Saturday 6:00 PM to 12:00 AM. Wednesday is usually closed and sometimes open: message to confirm. Sunday is closed.',
            'Now in Manila: '.$now->format('l g:i A').'. By the schedule: '.$this->status($now)['fact'].'. Whether the store really opened tonight and which items are available right now are not known here.',
            'Menu, prices in Philippine pesos:',
        ];
        foreach ($this->menu() as $section) {
            $lines[] = $section['label'].' ('.$section['note'].'): '.implode('; ', array_map(
                fn (array $item): string => $item['name'].' '.implode('/', $item['prices']),
                $section['items'],
            ));
        }
        $best = rescue(fn (): array => $this->bestSellers(), [], false);
        $lines[] = $best === []
            ? 'Best sellers: not verified yet, so name none.'
            : 'Best sellers by sales in the last '.self::BEST_SELLER_DAYS.' days, best first: '.implode(', ', $best).'.';

        return implode("\n", $lines);
    }

    /**
     * Shippy's own answer from the facts alone: what a guest gets whenever nothing better is available. It always
     * answers, and a question it cannot place is sent to Facebook.
     *
     * @return Answer
     */
    public function answer(string $message, CarbonImmutable $now): array
    {
        return $this->resolve($message, $now)[1];
    }

    /**
     * The same answer, but only when the facts answer the whole question: every word of it is either filler or belongs
     * to the one topic that matched. "Open ba kayo ngayon?" is answered; "Open ba kayo pag holiday?" is not, because
     * nothing here knows about holidays, so an FAQ, a document or Gemini gets it instead. A bare "magkano?" is only
     * answered when it opens a conversation: later it refers to what was said before.
     *
     * @return Answer|null
     */
    public function verified(string $message, CarbonImmutable $now, bool $opening = true): ?array
    {
        [$topic, $answer] = $this->resolve($message, $now);
        $said = match (true) {
            in_array($topic, ['thanks', 'who', 'hours', 'location', 'reviews', 'greeting'], true) => [self::TOPICS[$topic]],
            $topic === 'items', $topic === 'menu' && $opening => [self::TOPICS['menu']],
            $topic === 'best' => [self::TOPICS['best'], self::TOPICS['menu']],
            $topic === 'budget' => [self::BUDGET_WORDS],
            isset(self::CATEGORIES[$topic]) => [self::CATEGORIES[$topic], self::TOPICS['menu']],
            default => null,
        };
        if ($said === null) {
            return null;
        }
        $question = self::lower($message);
        if ($topic === 'budget') {
            $question = (string) preg_replace('/(?:\bphp|\bp)?\s?[₱\d]+/u', ' ', $question);
        }
        $rest = self::terms((string) preg_replace('/\b('.implode('|', $said).')\b/u', ' ', $question));

        return ($topic === 'items' ? array_diff($rest, $this->vocabulary()) : $rest) === [] ? $answer : null;
    }

    /**
     * The words of a text that carry its meaning: lower case, reduced by `key()`, without Taglish filler.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $words = array_map(self::key(...), preg_split('/[^a-z0-9ñ\']+/u', self::lower($text)) ?: []);

        return array_values(array_unique(array_filter($words, fn (string $word): bool => $word !== '' && ! in_array($word, self::FILLER, true))));
    }

    /**
     * The menu items a text names, in menu order.
     *
     * @return list<Item>
     */
    public function named(string $text): array
    {
        return $this->mentioned(self::words($text));
    }

    /**
     * What a question is about and the answer the facts give for it.
     *
     * @return array{0: string, 1: Answer}
     */
    private function resolve(string $message, CarbonImmutable $now): array
    {
        $question = self::lower($message);
        $words = self::words($message);
        $has = fn (string $pattern): bool => preg_match('/\b('.$pattern.')\b/u', $question) === 1;
        $status = $this->status($now)['text'];

        if (count($words) <= 4 && $has(self::TOPICS['thanks'])) {
            return ['thanks', $this->reply('Walang anuman. Kita-kits sa PONGSKILOG.')];
        }
        if ($has(self::TOPICS['who'])) {
            return ['who', $this->reply('Ako si Shippy, ang helper ng PONGSKILOG. Sinasagot ko ang tanong tungkol sa menu, presyo, oras at lokasyon.')];
        }
        if ($has(self::TOPICS['hours'])) {
            return ['hours', $this->reply($status.'. Para sigurado, i-message mo kami sa Facebook.', [
                ['name' => 'Mon, Tue, Thu to Sat', 'price' => '6:00 PM - 12:00 AM', 'img' => null],
                ['name' => 'Wednesday', 'price' => 'Usually closed', 'img' => null],
                ['name' => 'Sunday', 'price' => 'Closed', 'img' => null],
            ], ['messenger'])];
        }
        if ($has(self::TOPICS['location'])) {
            return ['location', $this->reply('Nasa '.self::ADDRESS.' kami. Dine-in at takeout.', [], ['maps'])];
        }
        if ($has(self::TOPICS['order'])) {
            return ['order', $this->reply('Dine-in at takeout kami. Para sa orders at tanong, i-message mo kami sa Facebook.', [], ['messenger'])];
        }
        if ($has(self::TOPICS['payment'])) {
            return ['payment', $this->reply('Para sigurado sa payment options, i-message mo kami sa Facebook.', [], ['messenger'])];
        }
        if ($has(self::TOPICS['reviews'])) {
            return ['reviews', $this->reply('Salamat. Mababasa at maiiwan ang reviews dito mismo sa page.', [], ['reviews'])];
        }

        $budget = $this->budget($message);
        if ($budget !== null) {
            $meals = array_values(array_filter($this->items(), fn (array $item): bool => in_array($item['category'], self::MEALS, true)));
            usort($meals, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
            $fits = array_slice(array_values(array_filter($meals, fn (array $item): bool => $item['amount'] <= $budget)), 0, self::MAX_ITEMS);
            if ($fits === []) {
                $cheapest = end($meals) ?: null;

                return ['budget', $cheapest === null
                    ? $this->reply('Tingnan mo ang buong menu para sa mga presyo.', [], ['menu'])
                    : $this->reply('Ang pinakamurang meal namin ay '.$cheapest['name'].' sa '.$cheapest['price'].'.', [$this->row($cheapest)], ['menu'])];
            }

            return ['budget', $this->reply('Pasok sa ₱'.$budget.' ang mga ito. Kasama na sa silog ang garlic rice at itlog.', array_map($this->row(...), $fits), ['menu'])];
        }

        $weak = false;
        $found = $this->mentioned($words, $weak);
        if ($found !== []) {
            $shown = array_slice($found, 0, self::MAX_ITEMS);
            $text = count($shown) === 1
                ? $shown[0]['name'].' ay '.$shown[0]['price'].'.'
                : 'Ito ang nahanap ko'.(count($found) > self::MAX_ITEMS ? ', may iba pa sa menu.' : ':');
            if (array_filter($found, fn (array $item): bool => $item['category'] === 'silog') !== []) {
                $text .= ' Kasama na sa silog ang garlic rice at itlog.';
            }
            if (array_filter($found, fn (array $item): bool => count($item['prices']) > 1) !== []) {
                $text .= ' Small / Medium / Large ang presyo ng lemonade.';
            }

            /** "Rice" or "itlog" alone is a guess at what the guest means, so it is never a verified answer. */
            return [$weak ? 'guess' : 'items', $this->reply($text, array_map($this->row(...), $shown), ['menu'])];
        }

        if ($has(self::TOPICS['best'])) {
            $best = array_values(array_filter(array_map($this->find(...), rescue(fn (): array => $this->bestSellers(), [], false))));

            return ['best', $best === []
                ? $this->reply('Wala pa akong verified na best seller sa ngayon. Tingnan mo muna ang buong menu.', [], ['menu'])
                : $this->reply('Ito ang pinakamabenta namin nitong nakaraang '.self::BEST_SELLER_DAYS.' araw:', array_map($this->row(...), $best), ['menu'])];
        }

        foreach (self::CATEGORIES as $category => $pattern) {
            if ($has($pattern)) {
                $section = $this->menu()[$category];
                $items = $category === 'drinks'
                    ? array_values(array_filter($section['items'], fn (array $item): bool => count($item['prices']) > 1))
                    : $section['items'];

                return [$category, $this->reply(
                    $section['note'].(count($items) > self::MAX_ITEMS ? ' Ilan sa mga ito:' : ''),
                    array_map($this->row(...), array_slice($items, 0, self::MAX_ITEMS)),
                    ['menu'],
                )];
            }
        }
        if ($has(self::TOPICS['menu'])) {
            $ranges = [];
            foreach ($this->menu() as $section) {
                $amounts = array_column($section['items'], 'amount');
                if ($amounts !== []) {
                    $ranges[] = ['name' => $section['label'], 'price' => '₱'.min($amounts).' to ₱'.max($amounts), 'img' => null];
                }
            }

            return ['menu', $this->reply('Ito ang mga category ng menu namin at ang price range ng bawat isa:', $ranges, ['menu'])];
        }
        if (preg_match('/^('.self::TOPICS['greeting'].')\b/u', $question) === 1) {
            return ['greeting', $this->reply('Hello. '.$status.'. Tanong ka lang tungkol sa menu, presyo o lokasyon.')];
        }

        return ['unknown', $this->reply('Hindi ko pa alam ang sagot diyan. I-message mo ang PONGSKILOG sa Facebook para sigurado.', [], ['messenger'])];
    }

    /**
     * @param  list<Row>  $items
     * @param  list<string>  $actions
     * @return Answer
     */
    private function reply(string $text, array $items = [], array $actions = []): array
    {
        return ['text' => $text, 'items' => $items, 'actions' => $actions];
    }

    private static function lower(string $text): string
    {
        return str_replace(['’', '‘'], "'", mb_strtolower($text));
    }

    /**
     * Every word of a text, reduced by `key()`.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(array_map(self::key(...), preg_split('/[^a-z0-9ñ\']+/u', self::lower($text)) ?: [])));
    }

    /**
     * Every word that can point at a menu item: the words of the names and the everyday names.
     *
     * @return list<string>
     */
    private function vocabulary(): array
    {
        return self::$vocabulary ??= array_values(array_unique([
            ...array_merge(...array_map(fn (array $item): array => self::words($item['name']), $this->items())),
            ...array_map(strval(...), array_keys(self::ALIASES)),
            ...array_keys(self::WEAK_ALIASES),
        ]));
    }

    /**
     * The menu items a guest's words point at, in menu order.
     *
     * @param  list<string>  $words
     * @param  bool  $weak  set when only a weak alias pointed at them
     * @return list<Item>
     */
    private function mentioned(array $words, bool &$weak = false): array
    {
        $names = [];
        foreach ($this->items() as $item) {
            $own = array_diff(array_filter(array_map(self::key(...), preg_split('/[^a-z0-9\']+/', mb_strtolower($item['name'])) ?: [])), self::COMMON_WORDS);
            if (array_intersect($own, $words) !== []) {
                $names[] = $item['name'];
            }
        }
        foreach ($words as $word) {
            array_push($names, ...(self::ALIASES[$word] ?? []));
        }
        $weak = $names === [];
        if ($weak) {
            foreach ($words as $word) {
                array_push($names, ...(self::WEAK_ALIASES[$word] ?? []));
            }
        }
        $names = array_values(array_unique($names));
        foreach (['silog', 'only', 'pares', 'rice'] as $word) {
            $narrowed = array_values(array_filter($names, fn (string $name): bool => str_contains(mb_strtolower($name), $word)));
            if (in_array($word, $words, true) && $narrowed !== []) {
                $names = $narrowed;
            }
        }

        return array_values(array_filter(array_map($this->find(...), $names)));
    }
}
