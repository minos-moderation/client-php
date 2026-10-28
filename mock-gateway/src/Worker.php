<?php

declare(strict_types=1);

namespace Minos\Mock;

use Minos\Client\Signature;

/**
 * The mock's delivery worker: takes due entries, builds their verdicts and delivers them
 * to the webhook, signed like the real gateway signs.
 *
 * What it keeps from the real worker, because a plugin must be built for it:
 * - a delivery is a `POST` with `Content-Type: application/json; charset=utf-8` and
 *   `X-Wergiliusz-Podpis`; any 2xx counts as delivered and the answer is not read;
 * - a failed delivery is retried with a doubling pause (from `MINOS_MOCK_BACKOFF_S`, at
 *   most two minutes) until the entry's TTL — then the entry and its verdict are gone;
 * - every attempt is signed anew, with its own timestamp;
 * - an entry nobody delivers disappears with its TTL, with no webhook at all;
 * - a comment whose profile the key no longer allows (`MINOS_MOCK_PROFILES` changed after
 *   it was accepted) gets `nieocenione`;
 * - one entry that fails never stops the others: it is reported {@see FAILED} and dropped.
 *
 * What it does NOT keep: the real worker delivers only over `https` to public addresses on
 * the key's host list. The mock posts to whatever `MINOS_MOCK_WEBHOOK_URL` names —
 * `http://localhost` included — because it runs next to a plugin in development.
 */
final class Worker
{
    /** Outcome: the webhook answered 2xx and the entry is gone. */
    public const DELIVERED = 'dostarczony';

    /** Outcome: the webhook failed; another attempt is scheduled. */
    public const RETRY = 'ponowienie';

    /** Outcome: the webhook failed and the TTL leaves no room for another attempt. */
    public const GAVE_UP = 'porzucony';

    /** Outcome: the entry reached its TTL undelivered (`[minos:cisza]`, or a stopped worker). */
    public const EXPIRED = 'wygasl';

    /** Outcome: the entry could not be processed at all (a broken entry); it was dropped. */
    public const FAILED = 'blad';

    /** @var Config */
    private $cfg;

    /** @var Queue */
    private $queue;

    /** @var callable(string, array<int,string>, string): int */
    private $transport;

    /**
     * @param Config        $cfg       The configuration.
     * @param Queue|null    $queue     The queue; the configured file by default.
     * @param callable|null $transport `fn(string $url, array $headers, string $body): int` —
     *     the HTTP status, or 0 when there was no answer. cURL by default.
     */
    public function __construct(Config $cfg, ?Queue $queue = null, ?callable $transport = null)
    {
        $this->cfg = $cfg;
        $this->queue = $queue ?? new Queue($cfg->queuePath());
        $this->transport = $transport ?? [new CurlTransport($cfg->webhookTimeoutS), 'post'];
    }

    /**
     * Processes every entry that is due.
     *
     * @param int $now Unix seconds.
     * @return array<int,array{id:string,wynik:string,status:int}> One line per entry touched.
     */
    public function runOnce(int $now): array
    {
        $report = [];
        foreach ($this->queue->all() as $entry) {
            try {
                $line = $this->process($entry, $now);
            } catch (\Throwable $e) {
                // A broken entry must not hold back the ones behind it until their TTL.
                $this->queue->remove((string)($entry['entry'] ?? ''));
                $line = ['id' => is_string($entry['item_id'] ?? null) ? $entry['item_id'] : '?',
                    'wynik' => self::FAILED, 'status' => 0];
            }
            if ($line !== null) {
                $report[] = $line;
            }
        }
        return $report;
    }

    /**
     * One entry of a pass: dropped at its TTL, skipped until it is due, else delivered.
     *
     * @param array<string,mixed> $entry The entry.
     * @param int                 $now   Unix seconds.
     * @return array{id:string,wynik:string,status:int}|null The report line; null when the
     *     entry was not due.
     */
    private function process(array $entry, int $now): ?array
    {
        $expiresAt = (int)$entry['received_at'] + $this->cfg->ttlS;
        if ($now >= $expiresAt) {
            $this->queue->remove($entry['entry']);
            return ['id' => $entry['item_id'], 'wynik' => self::EXPIRED, 'status' => 0];
        }
        $flags = Verdicts::deliveryFlags($entry['text']);
        if ((int)$entry['next_at'] > $now || in_array('cisza', $flags, true)) {
            return null;
        }
        return $this->deliver($entry, $flags, $now, $expiresAt);
    }

    /**
     * One delivery attempt of one entry.
     *
     * @param array<string,mixed> $entry     The entry.
     * @param array<int,string>   $flags     Its delivery flags.
     * @param int                 $now       Unix seconds.
     * @param int                 $expiresAt The end of its TTL.
     * @return array{id:string,wynik:string,status:int}
     */
    private function deliver(array $entry, array $flags, int $now, int $expiresAt): array
    {
        // The gateway never sends a comment the key may no longer use to its engine.
        $payload = is_string($entry['profile'] ?? null) && $this->cfg->allowsProfile($entry['profile'])
            ? Verdicts::payload($entry['item_id'], $entry['text'], ($entry['context'] ?? null) !== null)
            : ['id' => $entry['item_id'], 'status' => 'nieocenione'];
        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = in_array('zly-podpis', $flags, true)
            ? 'nie-ten-sekret-' . $this->cfg->webhookSecret
            : $this->cfg->webhookSecret;
        $signedAt = in_array('stary-podpis', $flags, true) ? $now - 600 : $now;
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            Signature::HEADER . ': ' . Signature::sign($secret, $body, $signedAt),
        ];

        $status = (int)call_user_func($this->transport, (string)$this->cfg->webhookUrl, $headers, $body);
        if ($status >= 200 && $status < 300) {
            $deliveries = (int)$entry['deliveries'] + 1;
            if (in_array('dwa-razy', $flags, true) && $deliveries < 2) {
                $this->queue->update($entry['entry'], ['deliveries' => $deliveries, 'next_at' => $now]);
            } else {
                $this->queue->remove($entry['entry']);
            }
            return ['id' => $entry['item_id'], 'wynik' => self::DELIVERED, 'status' => $status];
        }

        $attempts = (int)$entry['attempts'] + 1;
        $pause = min(Config::MAX_BACKOFF_S, $this->cfg->backoffS * (2 ** ($attempts - 1)));
        if ($now + $pause >= $expiresAt) {
            $this->queue->remove($entry['entry']);
            return ['id' => $entry['item_id'], 'wynik' => self::GAVE_UP, 'status' => $status];
        }
        $this->queue->update($entry['entry'], ['attempts' => $attempts, 'next_at' => $now + $pause]);
        return ['id' => $entry['item_id'], 'wynik' => self::RETRY, 'status' => $status];
    }
}
