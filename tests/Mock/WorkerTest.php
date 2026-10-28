<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Client\Signature;
use Minos\Client\WebhookPayload;
use Minos\Mock\Config;
use Minos\Mock\Intake;
use Minos\Mock\Worker;

/**
 * The mock's worker delivers the way a plugin must expect: signed, retried, at least once,
 * and gone with the TTL.
 */
final class WorkerTest extends MockTestCase
{
    private const NOW = 1727430000;

    /** @var array<int,array{url:string,headers:array<int,string>,body:string}> */
    private $sent = [];

    /** @var array<int,int> Statuses the fake webhook answers with, in turn (then 204). */
    private $answers = [];

    public function testADeliveryIsSignedAndReadsAsAVerdict(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', 'uwaga [[głupku]] [minos:cenzuruj] [minos:kategoria=nekanie]');

        $report = $this->worker($cfg)->runOnce(self::NOW);

        self::assertSame([['id' => 'k-1', 'wynik' => Worker::DELIVERED, 'status' => 204]], $report);
        self::assertCount(1, $this->sent);
        [$delivery] = $this->sent;
        self::assertSame($cfg->webhookUrl, $delivery['url']);
        self::assertContains('Content-Type: application/json; charset=utf-8', $delivery['headers']);
        self::assertTrue(Signature::verify($cfg->webhookSecret, $this->signature($delivery), $delivery['body'], self::NOW));
        $read = WebhookPayload::parse($delivery['body']);
        self::assertSame(['k-1', 'ocenzurowane', ['nekanie']], [$read['id'], $read['kwalifikacja'], $read['kategorie']]);
        self::assertSame('uwaga [[██████]] [minos:cenzuruj] [minos:kategoria=nekanie]', $read['ocenzurowany']);
        self::assertSame(0, $this->queue($cfg)->length(), 'a delivered entry is gone');
    }

    public function testNothingIsDeliveredBeforeTheDelay(): void
    {
        $cfg = $this->config(['MINOS_MOCK_DELAY_S' => '5']);
        $this->accept($cfg, 'k-1', 'x');

        self::assertSame([], $this->worker($cfg)->runOnce(self::NOW + 4));
        self::assertSame(Worker::DELIVERED, $this->worker($cfg)->runOnce(self::NOW + 5)[0]['wynik']);
    }

    public function testAFailedDeliveryIsRetriedWithADoublingPauseUntilTheTtl(): void
    {
        $cfg = $this->config(['MINOS_MOCK_TTL_S' => '60', 'MINOS_MOCK_BACKOFF_S' => '5']);
        $this->accept($cfg, 'k-1', 'x');
        $this->answers = [500, 0, 503, 502];
        $worker = $this->worker($cfg);

        $outcomes = [];
        for ($t = self::NOW; $t < self::NOW + 60; $t++) {
            foreach ($worker->runOnce($t) as $line) {
                $outcomes[] = [$t - self::NOW, $line['wynik'], $line['status']];
            }
        }
        // 5 s, 10 s, 20 s — the fourth pause (40 s) would end after the TTL: given up.
        self::assertSame([
            [0, Worker::RETRY, 500],
            [5, Worker::RETRY, 0],
            [15, Worker::RETRY, 503],
            [35, Worker::GAVE_UP, 502],
        ], $outcomes);
        self::assertSame(0, $this->queue($cfg)->length());
    }

    public function testEveryAttemptIsSignedAnew(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', 'x');
        $this->answers = [500];
        $worker = $this->worker($cfg);
        $worker->runOnce(self::NOW);
        $worker->runOnce(self::NOW + 5);

        self::assertCount(2, $this->sent);
        self::assertNotSame($this->signature($this->sent[0]), $this->signature($this->sent[1]));
        self::assertTrue(Signature::verify($cfg->webhookSecret, $this->signature($this->sent[1]),
            $this->sent[1]['body'], self::NOW + 5));
    }

