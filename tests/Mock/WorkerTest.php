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
        self::assertSame('uwaga ██████ [minos:cenzuruj] [minos:kategoria=nekanie]', $read['ocenzurowany']);
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

    private function accept(Config $cfg, string $id, string $text): void
    {
        $answer = (new Intake($cfg))->handle($cfg->key, null,
            (string)json_encode(['elementy' => [['id' => $id, 'tekst' => $text]]]), self::NOW);
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
