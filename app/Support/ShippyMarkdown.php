<?php

namespace App\Support;

/**
 * Reads an uploaded Markdown document as plain reference text for Shippy (v1.3.0): its headings and the text under
 * each. Nothing here renders Markdown: markup, links, images and HTML are reduced to their words, so what a guest
 * later reads is always text. A line that tries to instruct an assistant is left out, because a document is data.
 *
 * @phpstan-type Section array{heading: string, text: string}
 */
class ShippyMarkdown
{
    /** The most sections read from one document and the longest text kept for one. */
    public const MAX_SECTIONS = 200;

    public const MAX_SECTION = 2000;

    /** Wording that addresses the assistant instead of informing a guest. */
    private const INSTRUCTION = '/\b(?:ignore|disregard|forget|override)\b.{0,40}\b(?:instructions?|rules?|prompts?)\b|\bsystem (?:prompt|message|instruction)|\byou are now\b|\bact as\b|\bpretend (?:to be|you)\b|\bdeveloper mode\b|\bjailbreak\b/iu';

    /**
     * Why a file's bytes cannot be used as a document, or null when they can.
     */
    public static function problem(string $bytes): ?string
    {
        return match (true) {
            trim($bytes) === '' => 'is empty.',
            ! mb_check_encoding($bytes, 'UTF-8') => 'is not UTF-8 text. Save it as UTF-8 and upload it again.',
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $bytes) === 1 => 'is not a text file.',
            self::sections($bytes, 'document') === [] => 'has no readable text.',
            default => null,
        };
    }

    /**
     * The document as sections, in order: the text before the first heading belongs to the document's own name, and a
     * heading without text under it is skipped.
     *
     * @return list<Section>
     */
    public static function sections(string $markdown, string $filename): array
    {
        $markdown = (string) preg_replace('/\A\x{FEFF}/u', '', str_replace(["\r\n", "\r"], "\n", $markdown));
        /** Front matter, comments, scripts and styles are for tools, not for guests. */
        $markdown = (string) preg_replace(['/\A---\n.*?\n---\n/s', '/<!--.*?-->/s', '~<(script|style)\b.*?</\1\s*>~is'], '', $markdown);
        $sections = [];
        $heading = self::title($filename);
        $lines = [];
        $close = function () use (&$sections, &$heading, &$lines): void {
            $text = trim(implode("\n", $lines));
            if ($text !== '' && count($sections) < self::MAX_SECTIONS) {
                $sections[] = ['heading' => $heading, 'text' => mb_substr($text, 0, self::MAX_SECTION)];
            }
            $lines = [];
        };
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/u', $line, $found) === 1) {
                $close();
                $heading = self::plain($found[1]) ?: self::title($filename);

                continue;
            }
            $plain = self::plain($line);
            if ($plain !== '' && preg_match(self::INSTRUCTION, $plain) !== 1) {
                $lines[] = $plain;
            }
        }
        $close();

        return $sections;
    }

    /** A file name as a title: no folder, no extension. */
    public static function title(string $filename): string
    {
        return trim((string) preg_replace('/\.(md|markdown)$/i', '', basename(str_replace('\\', '/', $filename)))) ?: 'Document';
    }

    /** One Markdown line as the words a person would read. */
    private static function plain(string $line): string
    {
        $line = (string) preg_replace(['/!\[([^\]]*)\]\([^)]*\)/u', '/\[([^\]]*)\]\([^)]*\)/u', '/\[([^\]]*)\]\[[^\]]*\]/u'], '$1', $line);
        $line = strip_tags($line);
        /** Rules, code fences, table rulers and reference definitions carry no words. */
        if (preg_match('/^\s*(?:[-*_=]{3,}|`{3,}.*|~{3,}.*|\|?[\s:|-]+\||\[[^\]]+\]:\s.*)\s*$/u', $line) === 1) {
            return '';
        }
        $line = (string) preg_replace(['/^\s*(?:>\s?)+/u', '/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?/u'], '', $line);
        $line = str_replace(['**', '__', '~~', '`', '|'], ['', '', '', '', ' '], $line);
        $line = (string) preg_replace('/(?<![\p{L}\p{N}])[*_]|[*_](?![\p{L}\p{N}])/u', '', $line);

        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $line));
    }
}