    /**
     * 80 entries, a receiver that takes 5 s per delivery and checks each signature when the
     * delivery ends: one pass lasts 400 s, longer than the 300 s window, yet no delivery is
     * stale, because each is signed when it is sent, not when the pass started.
     */
    public function testEveryDeliveryOfALongPassIsSignedWhenItIsSent(): void
    {
        $cfg = $this->config();
        $items = array_map(static function (int $i): array {
            return ['id' => 'k-' . $i, 'tekst' => 'x'];
        }, range(1, 80));
        foreach (array_chunk($items, 20) as $batch) {
            $answer = (new Intake($cfg))->handle($cfg->key, null, (string)json_encode(['elementy' => $batch]), self::NOW);
            self::assertSame(202, $answer['status']);
        }
        $clock = 0.0;
        $reasons = [];
        $worker = new Worker($cfg, null, function (string $url, array $headers, string $body) use (&$clock, &$reasons, $cfg): int {
            $clock += 5.0;
            $reasons[] = Signature::check($cfg->webhookSecret, $this->signature(['headers' => $headers]), $body,
                self::NOW + (int)$clock);
            return 204;
        }, static function () use (&$clock): float {
            return $clock;
        });

        $report = $worker->runOnce(self::NOW);

        self::assertCount(80, $report);
        self::assertSame(['ok' => 80], array_count_values($reasons), 'no delivery may be refused');
        self::assertSame(400.0, $clock, 'the pass took longer than the signature window');
    }

    public function testNieczytelneIsSignedButNoPayload(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', 'x [minos:nieczytelne] [minos:blokuj]');
        self::assertSame(Worker::DELIVERED, $this->worker($cfg)->runOnce(self::NOW)[0]['wynik']);

        [$delivery] = $this->sent;
        self::assertSame(Signature::OK, Signature::check($cfg->webhookSecret, $this->signature($delivery),
            $delivery['body'], self::NOW));
        self::assertNull(json_decode($delivery['body']));
        self::assertNull(WebhookPayload::parse($delivery['body']));
    }

    public function testDwaRazyDeliversTheSameVerdictTwice(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', '[minos:dwa-razy] [minos:blokuj]');
        $worker = $this->worker($cfg);
        $worker->runOnce(self::NOW);
        $worker->runOnce(self::NOW + 1);
        $worker->runOnce(self::NOW + 2);

        self::assertCount(2, $this->sent);
        self::assertSame($this->sent[0]['body'], $this->sent[1]['body']);
        self::assertSame(0, $this->queue($cfg)->length());
    }

    public function testZlyPodpisAndStaryPodpisFailVerification(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', '[minos:zly-podpis]');
        $this->accept($cfg, 'k-2', '[minos:stary-podpis]');
        $this->worker($cfg)->runOnce(self::NOW);

        self::assertCount(2, $this->sent);
        foreach ($this->sent as $delivery) {
            self::assertFalse(Signature::verify($cfg->webhookSecret, $this->signature($delivery),
                $delivery['body'], self::NOW));
        }
        // The old one is a genuine signature, only ten minutes stale.
        self::assertTrue(Signature::verify($cfg->webhookSecret, $this->signature($this->sent[1]),
            $this->sent[1]['body'], self::NOW, 600));
    }

    public function testCiszaIsNeverDeliveredAndDisappearsWithItsTtl(): void
    {
        $cfg = $this->config(['MINOS_MOCK_TTL_S' => '60']);
        $this->accept($cfg, 'k-1', '[minos:cisza]');
        $worker = $this->worker($cfg);

        self::assertSame([], $worker->runOnce(self::NOW + 59));
        self::assertSame([['id' => 'k-1', 'wynik' => Worker::EXPIRED, 'status' => 0]], $worker->runOnce(self::NOW + 60));
        self::assertSame([], $this->sent);
        self::assertSame(0, $this->queue($cfg)->length());
    }

    public function testAnEntryPastItsTtlIsDroppedWithoutADelivery(): void
    {
        // The worker was down for the whole TTL: no webhook at all.
        $cfg = $this->config(['MINOS_MOCK_TTL_S' => '60']);
        $this->accept($cfg, 'k-1', 'x');

        self::assertSame(Worker::EXPIRED, $this->worker($cfg)->runOnce(self::NOW + 60)[0]['wynik']);
        self::assertSame([], $this->sent);
    }

