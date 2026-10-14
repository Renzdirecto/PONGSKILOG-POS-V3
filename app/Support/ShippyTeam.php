<?php

namespace App\Support;

use App\Models\LandingTeamMember;
use Illuminate\Support\Str;

/**
 * Meet the Team as Shippy knows it (v1.3.0): the visible cards of Website → Team that have a name, exactly as the
 * landing page shows them (name, role, bio, portrait). A hidden card, a card still waiting for its name and every staff
 * account stay out of reach. A question only about a member ("Sino si Shamarra?"), a role ("Who is the chef?") or the
 * team is answered here at once with the card's portrait; a longer question about them goes to Gemini with these cards
 * among its facts.
 *
 * @phpstan-import-type Answer from ShippyKnowledge
 * @phpstan-import-type Row from ShippyKnowledge
 *
 * @phpstan-type Member array{name: string, role: string, bio: string|null, img: string|null, names: list<string>, roles: list<string>}
 */
class ShippyTeam
{
    /** Words that ask who someone is. */
    private const WHO = ['sino', 'sinong', 'sinu', 'who', 'whos', 'kanino'];

    /** Words that ask about the whole team. */
    private const TEAM = ['team', 'staff', 'crew', 'member', 'members', 'people', 'tao', 'likod', 'behind'];

    /** Words that may surround a name or a role in a question about that person, beside Shippy's filler. */
    private const ABOUT = [
        'about', 'tungkol', 'kilala', 'kilalanin', 'meet', 'introduce', 'profile', 'bio', 'role', 'position', 'trabaho',
        'job', 'niya', 'nya', 'siya', 'sya', 'ni', 'kay', 'nino', 'she', 'he', 'her', 'his', 'him', 'they', 'sila', 'nila',
        'their', 'more', 'ate', 'kuya', 'tita', 'tito', 'miss', 'ms', 'mr', 'mrs', 'nasa', 'dito', 'here',
    ];

    /** Parts of a name or a role too common to say which card is meant. */
    private const COMMON = ['de', 'del', 'dela', 'delos', 'los', 'la', 'san', 'santa', 'sta', 'jr', 'sr', 'ii', 'iii', 'head', 'main', 'assistant', 'senior', 'junior'];

    /** @var list<Member>|null */
    private ?array $members = null;

    /**
     * The answer to a question that is only about one or more visible members, a role or the team, or null.
     *
     * @return Answer|null
     */
    public function answer(string $message, bool $english = false): ?array
    {
        $words = self::words($message);
        if ($words === [] || $this->members() === []) {
            return null;
        }
        $cued = self::cued($words);
        $team = array_intersect($words, self::TEAM) !== [];
        $named = $this->matching($words, 'names', $cued);
        if ($named === [] && $team === false && array_intersect($words, self::WHO) === []) {
            return null;
        }
        /** "Sino ang team?" asks for everyone, even when one role happens to be "Team Lead". */
        $found = $named !== [] ? $named : $this->matching(array_values(array_diff($words, self::TEAM)), 'roles', $cued);
        $said = array_merge(self::WHO, self::ABOUT, self::TEAM, ...array_map(fn (array $member): array => [...$member['names'], ...$member['roles']], $found));
        $rest = array_filter($words, fn (string $word): bool => array_filter($said, fn (string $known): bool => self::same($word, $known, $cued)) === []);
        if ($rest !== [] || ($found === [] && ! $team)) {
            return null;
        }

        return $this->reply($found === [] ? $this->members() : $found, $found === [], $english);
    }

    /** Whether a text names a visible member or the role on a visible member's card. */
    public function mentions(string $text): bool
    {
        $words = self::words($text);
        $cued = self::cued($words);

        return $this->members() !== []
            && ($this->matching($words, 'names', $cued) !== [] || $this->matching(array_values(array_diff($words, self::TEAM)), 'roles', $cued) !== []);
    }

    /**
     * A visible member by their exact name, as the chat shows them, or null.
     *
     * @return Row|null
     */
    public function find(string $name): ?array
    {
        foreach ($this->members() as $member) {
            if (mb_strtolower(trim($name)) === mb_strtolower($member['name'])) {
                return $this->row($member);
            }
        }

        return null;
    }

