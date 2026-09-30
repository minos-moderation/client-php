<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * The mock's two B2B routes: `POST /api/v1/b2b/oceny` ({@see handle}) checks a batch the way
 * the real gateway does and queues it; `POST /api/v1/b2b/ocena` ({@see handleSync}) checks one
 * comment and answers its verdict at once.
 *
 * The checks, their order and the error codes follow the gateway's routes
 * (`docs/contract.md` → each route's Errors). The batch: the key, the body's size, the JSON,
 * the webhook, the batch item by item (whole or not at all, with the item's position in
 * `blad.element`), the profiles, the queue's room. The synchronous route: the key and its
 * class, the body's size, the JSON, then the rules every batch item meets ({@see comment}),
 * without `id` and without `element`; nothing is queued. The key's rate limits are not
 * simulated — ask for any refusal of the route with the mock-only header
 * {@see FORCE_HEADER} instead.
 */
final class Intake
{
    /**
     * Mock-only request header: answer with this documented error code instead of
     * assessing. It does not exist in the real gateway.
     */
    public const FORCE_HEADER = 'X-Minos-Mock-Error';

    /** An item id: 1–64 characters, no spaces, no content. */
    private const ITEM_ID = '/^[A-Za-z0-9._:-]{1,64}\z/';

    /** Seconds after which a client refused for a full queue may try again. */
    private const FULL_RETRY_S = 60;

    /**
     * Every refusal of the contract: code → [HTTP status, message, `ponow_za_s`, `element`
     * of a forced refusal on the batch route]. The messages are the real gateway's; a
     * client reads `kod`, never `komunikat`.
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
        'za_duzo_elementow'        => [413, 'Jedno żądanie przyjmuje najwyżej %d komentarzy.', null, null],
        'limit_dlugosci'           => [413, 'Komentarz jest dłuższy niż %d znaków.', null, 0],
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
        if (strlen($body) > $this->bodyCeiling($this->cfg->maxItems)) {
            return self::refuse('za_duze_zadanie');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return self::answer(400, 'bledne_wejscie', 'Nie rozumiem tego żądania.');
        }
        if ($this->cfg->webhookUrl === null) {
            return self::refuse('brak_webhooka');
        }

        $parsed = $this->items($decoded['elementy'] ?? null, $now);
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
        if (!$this->cfg->isPaidKey()) {
            return self::refuse('tylko_klucze_platne');
        }
        if ($force !== null && $force !== '') {
            return $this->forced($force, false);
        }
        if (strlen($body) > $this->bodyCeiling(1)) {
            return self::refuse('za_duze_zadanie');
        }
        if (!json_decode($body) instanceof \stdClass) {
            return self::answer(400, 'bledne_wejscie', 'Nie rozumiem tego żądania.');
        }
        $decoded = (array)json_decode($body, true);
        // A `tekst` that is there but is not a string is a malformed request, not a missing
        // text (a batch item still answers `brak_tekstu`, as its contract says).
        if (array_key_exists('tekst', $decoded) && !is_string($decoded['tekst'])) {
            return self::answer(400, 'bledne_wejscie', 'Pole `tekst` musi być napisem.');
        }
        $comment = $this->comment($decoded, null);
        if (isset($comment['blad'])) {
            return $comment['blad'];
        }
        return ['status' => 200, 'body' => Verdicts::verdict($comment['text'])];
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
     * Validates the batch and builds the queue items.
     *
     * @param mixed $batch The `elementy` field.
     * @param int   $now   Unix seconds.
     * @return array{elementy:array<int,array<string,mixed>>}|array{blad:array{status:int,body:array}}
     */
    private function items($batch, int $now): array
    {
        if (!is_array($batch) || ($batch !== [] && array_keys($batch) !== range(0, count($batch) - 1))) {
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
            if (!is_array($element)) {
                return ['blad' => self::answer(400, 'bledne_wejscie', 'Każdy element musi być obiektem.', null, $position)];
            }
            $id = $element['id'] ?? null;
            if (!is_string($id) || !preg_match(self::ITEM_ID, $id)) {
                return ['blad' => self::refuse('bledny_identyfikator', null, $position)];
            }
            if (isset($seen[$id])) {
                return ['blad' => self::refuse('powtorzony_identyfikator', null, $position)];
            }
            $seen[$id] = true;
            $comment = $this->comment($element, $position);
            if (isset($comment['blad'])) {
                return $comment;
            }
            // Only these fields enter the queue: a `webhook` or `url` in the request is
            // never read, like in the real gateway (SSRF).
            $items[] = [
                'item_id'     => $id,
                'text'        => $comment['text'],
                'profile'     => $comment['profile'],
                'meta'        => is_array($element['meta'] ?? null) ? $element['meta'] : null,
                'received_at' => $now,
                'next_at'     => $now + $this->cfg->delayS,
            ];
        }
        return ['elementy' => $items];
    }

    /**
     * The rules every comment meets on both routes, in the gateway's order: its text, its
     * length, its profile.
     *
     * @param array<mixed> $comment  A batch item, or the synchronous route's body.
     * @param int|null     $position The item's position in the batch; null on the synchronous
     *     route, whose refusals name no `element`.
     * @return array{text:string,profile:string}|array{blad:array{status:int,body:array}}
     *     The trimmed text and the profile, or the refusal.
     */
    private function comment(array $comment, ?int $position): array
    {
        $text = is_string($comment['tekst'] ?? null) ? trim($comment['tekst']) : '';
        if ($text === '') {
            return ['blad' => self::refuse('brak_tekstu', null, $position)];
        }
        if (mb_strlen($text) > $this->cfg->maxChars) {
            return ['blad' => self::refuse('limit_dlugosci', $this->cfg->maxChars, $position)];
        }
        $profile = $comment['profil'] ?? ($this->cfg->profiles[0] ?? '');
        if (!is_string($profile) || !in_array($profile, $this->cfg->profiles, true)) {
            return ['blad' => self::refuse('profil_niedozwolony', null, $position)];
        }
        return ['text' => $text, 'profile' => $profile];
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
     * The body ceiling of the real gateway: every item at its character ceiling (4 bytes a
     * character), 1 KiB for its other fields, 16 KiB for the rest.
     *
     * @param int $items The items the route takes at most (1 on the synchronous route).
     * @return int Bytes.
     */
    private function bodyCeiling(int $items): int
    {
        return $items * ($this->cfg->maxChars * 4 + 1024) + 16384;
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