    /**
     * One broken entry (here a text that is not a string, as no intake writes it) is
     * reported and dropped; the entries behind it are delivered in the same pass.
     */
    public function testABrokenEntryNeverHoldsBackTheOthers(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', 'x');
        $this->queue($cfg)->append([['item_id' => 'k-2', 'text' => ['nie napis'], 'profile' => 'forum_adult',
            'meta' => null, 'context' => null, 'received_at' => self::NOW, 'next_at' => self::NOW]]);
        $this->accept($cfg, 'k-3', 'x [minos:kategoria]');

        $report = $this->worker($cfg)->runOnce(self::NOW);

        self::assertSame([
            ['id' => 'k-1', 'wynik' => Worker::DELIVERED, 'status' => 204],
            ['id' => 'k-2', 'wynik' => Worker::FAILED, 'status' => 0],
            ['id' => 'k-3', 'wynik' => Worker::DELIVERED, 'status' => 204],
        ], $report);
        self::assertSame([], WebhookPayload::parse($this->sent[1]['body'])['kategorie']);
        self::assertSame(0, $this->queue($cfg)->length(), 'the broken entry is gone too');
    }

    /**
     * A comment whose profile the key no longer allows when the worker reaches it (the key's
     * list changed after it was accepted) is never assessed: `nieocenione`.
     */
    public function testACommentWhoseProfileIsNoLongerAllowedIsNieocenione(): void
    {
        $this->accept($this->config(), 'k-1', 'x [minos:blokuj]', 'forum_teen');
        $narrowed = $this->config(['MINOS_MOCK_PROFILES' => 'forum_adult']);

        $this->worker($narrowed)->runOnce(self::NOW);

        self::assertCount(1, $this->sent);
        self::assertSame('{"id":"k-1","status":"nieocenione"}', $this->sent[0]['body']);
    }

    public function testAQueuedContextReachesTheVerdict(): void
    {
        $cfg = $this->config();
        $this->accept($cfg, 'k-1', 'dzwoń [minos:dane-osobowe=telefon]', null, [['rola' => 'inny', 'tekst' => 'podaj numer']]);
        $this->accept($cfg, 'k-2', 'dzwoń [minos:dane-osobowe=telefon]');

        $this->worker($cfg)->runOnce(self::NOW);

        $read = array_map(static function (array $delivery): array {
            return WebhookPayload::parse($delivery['body']);
        }, $this->sent);
        self::assertSame(['rodzaj' => 'telefon', 'reakcja' => 'ukryj'], $read[0]['dane_osobowe']);
        self::assertSame([], $read[1]['dane_osobowe'], 'no context, no section');
    }

    /**
     * @param array<int,array{rola:string,tekst:string}>|null $context
     */
    private function accept(Config $cfg, string $id, string $text, ?string $profile = null, ?array $context = null): void
    {
        $item = ['id' => $id, 'tekst' => $text];
        if ($profile !== null) {
            $item['profil'] = $profile;
        }
        if ($context !== null) {
            $item['kontekst'] = $context;
        }
        $answer = (new Intake($cfg))->handle($cfg->key, null, (string)json_encode(['elementy' => [$item]]), self::NOW);
        self::assertSame(202, $answer['status']);
    }

    private function worker(Config $cfg): Worker
    {
        return new Worker($cfg, null, function (string $url, array $headers, string $body): int {
            $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            return $this->answers === [] ? 204 : array_shift($this->answers);
        });
    }

    /**
     * @param array{headers:array<int,string>} $delivery
     */
    private function signature(array $delivery): string
    {
        foreach ($delivery['headers'] as $line) {
            if (strpos($line, Signature::HEADER . ': ') === 0) {
                return substr($line, strlen(Signature::HEADER) + 2);
            }
        }
        self::fail('no signature header');
    }
}
