<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * Settings of the mock gateway, read from `MINOS_MOCK_*` environment variables.
 *
 * The defaults are the real gateway's (20 items, 3000 characters, a 900 s TTL, a queue of
 * 500), so a plugin that works against the mock meets the same limits in production. The
 * mock has ONE key and ONE webhook, both set here — the real gateway keeps them in its key
 * store, one record per client, with its class.
 */
final class Config
{
    /** @var string The only B2B key the mock accepts (`X-Gateway-Key`). */
    public $key;

    /**
     * @var string The key's class. Only {@see KEY_CLASS_PAID} may use the synchronous route;
     *     `b2b_free`, or any other value, gets `403 tylko_klucze_platne` there.
     */
    public $keyClass;

    /** @var array<int,string> Profiles the key may ask for; the first is the default. */
    public $profiles;

    /** @var string|null Where verdicts go; null makes every batch `403 brak_webhooka`. */
    public $webhookUrl;

    /** @var string The secret deliveries are signed with. */
    public $webhookSecret;

    /** @var int Items one request may carry. */
    public $maxItems;

    /** @var int Characters one item may have. */
    public $maxChars;

    /** @var int Entries the queue holds at most; above it `429 kolejka_pelna`. */
    public $queueMax;

    /** @var int Seconds an entry lives; after that it disappears, delivered or not. */
    public $ttlS;

    /** @var int Seconds between accepting an item and its first delivery attempt. */
    public $delayS;

    /** @var int The first retry pause, in seconds; it doubles up to {@see MAX_BACKOFF_S}. */
    public $backoffS;

    /** @var int Timeout of one delivery, in seconds. */
    public $webhookTimeoutS;

    /** @var string Directory of the queue file. */
    public $dataDir;

    /** The longest pause between two delivery attempts (the real gateway's 2 min). */
    public const MAX_BACKOFF_S = 120;

    /** The class of a paid key, the only one the synchronous route accepts. */
    public const KEY_CLASS_PAID = 'b2b_paid';

    /** The default key: recognisably a mock, shaped like a real one (`wgb2b_…`). */
    public const DEFAULT_KEY = 'wgb2b_atrapa_minos_0000000000000000';

    /** The default webhook secret — for local work only, like everything here. */
    public const DEFAULT_SECRET = 'atrapa-minos-sekret-webhooka-tylko-lokalnie';

    /**
     * Reads the configuration.
     *
     * @param array<string,string>|null $env Variables to read instead of the process
     *     environment (tests).
     * @return self The configuration.
     */
    public static function fromEnv(?array $env = null): self
    {
        $get = static function (string $name, string $default) use ($env): string {
            $value = $env !== null ? ($env[$name] ?? false) : getenv($name);
            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };
        $int = static function (string $name, int $default) use ($get): int {
            $value = $get($name, (string)$default);
            return ctype_digit($value) ? (int)$value : $default;
        };

        $cfg = new self();
        $cfg->key = $get('MINOS_MOCK_KEY', self::DEFAULT_KEY);
        $cfg->keyClass = $get('MINOS_MOCK_KEY_CLASS', self::KEY_CLASS_PAID);
        $cfg->profiles = array_values(array_filter(array_map('trim',
            explode(',', $get('MINOS_MOCK_PROFILES', 'forum_adult,forum_teen'))), 'strlen'));
        $url = $get('MINOS_MOCK_WEBHOOK_URL', '');
        $cfg->webhookUrl = $url === '' ? null : $url;
        $cfg->webhookSecret = $get('MINOS_MOCK_WEBHOOK_SECRET', self::DEFAULT_SECRET);
        $cfg->maxItems = $int('MINOS_MOCK_MAX_ITEMS', 20);
        $cfg->maxChars = $int('MINOS_MOCK_MAX_CHARS', 3000);
        $cfg->queueMax = $int('MINOS_MOCK_QUEUE_MAX', 500);
        $cfg->ttlS = $int('MINOS_MOCK_TTL_S', 900);
        $cfg->delayS = $int('MINOS_MOCK_DELAY_S', 2);
        $cfg->backoffS = max(1, $int('MINOS_MOCK_BACKOFF_S', 5));
        $cfg->webhookTimeoutS = max(1, $int('MINOS_MOCK_WEBHOOK_TIMEOUT_S', 5));
        $cfg->dataDir = $get('MINOS_MOCK_DATA_DIR',
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'minos-mock');
        return $cfg;
    }

    /**
     * Whether the key is a paid one. An unknown class is not: a typo in
     * `MINOS_MOCK_KEY_CLASS` shows up as a refusal, not as a quietly paid key.
     *
     * @return bool True for {@see KEY_CLASS_PAID} alone.
     */
    public function isPaidKey(): bool
    {
        return $this->keyClass === self::KEY_CLASS_PAID;
    }

    /**
     * The queue file.
     *
     * @return string Its path.
     */
    public function queuePath(): string
    {
        return $this->dataDir . DIRECTORY_SEPARATOR . 'queue.json';
    }
}
