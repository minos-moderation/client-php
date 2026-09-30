<?php

declare(strict_types=1);

namespace Minos\Client;

/**
 * The body of a webhook delivery, read into one normalised shape.
 *
 * The gateway builds the payload from zero, so it carries only the fields below. This class
 * reads them defensively, because a plugin must never turn a payload it does not understand
 * into a verdict:
 *
 * - a `status` other than `ocenione`, or an `ocenione` whose `kwalifikacja` is not one of
 *   the three known values, reads as `nieocenione` — the same rule the gateway applies to
 *   an engine action it does not know. What happens to such a comment is the plugin's
 *   fail-open or fail-closed setting, never a guess;
 * - a category label outside {@see CATEGORIES} is kept: the list may grow, and dropping a
 *   label would hide it from the forum's moderators;
 * - `ocenzurowany` is read only together with `ocenzurowane`.
 *
 * Call it only on a body whose signature {@see Signature::verify} accepted. No PHP 8
 * syntax: it runs inside plugins on customers' hosts.
 */
final class WebhookPayload
{
    /** `status` of a payload that carries a verdict. */
    public const ASSESSED = 'ocenione';

    /** `status` of a payload that carries none. */
    public const UNASSESSED = 'nieocenione';

    /** The three verdicts a payload may carry, in order of severity. */
    public const QUALIFICATIONS = ['bezpieczne', 'ocenzurowane', 'zablokowane'];

    /** The category labels the contract names today (`docs/contract.md`). */
    public const CATEGORIES = [
        'mowa_nienawisci', 'grozba', 'przemoc', 'nekanie', 'tresc_seksualna',
        'samookaleczenie', 'oszustwo', 'spam', 'ujawnianie_danych', 'dane_osobowe',
        'wulgaryzmy', 'uzywki', 'uwodzenie_nieletnich', 'hazard', 'niebezpieczne_zachowanie',
    ];

    /** The item id the plugin sent: what it matches the verdict with. */
    private const ITEM_ID = '/^[A-Za-z0-9._:-]{1,64}\z/';

    /** The opaque `wersja`: 16 hex characters. */
    private const VERSION = '/^[0-9a-f]{16}\z/';

    /**
     * Reads a delivery body.
     *
     * @param string $body The exact body of a delivery whose signature was verified.
     * @return array{id:string,status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool,wersja:?string}|null
     *     The normalised payload, or null when the body is not a payload at all (not a JSON
     *     object, or no valid `id`) — nothing in it can be matched to a comment.
     */
    public static function parse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $id = $data['id'] ?? null;
        if (!is_string($id) || !preg_match(self::ITEM_ID, $id)) {
            return null;
        }

        $qualification = $data['kwalifikacja'] ?? null;
        if (($data['status'] ?? null) !== self::ASSESSED
            || !in_array($qualification, self::QUALIFICATIONS, true)) {
            return self::unassessed($id);
        }

        $categories = [];
        foreach ((is_array($data['kategorie'] ?? null) ? $data['kategorie'] : []) as $label) {
            if (is_string($label) && $label !== '') {
                $categories[$label] = true;
            }
        }
        $masked = $data['ocenzurowany'] ?? null;
        $version = $data['wersja'] ?? null;

        return [
            'id'           => $id,
            'status'       => self::ASSESSED,
            'kwalifikacja' => $qualification,
            'kategorie'    => array_keys($categories),
            'ocenzurowany' => $qualification === 'ocenzurowane' && is_string($masked) ? $masked : null,
            'wsparcie'     => ($data['wsparcie'] ?? null) === true,
            'wersja'       => is_string($version) && preg_match(self::VERSION, $version) ? $version : null,
        ];
    }

    /**
     * The normalised payload of an item without a verdict.
     *
     * @param string $id The item id.
     * @return array{id:string,status:string,kwalifikacja:null,kategorie:array<int,string>,ocenzurowany:null,wsparcie:bool,wersja:null}
     */
    private static function unassessed(string $id): array
    {
        return [
            'id'           => $id,
            'status'       => self::UNASSESSED,
            'kwalifikacja' => null,
            'kategorie'    => [],
            'ocenzurowany' => null,
            'wsparcie'     => false,
            'wersja'       => null,
        ];
    }
}
