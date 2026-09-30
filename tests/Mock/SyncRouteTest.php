<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Mock\Config;
use Minos\Mock\Intake;
use Minos\Mock\Verdicts;

/**
 * The mock's synchronous route answers like the gateway's: a paid key's single comment gets
 * its verdict in the response, the item rules are the batch's, the checks run in the
 * contract's order, and nothing is ever queued.
 */
final class SyncRouteTest extends MockTestCase
{
    public function testASafeCommentGetsTheWebhookPayloadWithoutAnId(): void
    {
        $cfg = $this->config();
        $answer = $this->post($cfg, ['tekst' => '  Świetny wpis!  ']);

        self::assertSame(200, $answer['status']);
        self::assertSame([
            'status'       => 'ocenione',
            'kwalifikacja' => 'bezpieczne',
            'kategorie'    => [],
            'wsparcie'     => false,
            'wersja'       => Verdicts::VERSION,
        ], $answer['body']);
        self::assertQueueUntouched($cfg);
    }

    public function testTheMarkersSteerTheVerdictAsOnTheBatchRoute(): void
    {
        $cfg = $this->config();
        $texts = [
            'spadaj [minos:blokuj] [minos:kategoria=nekanie] [minos:kategoria=grozba]',
            'no to jest [[głupi]] pomysł [minos:cenzuruj] [minos:kategoria=wulgaryzmy]',
            'bez maski [minos:cenzuruj]',
            'nie daję rady [minos:kategoria=samookaleczenie] [minos:bez-wersji]',
        ];
        foreach ($texts as $text) {
            $answer = $this->post($cfg, ['tekst' => $text]);
            self::assertSame(200, $answer['status'], $text);
            self::assertArrayNotHasKey('id', $answer['body'], $text);
            // The same verdict the webhook would carry, minus its id.
            $payload = Verdicts::payload('k-1', $text);
            unset($payload['id']);
            self::assertSame($payload, $answer['body'], $text);
        }
        self::assertQueueUntouched($cfg);
    }

    public function testAnUnassessedCommentIsExactlyStatusNieocenione(): void
    {
        $answer = $this->post($this->config(), ['tekst' => 'cokolwiek [minos:nieocenione] [minos:blokuj]']);
        self::assertSame([200, ['status' => 'nieocenione']], [$answer['status'], $answer['body']]);
    }

    public function testAProfileOnTheKeysListAndTheSpamSignalsAreAccepted(): void
    {
        $answer = $this->post($this->config(), [
            'tekst'  => 'dobry',
            'profil' => 'forum_teen',
            'meta'   => ['links' => 1, 'author_first_post' => true, 'e-mail' => 'x@example.org'],
        ]);
        self::assertSame(200, $answer['status']);
    }

    /**
     * @dataProvider refusedRequests
     */
    public function testARefusedRequestHasTheDocumentedCodeNoElementAndQueuesNothing(string $body, int $status,
        string $code): void
    {
        $cfg = $this->config();
        $answer = (new Intake($cfg))->handleSync($cfg->key, null, $body);

        self::assertSame([$status, $code], [$answer['status'], $answer['body']['blad']['kod']]);
        self::assertArrayNotHasKey('element', $answer['body']['blad']);
        self::assertQueueUntouched($cfg);
    }

    public function refusedRequests(): array
    {
        return [
            'not JSON'                => ['{"tekst":', 400, 'bledne_wejscie'],
            'a JSON list'             => ['[{"tekst":"x"}]', 400, 'bledne_wejscie'],
            'a JSON string'           => ['"x"', 400, 'bledne_wejscie'],
            'an empty list'           => ['[]', 400, 'bledne_wejscie'],
            'no text'                 => ['{}', 400, 'brak_tekstu'],
            'a batch instead'         => ['{"elementy":[{"id":"k-1","tekst":"x"}]}', 400, 'brak_tekstu'],
            'text is not a string'    => ['{"tekst":["x"]}', 400, 'brak_tekstu'],
            'blank text'              => ['{"tekst":"  \n\t"}', 400, 'brak_tekstu'],
            'text too long'           => [(string)json_encode(['tekst' => str_repeat('ż', 3001)]), 413, 'limit_dlugosci'],
            'profile off the key'     => ['{"tekst":"x","profil":"child_strict"}', 403, 'profil_niedozwolony'],
            'profile is not a string' => ['{"tekst":"x","profil":1}', 403, 'profil_niedozwolony'],
            'body over the ceiling'   => [(string)json_encode(['tekst' => 'x', 'meta' => str_repeat('a', 30000)]), 413, 'za_duze_zadanie'],
        ];
    }

    public function testTheLengthLimitCountsCharactersAfterTrimming(): void
    {
        $answer = $this->post($this->config(), ['tekst' => "  \n" . str_repeat('ż', 3000) . "\t "]);
        self::assertSame(200, $answer['status']);
    }

    public function testNoKeyOrAnotherKeyIs401(): void
    {
        $cfg = $this->config();
        foreach ([null, '', 'wgb2b_inny'] as $key) {
            $answer = (new Intake($cfg))->handleSync($key, null, '{"tekst":"x"}');
            self::assertSame([401, 'brak_klucza'], [$answer['status'], $answer['body']['blad']['kod']]);
        }
    }

