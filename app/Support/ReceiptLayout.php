<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receipt Settings (Phase 20): the controlled, safe options a Branch has over its receipt. There is no HTML and no
 * free layout — only which optional blocks show, the order of blocks inside the Details and Footer zones, a short
 * header line, up to five custom plain-text rows and a separator style. The logo on/off and the store name, address,
 * contact and thank-you texts keep their own Branch columns.
 *
 * Zones are fixed (Header → Details → Items → Totals → Payments → Footer) so a receipt can never be scrambled; the
 * required blocks (store name, order number, items, totals) can never be hidden.
 *
 * @phpstan-type Layout array{hidden: list<string>, details: list<string>, footer: list<string>, header_text: string|null, custom_rows: list<string>, separator: 'dashed'|'solid'|'none'}
 */
class ReceiptLayout
{
    /** @var list<string> */
    public const HEADER = ['logo', 'store', 'address', 'contact', 'header_text'];

    /** @var list<string> */
    public const DETAILS = ['order', 'date', 'cashier', 'customer'];

    /** @var list<string> */
    public const BODY = ['items', 'totals', 'payments'];

    /** @var list<string> */
    public const FOOTER = ['custom_rows', 'footer', 'order_qr'];

    /** @var list<string> Always shown. */
    public const REQUIRED = ['store', 'order', 'items', 'totals'];

    /** @var list<string> Shown unless a Branch hides them (the logo follows its own `receipt_show_logo`). */
    public const HIDEABLE = ['address', 'contact', 'header_text', 'date', 'cashier', 'customer', 'payments', 'custom_rows', 'footer', 'order_qr'];

    /** @var list<string> Off until a Branch turns them on. */
    public const OFF_BY_DEFAULT = ['order_qr'];

    public const MAX_CUSTOM_ROWS = 5;

    public const MAX_TEXT = 120;

    /** @return Layout */
    public static function defaults(): array
    {
        return [
            'hidden' => self::OFF_BY_DEFAULT,
            'details' => self::DETAILS,
            'footer' => self::FOOTER,
            'header_text' => null,
            'custom_rows' => [],
            'separator' => 'dashed',
        ];
    }

    /**
     * A stored layout, repaired against the current block list (unknown keys dropped, missing ones appended), so a
     * receipt always renders even from an older or hand-edited value.
     *
     * @return Layout
     */
    public static function normalize(mixed $stored): array
    {
        $defaults = self::defaults();
        if (! is_array($stored)) {
            return $defaults;
        }
        $order = fn (mixed $value, array $zone): array => array_values(array_unique([
            ...array_values(array_filter(is_array($value) ? $value : [], fn (mixed $key): bool => is_string($key) && in_array($key, $zone, true))),
            ...$zone,
        ]));
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? mb_substr(self::plain($value), 0, self::MAX_TEXT) : null;

        return [
            'hidden' => array_values(array_intersect(self::HIDEABLE, is_array($stored['hidden'] ?? null) ? $stored['hidden'] : $defaults['hidden'])),
            'details' => $order($stored['details'] ?? null, self::DETAILS),
            'footer' => $order($stored['footer'] ?? null, self::FOOTER),
            'header_text' => $text($stored['header_text'] ?? null),
            'custom_rows' => array_slice(array_values(array_filter(array_map($text, is_array($stored['custom_rows'] ?? null) ? $stored['custom_rows'] : []))), 0, self::MAX_CUSTOM_ROWS),
            'separator' => in_array($stored['separator'] ?? null, ['dashed', 'solid', 'none'], true) ? $stored['separator'] : 'dashed',
        ];
    }

    /**
     * Validates a submitted layout strictly (a permutation of each zone, known hideable keys, bounded plain text).
     *
     * @return Layout
     *
     * @throws ValidationException
     */
    public static function validate(mixed $input): array
    {
        $data = Validator::make(['receipt_layout' => $input], [
            'receipt_layout' => ['required', 'array'],
            'receipt_layout.hidden' => ['present', 'array'],
            'receipt_layout.hidden.*' => ['string', 'distinct', Rule::in(self::HIDEABLE)],
            'receipt_layout.details' => ['required', 'array', 'size:'.count(self::DETAILS)],
            'receipt_layout.details.*' => ['string', 'distinct', Rule::in(self::DETAILS)],
            'receipt_layout.footer' => ['required', 'array', 'size:'.count(self::FOOTER)],
            'receipt_layout.footer.*' => ['string', 'distinct', Rule::in(self::FOOTER)],
            'receipt_layout.header_text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'receipt_layout.custom_rows' => ['present', 'array', 'max:'.self::MAX_CUSTOM_ROWS],
            'receipt_layout.custom_rows.*' => ['string', 'max:'.self::MAX_TEXT],
            'receipt_layout.separator' => ['required', Rule::in(['dashed', 'solid', 'none'])],
        ], [
            'receipt_layout.custom_rows.max' => 'Use at most '.self::MAX_CUSTOM_ROWS.' custom text rows.',
            'receipt_layout.*.distinct' => 'Each receipt block can appear once.',
        ])->validate();

        return self::normalize($data['receipt_layout']);
    }

    /**
     * The visible blocks of a Branch's receipt in print order.
     *
     * @param  Layout  $layout
     * @return list<string>
     */
    public static function blocks(array $layout, bool $showLogo): array
    {
        $visible = fn (string $block): bool => in_array($block, self::REQUIRED, true)
            || ($block === 'logo' ? $showLogo : ! in_array($block, $layout['hidden'], true));

        return array_values(array_filter([...self::HEADER, ...$layout['details'], ...self::BODY, ...$layout['footer']], $visible));
    }

    /** Plain single-line text: no control characters or markup-looking brackets survive. */
    private static function plain(string $value): string
    {
        return trim((string) preg_replace(['/[\x00-\x1F\x7F]+/u', '/\s+/u'], [' ', ' '], strip_tags($value)));
    }
}
