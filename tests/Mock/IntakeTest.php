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
        self::assertSame(['item_id', 'text', 'profile', 'meta', 'context', 'received_at', 'next_at'], array_keys($entry));
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
        $with = static function ($context): array {
            return ['id' => 'k-1', 'tekst' => 'x', 'kontekst' => $context];
        };
        $entry = ['rola' => 'inny', 'tekst' => 'wcześniej'];
        return [
            'elementy is not a list'   => [['elementy' => ['a' => $ok]], 400, 'bledne_wejscie', null],
            'elementy is an object'    => [['elementy' => (object)['0' => $ok]], 400, 'bledne_wejscie', null],
            'an item is not an object' => [['elementy' => [$ok, 'x']], 400, 'bledne_wejscie', 1],
            'an item is a list'        => [['elementy' => [['k-1', 'x']]], 400, 'bledne_wejscie', 0],
            'empty batch'              => [['elementy' => []], 400, 'brak_elementow', null],
            'too many items'           => [['elementy' => array_map(static function (int $i): array {
                return ['id' => 'k-' . $i, 'tekst' => 'x'];
            }, range(1, 21))], 413, 'za_duzo_elementow', null],
            'id with a space'          => [['elementy' => [$ok, ['id' => 'k 1', 'tekst' => 'x']]], 400, 'bledny_identyfikator', 1],
            'id with a final newline'  => [['elementy' => [['id' => "k-1\n", 'tekst' => 'x']]], 400, 'bledny_identyfikator', 0],
            'repeated id'              => [['elementy' => [$ok, $ok]], 400, 'powtorzony_identyfikator', 1],
            'blank text'               => [['elementy' => [$ok, ['id' => 'k-1', 'tekst' => "  \n"]]], 400, 'brak_tekstu', 1],
            'text is not a string'     => [['elementy' => [$ok, ['id' => 'k-1', 'tekst' => ['x']]]], 400, 'brak_tekstu', 1],
            'text too long'            => [['elementy' => [['id' => 'k-1', 'tekst' => str_repeat('ż', 3001)]]], 413, 'limit_dlugosci', 0],
            'profile off the key'      => [['elementy' => [$ok, ['id' => 'k-1', 'tekst' => 'x', 'profil' => 'child_strict']]], 403, 'profil_niedozwolony', 1],
            'context is a string'      => [['elementy' => [$ok, $with('x')]], 400, 'bledny_kontekst', 1],
            'context is an object'     => [['elementy' => [$ok, $with((object)$entry)]], 400, 'bledny_kontekst', 1],
            'context is an empty object' => [['elementy' => [$ok, $with(new \stdClass())]], 400, 'bledny_kontekst', 1],
            'an entry is not an object' => [['elementy' => [$ok, $with(['x'])]], 400, 'bledny_kontekst', 1],
            'an entry is a list'       => [['elementy' => [$ok, $with([['inny', 'x']])]], 400, 'bledny_kontekst', 1],
            'an unknown role'          => [['elementy' => [$ok, $with([['rola' => 'dziecko', 'tekst' => 'x']])]], 400, 'bledny_kontekst', 1],
            'no role'                  => [['elementy' => [$ok, $with([['tekst' => 'x']])]], 400, 'bledny_kontekst', 1],
            'a blank entry'            => [['elementy' => [$ok, $with([['rola' => 'inny', 'tekst' => " \n"]])]], 400, 'bledny_kontekst', 1],
            'an entry text not a string' => [['elementy' => [$ok, $with([['rola' => 'inny', 'tekst' => 7]])]], 400, 'bledny_kontekst', 1],
            'nine entries'             => [['elementy' => [$ok, $with(array_fill(0, 9, $entry))]], 413, 'za_duzy_kontekst', 1],
            'nine malformed entries'   => [['elementy' => [$ok, $with(array_fill(0, 9, 'x'))]], 413, 'za_duzy_kontekst', 1],
            'an entry too long'        => [['elementy' => [$ok, $with([['rola' => 'inny', 'tekst' => str_repeat('ż', 501)]])]], 413, 'za_duzy_kontekst', 1],
        ];
    }

    /**
     * Each request fails every check from its step on, so only the order picks the code:
     * key → size → JSON → webhook (the mock's order before it, the contract names none) →
     * the batch (every item's shape, id, text and length) → the profiles → the contexts →
     * the queue's room.
     */
    public function testTheBatchChecksRunInTheContractsOrder(): void
    {
        $tooBig = '{"elementy":"' . str_repeat('a', 1727104);
        $badId = ['id' => 'k 1', 'tekst' => 'x'];
        $badProfile = ['id' => 'k-0', 'tekst' => 'x', 'profil' => 'child_strict', 'kontekst' => 'x'];
        $badContext = ['id' => 'k-2', 'tekst' => 'x', 'kontekst' => 'x'];
        $steps = [
            ['wgb2b_inny', false, $tooBig, 'brak_klucza', null],
            [null, false, $tooBig, 'za_duze_zadanie', null],
            [null, false, '{"elementy":', 'bledne_wejscie', null],
            [null, false, (string)json_encode(['elementy' => (object)['0' => $badId]]), 'brak_webhooka', null],
            [null, true, (string)json_encode(['elementy' => (object)['0' => $badProfile]]), 'bledne_wejscie', null],
            [null, true, (string)json_encode(['elementy' => [$badProfile, $badId]]), 'bledny_identyfikator', 1],
            [null, true, (string)json_encode(['elementy' => [$badProfile, ['id' => 'k-1', 'tekst' => str_repeat('ż', 3001)]]]), 'limit_dlugosci', 1],
            [null, true, (string)json_encode(['elementy' => [$badContext, $badProfile]]), 'profil_niedozwolony', 1],
            [null, true, (string)json_encode(['elementy' => [['id' => 'k-0', 'tekst' => 'x'], $badContext]]), 'bledny_kontekst', 1],
        ];
        foreach ($steps as $step => [$key, $webhook, $body, $code, $element]) {
            $env = ['MINOS_MOCK_QUEUE_MAX' => '0'];
            $cfg = $webhook ? $this->config($env) : Config::fromEnv($env + ['MINOS_MOCK_DATA_DIR' => $this->dir]);
            $answer = (new Intake($cfg))->handle($key ?? $cfg->key, null, $body, self::NOW);
            self::assertSame([$code, $element], [$answer['body']['blad']['kod'], $answer['body']['blad']['element'] ?? null],
                "step {$step}");
        }
        // The last step: a valid batch meets the full queue.
        $cfg = $this->config(['MINOS_MOCK_QUEUE_MAX' => '0']);
        self::assertSame('kolejka_pelna', $this->post($cfg, ['elementy' => [['id' => 'k-0', 'tekst' => 'x']]])['body']['blad']['kod']);
    }

    public function testAContextWithinItsLimitsIsQueuedWithItsComment(): void
    {
        $cfg = $this->config();
        $context = array_fill(0, 8, ['rola' => 'autor', 'tekst' => '  ' . str_repeat('ż', 500) . "\n"]);
        $answer = $this->post($cfg, ['elementy' => [
            ['id' => 'k-1', 'tekst' => 'x', 'kontekst' => $context],
            ['id' => 'k-2', 'tekst' => 'x', 'kontekst' => null],
            ['id' => 'k-3', 'tekst' => 'x', 'kontekst' => []],
            ['id' => 'k-4', 'tekst' => 'x'],
        ]]);

        self::assertSame(202, $answer['status']);
        $entries = $this->queue($cfg)->all();
        self::assertCount(8, $entries[0]['context']);
        self::assertSame(['rola' => 'autor', 'tekst' => str_repeat('ż', 500)], $entries[0]['context'][7], 'trimmed');
        self::assertSame([null, null, null], array_column(array_slice($entries, 1), 'context'), 'no context');
    }

    /**
     * A key may use only a profile on the B2B list, whatever its record lists: also as its
     * first profile when an item names none.
     */
    public function testAProfileOffTheB2bListIsRefusedEvenWhenTheKeyListsIt(): void
    {
        $cfg = $this->config(['MINOS_MOCK_PROFILES' => 'child_teen,forum_adult']);
        foreach ([['id' => 'k-1', 'tekst' => 'x'], ['id' => 'k-1', 'tekst' => 'x', 'profil' => 'child_teen']] as $item) {
            $answer = $this->post($cfg, ['elementy' => [$item]]);
            self::assertSame([403, 'profil_niedozwolony', 0],
                [$answer['status'], $answer['body']['blad']['kod'], $answer['body']['blad']['element']]);
            self::assertStringContainsString('forum_adult', $answer['body']['blad']['komunikat'], 'the message names the list');
        }
        self::assertSame(202, $this->post($cfg, ['elementy' => [['id' => 'k-1', 'tekst' => 'x', 'profil' => 'forum_adult']]])['status']);
        self::assertSame(1, $this->queue($cfg)->length());
    }

    public function testTheLengthLimitCountsCharactersNotBytes(): void
    {
        $answer = $this->post($this->config(), ['elementy' => [['id' => 'k-1', 'tekst' => str_repeat('ż', 3000)]]]);
        self::assertSame(202, $answer['status']);
    }

    public function testAFullBatchSentAsAsciiEscapedJsonIsAccepted(): void
    {
        $cfg = $this->config();
        $body = (string)json_encode(['elementy' => self::fullItems()]);
        self::assertGreaterThan(20 * (36000 + 8 * 6000), strlen($body), 'every character must be a 12-byte escaped pair');

        self::assertSame(202, (new Intake($cfg))->handle($cfg->key, null, $body, self::NOW)['status']);
        self::assertSame(20, $this->queue($cfg)->length());
    }

    /**
     * The gateway's ceiling, 20 × (3000 × 12 + 1024 + 8 × (500 × 12 + 64)) bytes for the items
     * and 16,384 for the rest: a body of 1,727,104 bytes is read, one byte more is refused
     * before it is parsed. The number is the one the contract's copy names.
     */
    public function testTheBodyCeilingIsTheGateways(): void
    {
        $cfg = $this->config();
        $ceiling = self::documentedCeiling('`POST /api/v1/b2b/oceny`');
        self::assertSame(1727104, $ceiling);
        $items = self::fullItems();
        $items[0]['meta'] = ['padding' => ''];
        $json = (string)json_encode(['elementy' => $items]);

        $over = (new Intake($cfg))->handle($cfg->key, null, self::sized($json, $ceiling + 1), self::NOW);
        self::assertSame([413, 'za_duze_zadanie'], [$over['status'], $over['body']['blad']['kod']]);
        self::assertArrayNotHasKey('element', $over['body']['blad']);
        self::assertSame(0, $this->queue($cfg)->length());

        $atCeiling = (new Intake($cfg))->handle($cfg->key, null, self::sized($json, $ceiling), self::NOW);
        self::assertSame(202, $atCeiling['status']);
    }

    /**
     * Both ceilings follow `MINOS_MOCK_MAX_ITEMS` and `MINOS_MOCK_MAX_CHARS`:
     * items × (characters × 12 + 1024 + 8 × (500 × 12 + 64)) + 16,384 bytes.
     */
    public function testTheBodyCeilingsFollowTheConfiguredLimits(): void
    {
        $cfg = $this->config(['MINOS_MOCK_MAX_ITEMS' => '2', 'MINOS_MOCK_MAX_CHARS' => '100']);
        $batch = '{"elementy":[{"id":"k-1","tekst":"x","meta":{"padding":""}}]}';
        $sync = '{"tekst":"x","meta":{"padding":""}}';
        $intake = new Intake($cfg);

        // 2 × (100 × 12 + 1024 + 48,512) + 16,384 = 117,856.
        self::assertSame(202, $intake->handle($cfg->key, null, self::sized($batch, 117856), self::NOW)['status']);
        self::assertSame('za_duze_zadanie',
            $intake->handle($cfg->key, null, self::sized($batch, 117857), self::NOW)['body']['blad']['kod']);
        // 1 × (100 × 12 + 1024 + 48,512) + 16,384 = 67,120.
        self::assertSame(200, $intake->handleSync($cfg->key, null, self::sized($sync, 67120))['status']);
        self::assertSame('za_duze_zadanie',
            $intake->handleSync($cfg->key, null, self::sized($sync, 67121))['body']['blad']['kod']);
    }

    /**
     * The queue the contract's copy names (350 comments) is the mock's default, and the B2B
     * list it names is the one the mock checks.
     */
    public function testTheQueueAndTheProfileListAreTheDocumentedOnes(): void
    {
        self::assertSame(1, preg_match('/^\| 429 \| `kolejka_pelna` \|[^|\n]*?holds (\d+) comments/m',
            self::contractSection('`POST /api/v1/b2b/oceny`'), $m), 'the kolejka_pelna row must name the queue\'s size');
        self::assertSame((int)$m[1], Config::fromEnv([])->queueMax);

        self::assertSame(1, preg_match('/only a profile on the gateway\'s B2B list: (.+?);/s',
            self::contractSection('The key'), $list), 'the B2B list must be found in docs/contract.md');
        preg_match_all('/`([a-z_]+)`/', $list[1], $profiles);
        self::assertSame(Config::B2B_PROFILES, $profiles[1]);
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

    public function testAnyRefusalOfTheBatchRouteCanBeForcedAndNoOther(): void
    {
        $cfg = $this->config();
        foreach (Intake::batchCodes() as $code) {
            $answer = (new Intake($cfg))->handle($cfg->key, $code, '{}', self::NOW);
            self::assertSame($code, $answer['body']['blad']['kod'], $code);
            self::assertGreaterThanOrEqual(400, $answer['status'], $code);
            self::assertStringNotContainsString('%d', $answer['body']['blad']['komunikat'], $code);
        }
        // The synchronous route's own codes, and anything else, are never sent here.
        foreach (['tylko_klucze_platne', 'silnik_przeciazony', 'cos_innego'] as $code) {
            $answer = (new Intake($cfg))->handle($cfg->key, $code, '{}', self::NOW);
            self::assertSame([400, 'atrapa_nieznany_kod'], [$answer['status'], $answer['body']['blad']['kod']], $code);
        }
        self::assertSame(0, $this->queue($cfg)->length());
    }

    /**
     * Two mock-only answers no contract code describes, on both routes: a `503` without a
     * `kod`, and a `503` whose body is not JSON. Neither is a refusal a client may read a
     * code from.
     */
    public function testA503WithoutACodeOrWithoutJsonCanBeForcedOnBothRoutes(): void
    {
        $cfg = $this->config();
        $intake = new Intake($cfg);
        foreach ([$intake->handle($cfg->key, '503-bez-kodu', '{}', self::NOW),
            $intake->handleSync($cfg->key, '503-bez-kodu', '{"tekst":"x"}')] as $answer) {
            self::assertSame(503, $answer['status']);
            self::assertArrayNotHasKey('kod', $answer['body']['blad']);
            self::assertArrayNotHasKey('ponow_za_s', $answer['body']['blad']);
        }
        foreach ([$intake->handle($cfg->key, '503-nie-json', '{}', self::NOW),
            $intake->handleSync($cfg->key, '503-nie-json', '{"tekst":"x"}')] as $answer) {
            self::assertSame(503, $answer['status']);
            self::assertNull(json_decode($answer['raw']));
        }
        self::assertSame([], array_intersect(Intake::MOCK_SCENARIOS, array_merge(Intake::batchCodes(), Intake::syncCodes())),
            'a mock scenario is no contract code');
        self::assertSame(0, $this->queue($cfg)->length());
    }

    public function testTheBatchRouteForcesExactlyItsDocumentedErrorCodes(): void
    {
        $document = (string)file_get_contents(__DIR__ . '/../../docs/contract.md');
        self::assertSame(1, preg_match('/^## `POST \/api\/v1\/b2b\/oceny`\n(.*?)(?=^## |\z)/ms', $document, $section),
            'the batch route\'s section must be found in docs/contract.md');
        preg_match_all('/^\| (\d{3}) \| (.+?) \|/m', $section[1], $rows, PREG_SET_ORDER);
        $documented = [];
        foreach ($rows as $row) {
            preg_match_all('/`([a-z_0-9]+)`/', $row[2], $codes);
            foreach ($codes[1] as $code) {
                $documented[$code] = (int)$row[1];
            }
        }
        self::assertGreaterThanOrEqual(19, count($documented), 'the error table must be found');

        $cfg = $this->config();
        $mock = [];
        foreach (Intake::batchCodes() as $code) {
            $mock[$code] = (new Intake($cfg))->handle($cfg->key, $code, '{}', self::NOW)['status'];
        }
        ksort($documented);
        ksort($mock);
        self::assertSame($documented, $mock);
    }

    /**
     * The default batch at its limits: 20 items of {@see fullEscapedText()}, each with a
     * {@see fullEscapedContext()}.
     *
     * @return array<int,array{id:string,tekst:string,kontekst:array}>
     */
    private static function fullItems(): array
    {
        return array_map(static function (int $i): array {
            return ['id' => 'k-' . $i, 'tekst' => self::fullEscapedText(), 'kontekst' => self::fullEscapedContext()];
        }, range(1, 20));
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