    public function testOnlyAPaidKeyMayUseTheRoute(): void
    {
        foreach (['b2b_free', 'b2b_platny', 'B2B_PAID'] as $class) {
            $cfg = $this->config(['MINOS_MOCK_KEY_CLASS' => $class]);
            $answer = (new Intake($cfg))->handleSync($cfg->key, null, '{"tekst":"x"}');
            self::assertSame([403, 'tylko_klucze_platne'], [$answer['status'], $answer['body']['blad']['kod']], $class);
        }
        self::assertSame(Config::KEY_CLASS_PAID, $this->config()->keyClass, 'the default class is the paid one');
    }

    public function testAFreeKeyStillQueuesABatch(): void
    {
        $cfg = $this->config(['MINOS_MOCK_KEY_CLASS' => 'b2b_free']);
        $answer = (new Intake($cfg))->handle($cfg->key, null, '{"elementy":[{"id":"k-1","tekst":"x"}]}', 1727430000);
        self::assertSame(202, $answer['status']);
    }

    /**
     * Each request fails every check from its step on, so only the contract's order picks
     * the code: key and class → body size → JSON → text → profile.
     */
    public function testTheChecksRunInTheContractsOrder(): void
    {
        $tooBig = '{"tekst":"' . str_repeat('a', 40000);
        $steps = [
            ['wgb2b_inny', 'b2b_free', $tooBig, 'brak_klucza'],
            [null, 'b2b_free', $tooBig, 'tylko_klucze_platne'],
            [null, 'b2b_paid', $tooBig, 'za_duze_zadanie'],
            [null, 'b2b_paid', '{"tekst":', 'bledne_wejscie'],
            [null, 'b2b_paid', (string)json_encode(['tekst' => str_repeat('ż', 3001), 'profil' => 'x']), 'limit_dlugosci'],
            [null, 'b2b_paid', '{"tekst":" ","profil":"x"}', 'brak_tekstu'],
            [null, 'b2b_paid', '{"tekst":"x","profil":"x"}', 'profil_niedozwolony'],
        ];
        foreach ($steps as [$key, $class, $body, $code]) {
            $cfg = $this->config(['MINOS_MOCK_KEY_CLASS' => $class]);
            $answer = (new Intake($cfg))->handleSync($key ?? $cfg->key, null, $body);
            self::assertSame($code, $answer['body']['blad']['kod']);
        }
    }

    public function testAnyDocumentedRefusalCanBeForcedAfterTheKeyAndClass(): void
    {
        $cfg = $this->config();
        foreach (Intake::codes() as $code) {
            $answer = (new Intake($cfg))->handleSync($cfg->key, $code, '{"tekst":"x"}');
            self::assertSame($code, $answer['body']['blad']['kod'], $code);
            self::assertGreaterThanOrEqual(400, $answer['status'], $code);
            self::assertArrayNotHasKey('element', $answer['body']['blad'], $code);
        }
        $overloaded = (new Intake($cfg))->handleSync($cfg->key, 'silnik_przeciazony', '{"tekst":"x"}');
        self::assertSame(503, $overloaded['status']);
        self::assertIsInt($overloaded['body']['blad']['ponow_za_s']);

        $free = $this->config(['MINOS_MOCK_KEY_CLASS' => 'b2b_free']);
        self::assertSame('tylko_klucze_platne',
            (new Intake($free))->handleSync($free->key, 'limit_minutowy_klucza', '{"tekst":"x"}')['body']['blad']['kod']);
        self::assertSame('brak_klucza',
            (new Intake($cfg))->handleSync('wgb2b_inny', 'limit_minutowy_klucza', '{"tekst":"x"}')['body']['blad']['kod']);
        self::assertQueueUntouched($cfg);
    }

    public function testTheMockKnowsEveryCodeOfTheSynchronousErrorTable(): void
    {
        $document = (string)file_get_contents(__DIR__ . '/../../docs/contract.md');
        self::assertSame(1, preg_match('/^## `POST \/api\/v1\/b2b\/ocena`\n(.*?)(?=^## |\z)/ms', $document, $section),
            'the synchronous route\'s section must be found in docs/contract.md');
        preg_match_all('/^\| (\d{3}) \| (.+?) \|/m', $section[1], $rows, PREG_SET_ORDER);
        $documented = [];
        foreach ($rows as $row) {
            preg_match_all('/`([a-z_0-9]+)`/', $row[2], $codes);
            foreach ($codes[1] as $code) {
                $documented[$code] = (int)$row[1];
            }
        }
        self::assertGreaterThanOrEqual(10, count($documented), 'the error table must be found');

        $cfg = $this->config();
        foreach ($documented as $code => $status) {
            $answer = (new Intake($cfg))->handleSync($cfg->key, $code, '{"tekst":"x"}');
            self::assertSame([$status, $code], [$answer['status'], $answer['body']['blad']['kod']]);
        }
        self::assertArrayNotHasKey('brak_webhooka', $documented, 'the synchronous route needs no webhook');
    }

    /**
     * No file was created: the queue's is written only by a batch.
     */
    private static function assertQueueUntouched(Config $cfg): void
    {
        self::assertFileDoesNotExist($cfg->queuePath());
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:array}
     */
    private function post(Config $cfg, array $body): array
    {
        return (new Intake($cfg))->handleSync($cfg->key, null, (string)json_encode($body));
    }
}
