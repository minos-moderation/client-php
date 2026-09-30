<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Mock\Config;
use Minos\Mock\Intake;

/**
 * The mock's intake answers like the real gateway: the same checks, codes and positions.
 */
final class IntakeTest extends MockTestCase
{
    private const NOW = 1727430000;

    public function testABatchIsAcceptedAndQueued(): void
    {
        $cfg = $this->config();
        $answer = $this->post($cfg, ['elementy' => [
            ['id' => 'k-1', 'tekst' => '  pierwszy  '],
            ['id' => 'k-2', 'tekst' => 'drugi', 'profil' => 'forum_teen', 'meta' => ['links' => 1]],
        ]]);

        self::assertSame(202, $answer['status']);
        self::assertSame(['przyjete' => ['k-1', 'k-2']], $answer['body']);
        $entries = $this->queue($cfg)->all();
        self::assertSame(['k-1', 'k-2'], array_column($entries, 'item_id'));
        self::assertSame('pierwszy', $entries[0]['text']);
        self::assertSame(['forum_adult', 'forum_teen'], array_column($entries, 'profile'));
    }

    public function testAnAddressInTheRequestNeverReachesTheQueue(): void
    {
        $cfg = $this->config();
        $this->post($cfg, [
            'webhook'  => 'https://attacker.example/',
            'elementy' => [['id' => 'k-1', 'tekst' => 'x', 'url' => 'http://169.254.169.254/']],
        ]);
        $entry = $this->queue($cfg)->all()[0];
        unset($entry['entry'], $entry['attempts'], $entry['deliveries']);
        self::assertSame(['item_id', 'text', 'profile', 'meta', 'received_at', 'next_at'], array_keys($entry));
        self::assertStringNotContainsString('attacker', (string)json_encode($entry));
    }

    public function testNoKeyOrAnotherKeyIs401(): void
    {
        $cfg = $this->config();
        foreach ([null, '', 'wgb2b_inny'] as $key) {
            $answer = (new Intake($cfg))->handle($key, null, '{"elementy":[]}', self::NOW);
            self::assertSame([401, 'brak_klucza'], [$answer['status'], $answer['body']['blad']['kod']]);
        }
    }

    public function testNoWebhookRefusesBeforeTheBatchIsRead(): void
    {
        $cfg = Config::fromEnv(['MINOS_MOCK_DATA_DIR' => $this->dir]);
        $answer = $this->post($cfg, ['elementy' => []]);
        self::assertSame([403, 'brak_webhooka'], [$answer['status'], $answer['body']['blad']['kod']]);
    }

    /**
     * @dataProvider refusedBatches
     */
    public function testARefusedBatchNamesItsItemAndQueuesNothing(array $body, int $status, string $code,
        ?int $element): void
    {
        $cfg = $this->config();
        $answer = $this->post($cfg, $body);

        self::assertSame($status, $answer['status']);
        self::assertSame($code, $answer['body']['blad']['kod']);
        self::assertSame($element, $answer['body']['blad']['element'] ?? null);
        self::assertSame(0, $this->queue($cfg)->length());
    }

    public function refusedBatches(): array
    {
        $ok = ['id' => 'k-0', 'tekst' => 'dobry'];
        return [
            'elementy is not a list'   => [['elementy' => ['a' => $ok]], 400, 'bledne_wejscie', null],
            'an item is not an object' => [['elementy' => [$ok, 'x']], 400, 'bledne_wejscie', 1],
            'empty batch'              => [['elementy' => []], 400, 'brak_elementow', null],
            'too many items'           => [['elementy' => array_map(static function (int $i): array {
                return ['id' => 'k-' . $i, 'tekst' => 'x'];
            }, range(1, 21))], 413, 'za_duzo_elementow', null],
            'id with a space'          => [['elementy' => [$ok, ['id' => 'k 1', 'tekst' => 'x']]], 400, 'bledny_identyfikator', 1],
            'id with a final newline'  => [['elementy' => [['id' => "k-1\n", 'tekst' => 'x']]], 400, 'bledny_identyfikator', 0],
            'repeated id'              => [['elementy' => [$ok, $ok]], 400, 'powtorzony_identyfikator', 1],
            'blank text'               => [['elementy' => [$ok, ['id' => 'k-1', 'tekst' => "  \n"]]], 400, 'brak_tekstu', 1],
            'text too long'            => [['elementy' => [['id' => 'k-1', 'tekst' => str_repeat('ż', 3001)]]], 413, 'limit_dlugosci', 0],
            'profile off the key'      => [['elementy' => [$ok, ['id' => 'k-1', 'tekst' => 'x', 'profil' => 'child_strict']]], 403, 'profil_niedozwolony', 1],
        ];
    }

    public function testTheLengthLimitCountsCharactersNotBytes(): void
    {
        $answer = $this->post($this->config(), ['elementy' => [['id' => 'k-1', 'tekst' => str_repeat('ż', 3000)]]]);
        self::assertSame(202, $answer['status']);
    }

    public function testAFullQueueIs429WithARetryHint(): void
    {
        $cfg = $this->config(['MINOS_MOCK_QUEUE_MAX' => '2']);
        self::assertSame(202, $this->post($cfg, ['elementy' => [['id' => 'a', 'tekst' => 'x']]])['status']);
        $answer = $this->post($cfg, ['elementy' => [['id' => 'b', 'tekst' => 'x'], ['id' => 'c', 'tekst' => 'x']]]);

        self::assertSame(429, $answer['status']);
        self::assertSame(['kod' => 'kolejka_pelna', 'komunikat' => $answer['body']['blad']['komunikat'],
            'ponow_za_s' => 60], $answer['body']['blad']);
        self::assertSame(1, $this->queue($cfg)->length());
    }

    public function testAnyDocumentedRefusalCanBeForced(): void
    {
        $cfg = $this->config();
        foreach (Intake::codes() as $code) {
            $answer = (new Intake($cfg))->handle($cfg->key, $code, '{}', self::NOW);
            self::assertSame($code, $answer['body']['blad']['kod'], $code);
            self::assertGreaterThanOrEqual(400, $answer['status'], $code);
            self::assertStringNotContainsString('%d', $answer['body']['blad']['komunikat'], $code);
        }
        $unknown = (new Intake($cfg))->handle($cfg->key, 'cos_innego', '{}', self::NOW);
        self::assertSame('atrapa_nieznany_kod', $unknown['body']['blad']['kod']);
    }

    public function testTheMockKnowsExactlyTheDocumentedErrorCodes(): void
    {
        $document = (string)file_get_contents(__DIR__ . '/../../docs/contract.md');
        preg_match_all('/^\| (\d{3}) \| (.+?) \|/m', $document, $rows, PREG_SET_ORDER);
        $documented = [];
        foreach ($rows as $row) {
            preg_match_all('/`([a-z_0-9]+)`/', $row[2], $codes);
            foreach ($codes[1] as $code) {
                $documented[$code] = (int)$row[1];
            }
        }
        self::assertGreaterThanOrEqual(15, count($documented), 'the error table must be found');

        $cfg = $this->config();
        $mock = [];
        foreach (Intake::codes() as $code) {
            $mock[$code] = (new Intake($cfg))->handle($cfg->key, $code, '{}', self::NOW)['status'];
        }
        ksort($documented);
        ksort($mock);
        self::assertSame($documented, $mock);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:array}
     */
    private function post(Config $cfg, array $body): array
    {
        return (new Intake($cfg))->handle($cfg->key, null, (string)json_encode($body), self::NOW);
    }
}
