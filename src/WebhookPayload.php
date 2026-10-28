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
 * - `kategorie` is read only from a JSON list; a category label outside {@see CATEGORIES}
 *   is kept: the list may grow, and dropping a label would hide it from the forum's
 *   moderators;
 * - `ocenzurowany` is read only together with `ocenzurowane`, and only as a text that masks
 *   something (at least one `█`). An empty or unmasked one reads as absent, so the plugin
 *   holds the comment, as the receiving checklist says for a missing one;
 * - the `dane_osobowe` section (a comment sent with a conversation context that carries a
 *   personal datum) is read as `{rodzaj, reakcja}`: a `reakcja` other than the three known
 *   ones reads as `nieocenione` (the question got no usable answer), and a kind outside
 *   {@see PERSONAL_DATA_KINDS} is kept, like a category. Without the section, `[]`.
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

    /** The kinds of personal datum the `dane_osobowe` section names today (`docs/contract.md`). */
    public const PERSONAL_DATA_KINDS = ['adres', 'telefon', 'pesel', 'karta_platnicza', 'rachunek'];

    /**
     * The reactions of the `dane_osobowe` section: hide the datum, leave it, or no answer to
     * the question (`nieocenione`, the same word as the payload's status).
     */
    public const PERSONAL_DATA_REACTIONS = ['ukryj', 'pozostaw', 'nieocenione'];

    /** The item id the plugin sent: what it matches the verdict with. */
    private const ITEM_ID = '/^[A-Za-z0-9._:-]{1,64}\z/';

    /** The opaque `wersja`: 16 hex characters. */
    private const VERSION = '/^[0-9a-f]{16}\z/';

    /** The mask character, U+2588 FULL BLOCK, in UTF-8 (no mbstring needed). */
    private const MASK = "\xE2\x96\x88";

    /**
     * Reads a delivery body.
     *
     * @param string $body The exact body of a delivery whose signature was verified.
     * @return array{id:string,status:string,kwalifikacja:?string,kategorie:array<int,string>,ocenzurowany:?string,wsparcie:bool,wersja:?string,dane_osobowe:array{}|array{rodzaj:?string,reakcja:string}}|null
     *     The normalised payload, or null when the body is not a payload at all (not a JSON
     *     object, or no valid `id`) — nothing in it can be matched to a comment.
     */
    public static function parse(string $body): ?array
    {
        // Objects stay objects, so a JSON list is never read as an object, nor one as a list.
        $decoded = json_decode($body);
        if (!$decoded instanceof \stdClass) {
            return null;
        }
        $data = get_object_vars($decoded);
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
        // A JSON list decodes to an array, a JSON object to a \stdClass: only a list is read.
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
            'ocenzurowany' => $qualification === 'ocenzurowane' && is_string($masked)
                && strpos($masked, self::MASK) !== false ? $masked : null,
            'wsparcie'     => ($data['wsparcie'] ?? null) === true,
            'wersja'       => is_string($version) && preg_match(self::VERSION, $version) ? $version : null,
            'dane_osobowe' => self::personalData($data['dane_osobowe'] ?? null),
        ];
    }

    /**
     * The `dane_osobowe` section, read so that nothing unknown passes for an answer.
     *
     * @param mixed $section The decoded section, or null when the payload has none.
     * @return array{}|array{rodzaj:?string,reakcja:string} `[]` without a section; else the
     *     kind (null when it is not a non-empty string) and the reaction (`nieocenione` when
     *     it is not one of {@see PERSONAL_DATA_REACTIONS}). A section that is there but is
     *     not an object reads as a kind unknown and no answer, never as no section.
     */
    private static function personalData($section): array
    {
        if ($section === null) {
            return [];
        }
        $fields = $section instanceof \stdClass ? get_object_vars($section) : [];
        $kind = $fields['rodzaj'] ?? null;
        $reaction = $fields['reakcja'] ?? null;
        return [
            'rodzaj'  => is_string($kind) && $kind !== '' ? $kind : null,
            'reakcja' => in_array($reaction, self::PERSONAL_DATA_REACTIONS, true) ? $reaction : self::UNASSESSED,
        ];
    }

    /**
     * The normalised payload of an item without a verdict.
     *
     * @param string $id The item id.
     * @return array{id:string,status:string,kwalifikacja:null,kategorie:array<int,string>,ocenzurowany:null,wsparcie:bool,wersja:null,dane_osobowe:array{}}
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
            'dane_osobowe' => [],
        ];
    }
}
