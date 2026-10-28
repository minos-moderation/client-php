<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * The mock's two B2B routes: `POST /api/v1/b2b/oceny` ({@see handle}) checks a batch the way
 * the contract describes the gateway's and queues it; `POST /api/v1/b2b/ocena`
 * ({@see handleSync}) checks one comment and answers its verdict at once.
 *
 * The checks, their order and the error codes follow the contract's copy
 * (`docs/contract.md` → each route's Errors). The batch: the key, the body's size, the JSON,
 * the webhook, then the batch whole or not at all — first every item's shape, id, text and
 * length, then every item's profile, then every item's conversation context — and the
 * queue's room, each refusal of an item naming its position in `blad.element`. Where the
 * copy places no check (the body's size and the JSON against the webhook, the context in
 * the batch), the order is the mock's, and the README says so. The synchronous route: the
 * key, the route's own switch, the key's class, the body's size, the JSON, the text, the
 * profile, the context, without `id` and without `element`; nothing is queued. The key's
 * rate limits are not simulated — ask for any refusal of the route with the mock-only
 * header {@see FORCE_HEADER} instead.
 *
 * The body is decoded with JSON objects kept as objects, so a JSON object is never taken for
 * a list, nor a list for an object: the contract tells them apart (`elementy` is a list, an
 * item and a context entry are objects, `kontekst` is a list).
 */
final class Intake
{
    /**
     * Mock-only request header: answer with this documented error code instead of
     * assessing, or with one of {@see MOCK_SCENARIOS}. It does not exist in the gateway.
     */
    public const FORCE_HEADER = 'X-Minos-Mock-Error';

    /** An item id: 1–64 characters, no spaces, no content. */
    private const ITEM_ID = '/^[A-Za-z0-9._:-]{1,64}\z/';

    /** The roles of a context entry: the assessed comment's author, or anyone else. */
    private const CONTEXT_ROLES = ['autor', 'inny'];

    /** Seconds after which a client refused for a full queue may try again. */
    private const FULL_RETRY_S = 60;

    /** The most bytes one character may take in the body: an escaped surrogate pair. */
    private const ESCAPED_CHAR_BYTES = 12;

    /** Bytes the body ceiling grants an item's fields other than its texts. */
    private const ITEM_OVERHEAD_BYTES = 1024;

    /** Bytes the body ceiling grants a context entry's fields other than its text. */
    private const CONTEXT_ENTRY_OVERHEAD_BYTES = 64;

    /** Bytes the body ceiling grants the rest of the body. */
    private const BODY_OVERHEAD_BYTES = 16384;

    /**
     * Every refusal of the contract: code → [HTTP status, message, `ponow_za_s`, `element`
     * of a forced refusal on the batch route]. The messages follow the gateway's; a client
     * reads `kod`, never `komunikat`.
     */
    private const ERRORS = [
        'brak_klucza'              => [401, 'Ta trasa wymaga klucza B2B.', null, null],
        'nie_ta_powierzchnia'      => [403, 'Ta trasa jest dla kluczy B2B.', null, null],
        'tylko_klucze_platne'      => [403, 'Ocena synchroniczna jest tylko dla kluczy płatnych. Klucz bezpłatny korzysta z trasy asynchronicznej /api/v1/b2b/oceny.', null, null],
        'brak_webhooka'            => [403, 'Ten klucz nie ma skonfigurowanego adresu zwrotnego — nie mielibyśmy gdzie odesłać werdyktu.', null, null],
        'profil_niedozwolony'      => [403, 'Ten klucz nie ma dostępu do wskazanego profilu.', null, 0],
        'bledne_wejscie'           => [400, 'Oczekuję pola `elementy` z listą komentarzy.', null, null],
        'brak_elementow'           => [400, 'Lista `elementy` jest pusta.', null, null],
        'bledny_identyfikator'     => [400, 'Identyfikator: 1–64 znaki z zakresu A–Z, a–z, 0–9, `.`, `_`, `:`, `-`.', null, 0],
        'powtorzony_identyfikator' => [400, 'Identyfikatory w jednym żądaniu muszą być różne.', null, 1],
        'brak_tekstu'              => [400, 'Element nie ma treści do oceny.', null, 0],
        'bledny_kontekst'          => [400, 'Pole `kontekst` musi być listą wpisów {rola: autor albo inny, tekst}.', null, 0],
        'za_duzo_elementow'        => [413, 'Jedno żądanie przyjmuje najwyżej %d komentarzy.', null, null],
        'limit_dlugosci'           => [413, 'Komentarz jest dłuższy niż %d znaków.', null, 0],
        'za_duzy_kontekst'         => [413, 'Kontekst może mieć najwyżej 8 wpisów po 500 znaków.', null, 0],
        'za_duze_zadanie'          => [413, 'To żądanie jest za duże.', null, null],
        'kolejka_pelna'            => [429, 'Kolejka ocen jest pełna. Spróbuj ponownie za chwilę.', self::FULL_RETRY_S, null],
        'limit_minutowy_klucza'    => [429, 'Za dużo zapytań w tej minucie dla tego klucza.', 30, null],
        'limit_dobowy_klucza'      => [429, 'Wykorzystano dzienny limit tego klucza.', 3600, null],
        'limit_w_locie_klucza'     => [429, 'Ten klucz ma już maksymalną liczbę zapytań w toku.', 1, null],
        'limit_globalny_b2b'       => [429, 'Usługa jest chwilowo przeciążona. Spróbuj ponownie później.', 300, null],
        'kolejka_niedostepna'      => [503, 'Kolejka ocen jest chwilowo niedostępna. Spróbuj później.', null, null],
        'silnik_przeciazony'       => [503, 'Silnik oceny jest chwilowo zajęty. Spróbuj ponownie za chwilę.', 30, null],
        'nie_znaleziono'           => [404, 'Nie ma tu nic.', null, null],
    ];

    /** The message of a profile the key lists but the B2B list does not. */
    private const NOT_B2B_PROFILE = 'Ten profil nie jest dostępny dla kluczy B2B (dozwolone: forum_adult, forum_teen, blog_comments, ig_comments, api_generic).';

    /** Codes only the batch route sends ({@see batchCodes}). */
    private const BATCH_ONLY = [
        'brak_webhooka', 'brak_elementow', 'bledny_identyfikator', 'powtorzony_identyfikator',
        'za_duzo_elementow', 'kolejka_pelna', 'kolejka_niedostepna',
    ];

    /** Codes only the synchronous route sends ({@see syncCodes}). */
    private const SYNC_ONLY = ['tylko_klucze_platne', 'silnik_przeciazony'];

    /** @var Config */
    private $cfg;

    /** @var Queue */
    private $queue;

    /**
     * @param Config     $cfg   The configuration.
     * @param Queue|null $queue The queue (tests inject one); the configured file by default.
     */
    public function __construct(Config $cfg, ?Queue $queue = null)
    {
        $this->cfg = $cfg;
        $this->queue = $queue ?? new Queue($cfg->queuePath());
    }

    /**
     * The codes {@see FORCE_HEADER} accepts on the batch route: the refusals its contract
     * table lists, and no other.
     *
     * @return array<int,string> The codes.
     */
    public static function batchCodes(): array
    {
        return array_values(array_diff(array_keys(self::ERRORS), self::SYNC_ONLY));
    }

    /**
     * The codes {@see FORCE_HEADER} accepts on the synchronous route: the refusals its
     * contract table lists, and no other (never a queue's or a webhook's).
     *
     * @return array<int,string> The codes.
     */
    public static function syncCodes(): array
    {
        return array_values(array_diff(array_keys(self::ERRORS), self::BATCH_ONLY));
    }

    /**
     * The body ceiling of a route: every item at its character ceiling with a full context,
     * each character escaped at 12 bytes (a character outside the BMP sent as ASCII-escaped
     * JSON is a surrogate pair, `😀`), 1 KiB for an item's other fields, 64 bytes
     * for a context entry's, and 16 KiB for the rest. So a valid request is never refused for
     * how its JSON encoder escapes it (`json_encode` without `JSON_UNESCAPED_UNICODE`).
     *
     * @param Config $cfg   The configuration (the item and character ceilings).
     * @param int    $items The items the route takes at most (1 on the synchronous route).
     * @return int Bytes: 1,727,104 on the batch route and 101,920 on the synchronous one at
     *     the defaults.
     */
    public static function bodyCeiling(Config $cfg, int $items): int
    {
        $context = Config::MAX_CONTEXT_ENTRIES
            * (Config::MAX_CONTEXT_CHARS * self::ESCAPED_CHAR_BYTES + self::CONTEXT_ENTRY_OVERHEAD_BYTES);
        return $items * ($cfg->maxChars * self::ESCAPED_CHAR_BYTES + self::ITEM_OVERHEAD_BYTES + $context)
            + self::BODY_OVERHEAD_BYTES;
    }

    /**
     * Handles one batch request (`POST /api/v1/b2b/oceny`).
     *
     * @param string|null $key    The `X-Gateway-Key` header, or null.
     * @param string|null $force  The {@see FORCE_HEADER} header, or null.
     * @param string      $body   The raw body.
     * @param int         $now    Unix seconds.
     * @return array{status:int,body:array} `202 {przyjete}` or `{blad}`.
     */
    public function handle(?string $key, ?string $force, string $body, int $now): array
    {
        if (!$this->knowsKey($key)) {
            return self::refuse('brak_klucza');
        }
        if ($force !== null && $force !== '') {
            return $this->forced($force, true);
        }
        if (strlen($body) > self::bodyCeiling($this->cfg, $this->cfg->maxItems)) {
            return self::refuse('za_duze_zadanie');
        }
        $decoded = json_decode($body);
        if (!$decoded instanceof \stdClass) {
            return self::answer(400, 'bledne_wejscie', 'Nie rozumiem tego żądania.');
        }
        if ($this->cfg->webhookUrl === null) {
            return self::refuse('brak_webhooka');
        }

        $parsed = $this->items(get_object_vars($decoded)['elementy'] ?? null, $now);
        if (isset($parsed['blad'])) {
            return $parsed['blad'];
        }
        if ($this->queue->length() + count($parsed['elementy']) > $this->cfg->queueMax) {
            return self::refuse('kolejka_pelna');
        }
        $this->queue->append($parsed['elementy']);
        return ['status' => 202, 'body' => ['przyjete' => array_column($parsed['elementy'], 'item_id')]];
    }

    /**
     * Handles one synchronous request (`POST /api/v1/b2b/ocena`): one comment, its verdict
     * in the response, nothing queued and nothing kept.
     *
     * @param string|null $key   The `X-Gateway-Key` header, or null.
     * @param string|null $force The {@see FORCE_HEADER} header, or null.
     * @param string      $body  The raw body.
     * @return array{status:int,body:array} `200` with the verdict ({@see Verdicts::verdict}),
     *     or `{blad}` without `element`.
     */
    public function handleSync(?string $key, ?string $force, string $body): array
    {
        if (!$this->knowsKey($key)) {
            return self::refuse('brak_klucza');
        }
        // Closed, the route is absent: the class, the body and a forced code are not looked at.
        if (!$this->cfg->syncOpen) {
            return self::refuse('nie_znaleziono');
        }
        if (!$this->cfg->isPaidKey()) {
            return self::refuse('tylko_klucze_platne');
        }
        if ($force !== null && $force !== '') {
            return $this->forced($force, false);
        }
        if (strlen($body) > self::bodyCeiling($this->cfg, 1)) {
            return self::refuse('za_duze_zadanie');
        }
        $decoded = json_decode($body);
        if (!$decoded instanceof \stdClass) {
            return self::answer(400, 'bledne_wejscie', 'Nie rozumiem tego żądania.');
        }
        $fields = get_object_vars($decoded);
        // A `tekst` that is there but is not a string is a malformed request, not a missing
        // text (a batch item still answers `brak_tekstu`, as its contract says).
        if (array_key_exists('tekst', $fields) && !is_string($fields['tekst'])) {
            return self::answer(400, 'bledne_wejscie', 'Pole `tekst` musi być napisem.');
        }
        $text = $this->text($fields, null);
        if (isset($text['blad'])) {
            return $text['blad'];
        }
        $profile = $this->profile($fields, null);
        if (isset($profile['blad'])) {
            return $profile['blad'];
        }
        $context = self::context($fields, null);
        if (isset($context['blad'])) {
            return $context['blad'];
        }
        return ['status' => 200, 'body' => Verdicts::verdict($text['text'], $context['context'] !== null)];
    }

    /**
     * Whether the request carries the mock's key.
     *
     * @param string|null $key The `X-Gateway-Key` header, or null.
     * @return bool True for the configured key alone.
     */
    private function knowsKey(?string $key): bool
    {
        return $key !== null && hash_equals($this->cfg->key, $key);
    }

    /**
     * Validates the batch, whole or not at all, and builds the queue items: every item's
     * shape, id, text and length first, then every item's profile, then every item's
     * context, so a refusal names the first item that fails the earliest step.
     *
     * @param mixed $batch The `elementy` field, as decoded with objects kept.
     * @param int   $now   Unix seconds.
     * @return array{elementy:array<int,array<string,mixed>>}|array{blad:array{status:int,body:array}}
     */
    private function items($batch, int $now): array
    {
        // A JSON list decodes to an array, a JSON object to a \stdClass.
        if (!is_array($batch)) {
            return ['blad' => self::refuse('bledne_wejscie')];
        }
        if ($batch === []) {
            return ['blad' => self::refuse('brak_elementow')];
        }
        if (count($batch) > $this->cfg->maxItems) {
            return ['blad' => self::refuse('za_duzo_elementow', $this->cfg->maxItems)];
        }

        $seen = [];
        $items = [];
        foreach ($batch as $position => $element) {
            if (!$element instanceof \stdClass) {
                return ['blad' => self::answer(400, 'bledne_wejscie', 'Każdy element musi być obiektem.', null, $position)];
            }
            $fields = get_object_vars($element);
            $id = $fields['id'] ?? null;
            if (!is_string($id) || !preg_match(self::ITEM_ID, $id)) {
                return ['blad' => self::refuse('bledny_identyfikator', null, $position)];
            }
            if (isset($seen[$id])) {
                return ['blad' => self::refuse('powtorzony_identyfikator', null, $position)];
            }
            $seen[$id] = true;
            $text = $this->text($fields, $position);
            if (isset($text['blad'])) {
                return $text;
            }
            $items[$position] = ['fields' => $fields, 'id' => $id, 'text' => $text['text']];
        }
        foreach ($items as $position => $item) {
            $profile = $this->profile($item['fields'], $position);
            if (isset($profile['blad'])) {
                return $profile;
            }
            $items[$position]['profile'] = $profile['profile'];
        }
        $queued = [];
        foreach ($items as $position => $item) {
            $context = self::context($item['fields'], $position);
            if (isset($context['blad'])) {
                return $context;
            }
            $meta = $item['fields']['meta'] ?? null;
            // Only these fields enter the queue: a `webhook` or `url` in the request is
            // never read, like in the gateway (SSRF).
            $queued[] = [
                'item_id'     => $item['id'],
                'text'        => $item['text'],
                'profile'     => $item['profile'],
                'meta'        => $meta instanceof \stdClass ? json_decode((string)json_encode($meta), true) : null,
                'context'     => $context['context'],
                'received_at' => $now,
                'next_at'     => $now + $this->cfg->delayS,
            ];
        }
        return ['elementy' => $queued];
    }

    /**
     * A comment's text: present, and within the character ceiling once trimmed.
     *
     * @param array<string,mixed> $comment  A batch item's fields, or the synchronous body's.
     * @param int|null            $position The item's position in the batch; null on the
     *     synchronous route, whose refusals name no `element`.
     * @return array{text:string}|array{blad:array{status:int,body:array}} The trimmed text, or
     *     the refusal.
     */
    private function text(array $comment, ?int $position): array
    {
        $text = is_string($comment['tekst'] ?? null) ? trim($comment['tekst']) : '';
        if ($text === '') {
            return ['blad' => self::refuse('brak_tekstu', null, $position)];
        }
        if (mb_strlen($text) > $this->cfg->maxChars) {
            return ['blad' => self::refuse('limit_dlugosci', $this->cfg->maxChars, $position)];
        }
        return ['text' => $text];
    }

    /**
     * A comment's profile: the one it names, or the key's first, and in either case one on
     * the B2B list and on the key's ({@see Config::allowsProfile}).
     *
     * @param array<string,mixed> $comment  A batch item's fields, or the synchronous body's.
     * @param int|null            $position The item's position, or null.
     * @return array{profile:string}|array{blad:array{status:int,body:array}}
     */
    private function profile(array $comment, ?int $position): array
    {
        $profile = $comment['profil'] ?? ($this->cfg->profiles[0] ?? '');
        if (is_string($profile) && !in_array($profile, Config::B2B_PROFILES, true)) {
            return ['blad' => self::answer(403, 'profil_niedozwolony', self::NOT_B2B_PROFILE, null, $position)];
        }
        if (!is_string($profile) || !$this->cfg->allowsProfile($profile)) {
            return ['blad' => self::refuse('profil_niedozwolony', null, $position)];
        }
        return ['profile' => $profile];
    }

    /**
     * A comment's conversation context (`kontekst`): absent, `null` or `[]` is none; else a
     * list of at most 8 entries `{rola: autor|inny, tekst}`, each text 1–500 characters once
     * trimmed. The number of entries is checked before the entries, as the contract lists it.
     *
     * @param array<string,mixed> $comment  A batch item's fields, or the synchronous body's.
     * @param int|null            $position The item's position, or null.
     * @return array{context:array<int,array{rola:string,tekst:string}>|null}|array{blad:array{status:int,body:array}}
     *     The checked entries (null when there is no context), or the refusal.
     */
    private static function context(array $comment, ?int $position): array
    {
        $raw = $comment['kontekst'] ?? null;
        if ($raw === null || $raw === []) {
            return ['context' => null];
        }
        if (!is_array($raw)) {
            return ['blad' => self::refuse('bledny_kontekst', null, $position)];
        }
        if (count($raw) > Config::MAX_CONTEXT_ENTRIES) {
            return ['blad' => self::refuse('za_duzy_kontekst', null, $position)];
        }
        $entries = [];
        foreach ($raw as $entry) {
            $fields = $entry instanceof \stdClass ? get_object_vars($entry) : null;
            $role = $fields['rola'] ?? null;
            $text = is_string($fields['tekst'] ?? null) ? trim($fields['tekst']) : '';
            if ($fields === null || !in_array($role, self::CONTEXT_ROLES, true) || $text === '') {
                return ['blad' => self::refuse('bledny_kontekst', null, $position)];
            }
            if (mb_strlen($text) > Config::MAX_CONTEXT_CHARS) {
                return ['blad' => self::refuse('za_duzy_kontekst', null, $position)];
            }
            $entries[] = ['rola' => $role, 'tekst' => $text];
        }
        return ['context' => $entries];
    }

    /**
     * The refusal {@see FORCE_HEADER} asks for, if the route can send it.
     *
     * @param string $code    The header's code.
     * @param bool   $inBatch Whether the route takes a batch. There, a forced item refusal
     *     names the position a real one could; the synchronous route names none.
     * @return array{status:int,body:array} The refusal, or `400 atrapa_nieznany_kod` for a
     *     code the route never sends.
     */
    private function forced(string $code, bool $inBatch): array
    {
        if (!in_array($code, $inBatch ? self::batchCodes() : self::syncCodes(), true)) {
            return ['status' => 400, 'body' => ['blad' => [
                'kod'       => 'atrapa_nieznany_kod',
                'komunikat' => 'Ta trasa nie wysyła kodu z nagłówka ' . self::FORCE_HEADER . '.',
            ]]];
        }
        return self::refuse($code, $this->limitFor($code), $inBatch ? self::ERRORS[$code][3] : null);
    }

    /**
     * The number a refusal's message names, when it names one.
     *
     * @param string $code The code.
     * @return int|null The limit, or null.
     */
    private function limitFor(string $code): ?int
    {
        if ($code === 'za_duzo_elementow') {
            return $this->cfg->maxItems;
        }
        return $code === 'limit_dlugosci' ? $this->cfg->maxChars : null;
    }

    /**
     * A documented refusal.
     *
     * @param string   $code     The code (a key of {@see ERRORS}).
     * @param int|null $limit    The number the message names, if it names one.
     * @param int|null $position The batch item it names in `element`, if any.
     * @return array{status:int,body:array}
     */
    private static function refuse(string $code, ?int $limit = null, ?int $position = null): array
    {
        [$status, $message, $retryS] = self::ERRORS[$code];
        if ($limit !== null) {
            $message = sprintf($message, $limit);
        }
        return self::answer($status, $code, $message, $retryS, $position);
    }

    /**
     * The gateway's error shape, `{blad: {kod, komunikat, ponow_za_s?, element?}}`.
     *
     * @return array{status:int,body:array}
     */
    private static function answer(int $status, string $code, string $message,
        ?int $retryS = null, ?int $position = null): array
    {
        $error = ['kod' => $code, 'komunikat' => $message];
        if ($retryS !== null) {
            $error['ponow_za_s'] = $retryS;
        }
        if ($position !== null) {
            $error['element'] = $position;
        }
        return ['status' => $status, 'body' => ['blad' => $error]];
    }
}
