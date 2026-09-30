<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * `POST /api/v1/b2b/oceny` of the mock: checks a batch the way the real gateway does and
 * queues it.
 *
 * The checks, their order and the error codes follow the gateway's B2B route
 * (`docs/contract.md` → Errors): the key, the body's size, the JSON, the webhook, the
 * batch item by item (whole or not at all, with the item's position in `blad.element`),
 * the profiles, the queue's room. The key's rate limits are not simulated
 * — ask for any refusal with the mock-only header {@see FORCE_HEADER} instead.
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
     * Every refusal of the contract: code → [HTTP status, message, `ponow_za_s`, `element`].
     * The messages are the real gateway's.
     */
    private const ERRORS = [
        'brak_klucza'              => [401, 'Ta trasa wymaga klucza B2B.', null, null],
        'nie_ta_powierzchnia'      => [403, 'Ta trasa jest dla kluczy B2B.', null, null],
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
        'nie_znaleziono'           => [404, 'Nie ma tu nic.', null, null],
    ];

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
     * The codes {@see FORCE_HEADER} accepts — every refusal of the contract.
     *
     * @return array<int,string> The codes.
     */
    public static function codes(): array
    {
        return array_keys(self::ERRORS);
    }

    /**
     * Handles one request.
     *
     * @param string|null $key    The `X-Gateway-Key` header, or null.
     * @param string|null $force  The {@see FORCE_HEADER} header, or null.
     * @param string      $body   The raw body.
     * @param int         $now    Unix seconds.
     * @return array{status:int,body:array} `202 {przyjete}` or `{blad}`.
     */
    public function handle(?string $key, ?string $force, string $body, int $now): array
    {
        if ($key === null || !hash_equals($this->cfg->key, $key)) {
            return self::refuse('brak_klucza');
        }
        if ($force !== null && $force !== '') {
            return isset(self::ERRORS[$force])
                ? self::refuse($force, $this->limitFor($force))
                : ['status' => 400, 'body' => ['blad' => [
                    'kod'       => 'atrapa_nieznany_kod',
                    'komunikat' => 'Atrapa nie zna kodu z nagłówka ' . self::FORCE_HEADER . '.',
                ]]];
        }
        if (strlen($body) > $this->bodyCeiling()) {
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
     * Validates the batch and builds the queue items.
     *
     * @param mixed $batch The `elementy` field.
     * @param int   $now   Unix seconds.
     * @return array{elementy:array<int,array<string,mixed>>}|array{blad:array{status:int,body:array}}
     */
    private function items($batch, int $now): array
    {
        if (!is_array($batch) || ($batch !== [] && array_keys($batch) !== range(0, count($batch) - 1))) {
            return ['blad' => self::refuse('bledne_wejscie', null, null)];
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
            $text = is_string($element['tekst'] ?? null) ? trim($element['tekst']) : '';
            if ($text === '') {
                return ['blad' => self::refuse('brak_tekstu', null, $position)];
            }
            if (mb_strlen($text) > $this->cfg->maxChars) {
                return ['blad' => self::refuse('limit_dlugosci', $this->cfg->maxChars, $position)];
            }
            $profile = $element['profil'] ?? ($this->cfg->profiles[0] ?? '');
            if (!is_string($profile) || !in_array($profile, $this->cfg->profiles, true)) {
                return ['blad' => self::refuse('profil_niedozwolony', null, $position)];
            }
            // Only these fields enter the queue: a `webhook` or `url` in the request is
            // never read, like in the real gateway (SSRF).
            $items[] = [
                'item_id'     => $id,
                'text'        => $text,
                'profile'     => $profile,
                'meta'        => is_array($element['meta'] ?? null) ? $element['meta'] : null,
                'received_at' => $now,
                'next_at'     => $now + $this->cfg->delayS,
            ];
        }
        return ['elementy' => $items];
    }

    /**
     * The body ceiling of the real gateway: every item at its character ceiling (4 bytes a
     * character), 1 KiB for its other fields, 16 KiB for the rest.
     *
     * @return int Bytes.
     */
    private function bodyCeiling(): int
    {
        return $this->cfg->maxItems * ($this->cfg->maxChars * 4 + 1024) + 16384;
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
     * @param int|null $position The item, overriding the default position of forced errors.
     * @return array{status:int,body:array}
     */
    private static function refuse(string $code, ?int $limit = null, ?int $position = null): array
    {
        [$status, $message, $retryS, $defaultPosition] = self::ERRORS[$code];
        if ($limit !== null) {
            $message = sprintf($message, $limit);
        }
        return self::answer($status, $code, $message, $retryS,
            $position !== null ? $position : $defaultPosition);
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
