<?php

namespace App\Support;

/**
 * The language Shippy answers in (v1.3.0), decided by the engine for every source alike, never by a document:
 *
 * 1. a guest who asks for it ("Talk in English", "Taglish ulit") gets that language until they ask for the other;
 * 2. otherwise a question written fully in English is answered fully in English;
 * 3. anything else (Tagalog, mixed, or too short to tell, such as a dish name alone) gets casual Taglish.
 */
class ShippyLanguage
{
    public const ENGLISH = 'english';

    public const TAGLISH = 'taglish';

    /** Asking to switch: the phrase and the language it asks for. */
    private const REQUESTS = [
        self::ENGLISH => '\b(?:talk|speak|reply|respond|answer|chat|write|type)\b[\w\s]{0,12}?\benglish\b|\bin english\b|\benglish (?:only|please|pls|lang|nalang|na lang|na|ka|mo|mode|ulit|again)\b|\bmag-?english\b|^\s*english\s*[.!?]*\s*$',
        self::TAGLISH => '\btaglish\b|\b(?:talk|speak|reply|respond|answer|chat|write|type)\b[\w\s]{0,12}?\b(?:tagalog|filipino)\b|\btagalog (?:only|please|pls|lang|nalang|na lang|na|ka|mo|ulit|again)\b|\bmag-?tagalog\b',
    ];

    /** Words that make a message Tagalog or mixed. Words both languages use ("may", "at", "to") are left out. */
    private const TAGALOG = [
        'po', 'opo', 'ba', 'bang', 'kayo', 'kau', 'ako', 'ko', 'mo', 'ka', 'nyo', 'niyo', 'ninyo', 'namin', 'natin', 'kami', 'tayo',
        'sila', 'siya', 'ang', 'ng', 'mga', 'sa', 'si', 'ni', 'kay', 'yung', 'ung', 'yun', 'iyon', 'ito', 'iyan', 'yan', 'dito', 'diyan',
        'dyan', 'doon', 'na', 'pa', 'din', 'rin', 'naman', 'nga', 'lang', 'lng', 'talaga', 'ano', 'anong', 'anu', 'sino', 'sinong', 'saan',
        'nasaan', 'kailan', 'kelan', 'paano', 'pano', 'bakit', 'alin', 'ilan', 'magkano', 'meron', 'mayroon', 'wala', 'walang', 'pwede',
        'puwede', 'pede', 'gusto', 'kung', 'hindi', 'di', 'oo', 'salamat', 'masarap', 'bukas', 'sarado', 'ngayon', 'mamaya', 'kanina',
        'kasi', 'sana', 'baka', 'daw', 'raw', 'muna', 'sige', 'ulit', 'kain', 'kumain', 'pagkain', 'kanin', 'itlog', 'ulam', 'presyo',
        'mura', 'mahal', 'lahat', 'tanong', 'pag', 'kapag', 'nang', 'para', 'eh', 'ha', 'tsaka', 'saka', 'pati', 'tapos', 'diba',
    ];

    /** English words that only an English sentence uses; dish names and nouns Taglish borrows do not count. */
    private const ENGLISH_WORDS = [
        'what', 'whats', 'where', 'wheres', 'when', 'who', 'whos', 'why', 'how', 'which', 'do', 'does', 'did', 'is', 'are', 'was',
        'were', 'am', 'can', 'could', 'would', 'will', 'should', 'you', 'your', 'youre', 'i', 'im', 'my', 'me', 'we', 'our', 'us',
        'they', 'it', 'its', 'the', 'a', 'an', 'have', 'has', 'there', 'this', 'that', 'these', 'those', 'of', 'for', 'in', 'on',
        'with', 'and', 'or', 'but', 'if', 'any', 'some', 'please', 'much', 'many', 'want', 'need', 'get', 'about', 'tell', 'still',
    ];

    /**
     * The language a message asks Shippy to switch to and the question left once that request is taken out, which
     * is empty when the message only asked to switch.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function requested(string $message): array
    {
        $lower = mb_strtolower($message);
        $found = null;
        $at = -1;
        $rest = $message;
        foreach (self::REQUESTS as $language => $pattern) {
            if (preg_match('/'.$pattern.'/u', $lower, $match, PREG_OFFSET_CAPTURE) === 1 && $match[0][1] > $at) {
                [$found, $at] = [$language, $match[0][1]];
                $rest = (string) preg_replace('/'.$pattern.'/u', ' ', $lower);
            }
        }
        if ($found === null) {
            return [null, $message];
        }
        $left = array_diff(ShippyKnowledge::terms($rest), ['ulit', 'again', 'back', 'switch', 'now', 'mode', 'pls', 'thanks', 'sige', 'nalang', 'po']);

        return [$found, $left === [] ? '' : trim((string) preg_replace('/\s+/u', ' ', $rest))];
    }

    /**
     * The language a reply is written in: the guest's request first, then a question written fully in English, and
     * casual Taglish for everything else.
     */
    public static function reply(string $message, ?string $preference): string
    {
        if ($preference === self::ENGLISH || $preference === self::TAGLISH) {
            return $preference;
        }
        $words = self::words($message);
        $english = count(array_intersect($words, self::ENGLISH_WORDS));

        return $english > 0 && count($words) >= 2 && ! self::isTagalog($message) ? self::ENGLISH : self::TAGLISH;
    }

    /** Whether a text has any Tagalog in it: an approved answer like that is put into English for an English reply. */
    public static function isTagalog(string $text): bool
    {
        return array_intersect(self::words($text), self::TAGALOG) !== [];
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return preg_split('/[^a-zñ]+/u', str_replace(['’', "'"], '', mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