    /**
     * The visible members as facts for Gemini, when the conversation is about a person or the team; otherwise empty,
     * so a question about food carries no names.
     */
    public function facts(string $conversation): string
    {
        $words = self::words($conversation);
        $asked = array_intersect($words, [...self::WHO, ...self::TEAM]) !== [] || $this->matching($words, 'names', self::cued($words)) !== [];
        if (! $asked || $this->members() === []) {
            return '';
        }

        return 'TEAM (the people on the website, name, role and what their card says): '.implode('; ', array_map(
            fn (array $member): string => $member['name'].', '.$member['role'].($member['bio'] === null ? '' : ': '.$member['bio']),
            $this->members(),
        )).'.';
    }

    /**
     * @param  list<Member>  $members
     * @return Answer
     */
    private function reply(array $members, bool $everyone, bool $english): array
    {
        $shown = array_slice($members, 0, ShippyKnowledge::MAX_ITEMS);
        if (count($shown) === 1) {
            $member = $shown[0];
            $text = ($english ? $member['name']." is PONGSKILOG's ".$member['role'].'.' : 'Si '.$member['name'].' ang '.$member['role'].' ng PONGSKILOG.')
                .($member['bio'] === null ? '' : ' '.$member['bio']);
        } else {
            $text = match (true) {
                $everyone && $english => 'Meet the PONGSKILOG team, the people behind the silog:',
                $everyone => 'Ito ang team ng PONGSKILOG, sila ang nasa likod ng silog:',
                $english => "Here's who I found on our team:",
                default => 'Ito ang nahanap ko sa team namin:',
            };
        }

        return ['text' => $text, 'items' => array_map($this->row(...), $shown), 'actions' => ['team']];
    }

    /**
     * @param  Member  $member
     * @return Row
     */
    private function row(array $member): array
    {
        return ['name' => $member['name'], 'price' => $member['role'], 'img' => $member['img'], 'kind' => 'person'];
    }

    /**
     * The members whose name (or role) shares a word with the question; when the question asks about someone, a
     * longer word may be one letter off.
     *
     * @param  list<string>  $words
     * @param  'names'|'roles'  $part
     * @return list<Member>
     */
    private function matching(array $words, string $part, bool $fuzzy): array
    {
        return array_values(array_filter($this->members(), function (array $member) use ($words, $part, $fuzzy): bool {
            foreach (array_diff($member[$part], self::COMMON) as $known) {
                foreach ($words as $word) {
                    if (self::same($word, $known, $fuzzy)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * Whether a word of the question is a known word. A word of five letters or more may be one letter off, but only
     * in a question about someone: "Price?" is never a member called Prince.
     */
    private static function same(string $word, string $known, bool $fuzzy): bool
    {
        return $word === $known || ($fuzzy && strlen($word) >= 5 && abs(strlen($word) - strlen($known)) <= 1 && levenshtein($word, $known) <= 1);
    }

    /**
     * The words a text is compared by, with accents folded so "Niño" and "Nino" are one name.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return ShippyKnowledge::terms(Str::ascii($text));
    }

    /**
     * Whether the question asks about someone or the team.
     *
     * @param  list<string>  $words
     */
    private static function cued(array $words): bool
    {
        return array_intersect($words, [...self::WHO, ...self::ABOUT, ...self::TEAM]) !== [];
    }

    /**
     * The visible members with a name, in the landing page's order, read once per question.
     *
     * @return list<Member>
     */
    private function members(): array
    {
        return $this->members ??= array_values(LandingTeamMember::query()->where('is_visible', true)->whereNotNull('name')->where('name', '!=', '')
            ->orderBy('position')->orderBy('id')->limit(LandingTeamMember::MAX_MEMBERS)->get()
            ->map(fn (LandingTeamMember $member): array => [
                'name' => trim((string) $member->name), 'role' => $member->role_title, 'bio' => $member->bio === null || trim($member->bio) === '' ? null : trim($member->bio),
                'img' => $member->photoUrl(),
                'names' => array_values(array_filter(self::words((string) $member->name), fn (string $word): bool => strlen($word) >= 3)),
                'roles' => self::words($member->role_title.' '.$member->role_label),
            ])->all());
    }
}
