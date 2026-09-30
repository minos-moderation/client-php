<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * The mock's "engine": a verdict read from markers in the comment itself.
 *
 * The mock never assesses content. A plugin developer steers every branch of the contract
 * by typing markers into the test comment:
 *
 * | marker | effect |
 * |---|---|
 * | (none) | `ocenione`, `bezpieczne`, no categories |
 * | `[minos:blokuj]` | `zablokowane` |
 * | `[minos:cenzuruj]` | `ocenzurowane`; every `[[fragment]]` is masked with `█` per character in `ocenzurowany` (no `[[…]]` = no `ocenzurowany`, as in the contract) |
 * | `[minos:kategoria=<label>]` | adds the category (repeatable); `samookaleczenie` also sets `wsparcie` |
 * | `[minos:nieocenione]` | `{"id", "status": "nieocenione"}` (`{"status": "nieocenione"}` on the synchronous route) |
 * | `[minos:bez-wersji]` | `wersja: null` |
 *
 * Delivery markers are read by {@see Worker} ({@see deliveryFlags}): `[minos:dwa-razy]`
 * (delivered twice, as at-least-once allows), `[minos:zly-podpis]` (signed with a wrong
 * secret), `[minos:stary-podpis]` (timestamp ten minutes old), `[minos:cisza]` (never
 * delivered; disappears with its TTL, like an entry the real worker never reached).
 *
 * The payload is built like the gateway builds it: the same fields in the same order,
 * nothing more. The synchronous route answers {@see verdict}, the same payload without `id`.
 */
final class Verdicts
{
    /** `wersja` of every mock verdict: 16 hex characters ("mockmock"). */
    public const VERSION = '6d6f636b6d6f636b';

    /** Delivery flags {@see Worker} understands. */
    public const DELIVERY_FLAGS = ['dwa-razy', 'zly-podpis', 'stary-podpis', 'cisza'];

    /** Category labels of the contract; a marker with another label is ignored. */
    private const CATEGORIES = [
        'mowa_nienawisci', 'grozba', 'przemoc', 'nekanie', 'tresc_seksualna',
        'samookaleczenie', 'oszustwo', 'spam', 'ujawnianie_danych', 'dane_osobowe',
        'wulgaryzmy', 'uzywki', 'uwodzenie_nieletnich', 'hazard', 'niebezpieczne_zachowanie',
    ];

    /** The mask character (the gateway's). */
    private const MASK = '█';

    /**
     * The webhook payload for a queued item.
     *
     * @param string $itemId The client's item id.
     * @param string $text   The comment, with its markers.
     * @return array<string,mixed> The payload.
     */
    public static function payload(string $itemId, string $text): array
    {
        return ['id' => $itemId] + self::verdict($text);
    }

    /**
     * The verdict for a comment: the webhook payload without `id`, which is what the
     * synchronous route answers.
     *
     * @param string $text The comment, with its markers.
     * @return array<string,mixed> `{status, kwalifikacja, kategorie, ocenzurowany?, wsparcie,
     *     wersja}`, or exactly `{status: nieocenione}`.
     */
    public static function verdict(string $text): array
    {
        $markers = self::markers($text);
        if (isset($markers['nieocenione'])) {
            return ['status' => 'nieocenione'];
        }

        $qualification = 'bezpieczne';
        if (isset($markers['cenzuruj'])) {
            $qualification = 'ocenzurowane';
        }
        if (isset($markers['blokuj'])) {
            $qualification = 'zablokowane';
        }
        $categories = array_values(array_intersect(self::CATEGORIES, $markers['kategoria'] ?? []));
        sort($categories);

        $verdict = [
            'status'       => 'ocenione',
            'kwalifikacja' => $qualification,
            'kategorie'    => $categories,
        ];
        if ($qualification === 'ocenzurowane') {
            $masked = preg_replace_callback('/\[\[(.+?)\]\]/u', static function (array $m): string {
                return str_repeat(self::MASK, mb_strlen($m[1]));
            }, $text);
            if (is_string($masked) && $masked !== $text) {
                $verdict['ocenzurowany'] = $masked;
            }
        }
        $verdict['wsparcie'] = in_array('samookaleczenie', $categories, true);
        $verdict['wersja'] = isset($markers['bez-wersji']) ? null : self::VERSION;
        return $verdict;
    }

    /**
     * The delivery flags of a comment.
     *
     * @param string $text The comment.
     * @return array<int,string> Flags from {@see DELIVERY_FLAGS}.
     */
    public static function deliveryFlags(string $text): array
    {
        return array_values(array_intersect(self::DELIVERY_FLAGS, array_keys(self::markers($text))));
    }

    /**
     * The `[minos:…]` markers of a comment.
     *
     * @param string $text The comment.
     * @return array<string,mixed> Marker name → true, and `kategoria` → its labels.
     */
    private static function markers(string $text): array
    {
        $markers = [];
        preg_match_all('/\[minos:([a-z-]+)(?:=([a-z_]+))?\]/u', $text, $found, PREG_SET_ORDER);
        foreach ($found as $m) {
            if ($m[1] === 'kategoria' && isset($m[2])) {
                $markers['kategoria'][] = $m[2];
            } else {
                $markers[$m[1]] = true;
            }
        }
        return $markers;
    }
}
