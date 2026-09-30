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
 * | `[minos:cenzuruj]` | `ocenzurowane`; in `ocenzurowany` every `[[fragment]]` becomes `[[█…]]`, `█` per character, so it keeps the sent text's length (no `[[…]]`, or nested or unbalanced brackets = no `ocenzurowany`, as in the contract) |
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
            $masked = self::masked($text);
            if ($masked !== null) {
                $verdict['ocenzurowany'] = $masked;
            }
        }
        $verdict['wsparcie'] = in_array('samookaleczenie', $categories, true);
        $verdict['wersja'] = isset($markers['bez-wersji']) ? null : self::VERSION;
        return $verdict;
    }

    /**
     * The masked text of a comment: the characters of every `[[fragment]]` replaced with
     * `█`, one per character, and the brackets kept.
     *
     * The gateway's masked text has exactly the length of the (trimmed) text that was sent,
     * and a plugin may rely on that; keeping the brackets keeps it here too. The markers are
     * the mock's own artefact, so they stay visible, like `[minos:cenzuruj]` does.
     *
     * @param string $text The comment, with its markers.
     * @return string|null The masked text; null when nothing was masked, or when the brackets
     *     are nested or unbalanced, because then the mock cannot tell what to mask.
     */
    private static function masked(string $text): ?string
    {
        // One capturing group: even indices are text, odd ones a `[[` or a `]]`.
        $parts = preg_split('/(\[\[|\]\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return null;
        }
        $masked = '';
        $inside = false;
        $maskedAny = false;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                // A `[[` inside a fragment, or a `]]` outside one.
                if (($part === '[[') === $inside) {
                    return null;
                }
                $inside = !$inside;
                $masked .= $part;
            } elseif ($inside && $part !== '') {
                $masked .= str_repeat(self::MASK, mb_strlen($part));
                $maskedAny = true;
            } else {
                $masked .= $part;
            }
        }
        return $inside || !$maskedAny ? null : $masked;
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
