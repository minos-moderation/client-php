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
        $entry = ['rola' => 'inny', 'tekst' => 'wcześniej'];
        $with = static function ($context): string {
            return (string)json_encode(['tekst' => 'x', 'kontekst' => $context]);
        };
        return [
            'not JSON'                => ['{"tekst":', 400, 'bledne_wejscie'],
            'a JSON list'             => ['[{"tekst":"x"}]', 400, 'bledne_wejscie'],
            'a JSON string'           => ['"x"', 400, 'bledne_wejscie'],
            'an empty list'           => ['[]', 400, 'bledne_wejscie'],
            'no text'                 => ['{}', 400, 'brak_tekstu'],
            'a batch instead'         => ['{"elementy":[{"id":"k-1","tekst":"x"}]}', 400, 'brak_tekstu'],
            'text is a list'          => ['{"tekst":["x"]}', 400, 'bledne_wejscie'],
            'text is a number'        => ['{"tekst":7}', 400, 'bledne_wejscie'],
            'text is null'            => ['{"tekst":null}', 400, 'bledne_wejscie'],
            'text is an object'       => ['{"tekst":{"a":"x"},"profil":"child_strict"}', 400, 'bledne_wejscie'],
            'blank text'              => ['{"tekst":"  \n\t"}', 400, 'brak_tekstu'],
            'text too long'           => [(string)json_encode(['tekst' => str_repeat('ż', 3001)]), 413, 'limit_dlugosci'],
            'profile off the key'     => ['{"tekst":"x","profil":"child_strict"}', 403, 'profil_niedozwolony'],
            'profile is not a string' => ['{"tekst":"x","profil":1}', 403, 'profil_niedozwolony'],
            'context is a string'     => [$with('x'), 400, 'bledny_kontekst'],
            'context is an object'    => [$with((object)$entry), 400, 'bledny_kontekst'],
            'an entry is not an object' => [$with([7]), 400, 'bledny_kontekst'],
            'an unknown role'         => [$with([['rola' => 'Autor', 'tekst' => 'x']]), 400, 'bledny_kontekst'],
            'a blank entry'           => [$with([['rola' => 'autor', 'tekst' => '']]), 400, 'bledny_kontekst'],
            'nine entries'            => [$with(array_fill(0, 9, $entry)), 413, 'za_duzy_kontekst'],
            'an entry too long'       => [$with([['rola' => 'autor', 'tekst' => str_repeat('ż', 501)]]), 413, 'za_duzy_kontekst'],
            'body over the ceiling'   => [(string)json_encode(['tekst' => 'x', 'meta' => str_repeat('a', 110000)]), 413, 'za_duze_zadanie'],
        ];
    }

    public function testTheLengthLimitCountsCharactersAfterTrimming(): void
    {
        $answer = $this->post($this->config(), ['tekst' => "  \n" . str_repeat('ż', 3000) . "\t "]);
        self::assertSame(200, $answer['status']);
    }

    public function testAFullCommentSentAsAsciiEscapedJsonIsAccepted(): void
    {
        $body = (string)json_encode(['tekst' => self::fullEscapedText()]);
        self::assertSame(36012, strlen($body), 'every character must be a 12-byte escaped pair');
        $withContext = (string)json_encode(['tekst' => self::fullEscapedText(), 'kontekst' => self::fullEscapedContext()]);
        self::assertGreaterThan(36000 + 8 * 6000, strlen($withContext));

        $cfg = $this->config();
        self::assertSame(200, (new Intake($cfg))->handleSync($cfg->key, null, $body)['status']);
        self::assertSame(200, (new Intake($cfg))->handleSync($cfg->key, null, $withContext)['status']);
    }

    /**
     * The gateway's ceiling, 3000 × 12 + 1024 + 8 × (500 × 12 + 64) bytes for the comment and
     * its context and 16,384 for the rest: a body of 101,920 bytes is read, one byte more is
     * refused before it is parsed. The number is the one the contract's copy names.
     */
    public function testTheBodyCeilingIsTheGateways(): void
    {
        $cfg = $this->config();
        $ceiling = self::documentedCeiling('`POST /api/v1/b2b/ocena`');
        self::assertSame(101920, $ceiling);
        $json = (string)json_encode(['tekst' => self::fullEscapedText(), 'kontekst' => self::fullEscapedContext(),
            'meta' => ['padding' => '']]);

        $atCeiling = (new Intake($cfg))->handleSync($cfg->key, null, self::sized($json, $ceiling));
        self::assertSame(200, $atCeiling['status']);

        $over = (new Intake($cfg))->handleSync($cfg->key, null, self::sized($json, $ceiling + 1));
        self::assertSame([413, 'za_duze_zadanie'], [$over['status'], $over['body']['blad']['kod']]);
        self::assertQueueUntouched($cfg);
    }

    /**
     * Closed, as the gateway keeps it in production and as the mock starts, the route is
     * absent: any known key gets `404 nie_znaleziono`, whatever its class, the body or a
     * forced code; only a missing or unknown key is refused first, with 401.
     */
    public function testTheClosedRouteAnswers404ToTheKeyAfterThe401(): void
    {
        self::assertFalse(Config::fromEnv([])->syncOpen, 'the route is closed by default');
        foreach (['b2b_paid', 'b2b_free'] as $class) {
            $cfg = Config::fromEnv(['MINOS_MOCK_DATA_DIR' => $this->dir, 'MINOS_MOCK_KEY_CLASS' => $class]);
            foreach ([[null, '{"tekst":"x"}'], [null, '{"tekst":'], ['silnik_przeciazony', '{"tekst":"x"}'],
                [null, (string)json_encode(['tekst' => 'x', 'meta' => str_repeat('a', 110000)])]] as [$force, $body]) {
                $answer = (new Intake($cfg))->handleSync($cfg->key, $force, $body);
                self::assertSame([404, 'nie_znaleziono'], [$answer['status'], $answer['body']['blad']['kod']], $class);
            }
            foreach ([null, '', 'wgb2b_inny'] as $key) {
                $answer = (new Intake($cfg))->handleSync($key, null, '{"tekst":"x"}');
                self::assertSame([401, 'brak_klucza'], [$answer['status'], $answer['body']['blad']['kod']]);
            }
            self::assertQueueUntouched($cfg);
        }
    }

    public function testAContextWithADatumMarkerGetsTheSectionAndNoContextNone(): void
    {
        $cfg = $this->config();
        $text = 'dzwoń [minos:dane-osobowe=telefon]';
        $with = $this->post($cfg, ['tekst' => $text, 'kontekst' => [['rola' => 'inny', 'tekst' => 'podaj numer']]]);
        self::assertSame(['rodzaj' => 'telefon', 'reakcja' => 'ukryj'], $with['body']['dane_osobowe']);

        foreach ([['tekst' => $text], ['tekst' => $text, 'kontekst' => []], ['tekst' => $text, 'kontekst' => null]] as $body) {
            $without = $this->post($cfg, $body);
            self::assertSame(200, $without['status']);
            self::assertArrayNotHasKey('dane_osobowe', $without['body']);
        }
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
     * the code: key → the route's switch → class → body size → JSON → text → profile →
     * context.
     */
    public function testTheChecksRunInTheContractsOrder(): void
    {
        $tooBig = '{"tekst":"' . str_repeat('a', 110000);
        $steps = [
            ['wgb2b_inny', '0', 'b2b_free', $tooBig, 'brak_klucza'],
            [null, '0', 'b2b_free', $tooBig, 'nie_znaleziono'],
            [null, '1', 'b2b_free', $tooBig, 'tylko_klucze_platne'],
            [null, '1', 'b2b_paid', $tooBig, 'za_duze_zadanie'],
            [null, '1', 'b2b_paid', '{"tekst":', 'bledne_wejscie'],
            [null, '1', 'b2b_paid', (string)json_encode(['tekst' => str_repeat('ż', 3001), 'profil' => 'x', 'kontekst' => 'x']), 'limit_dlugosci'],
            [null, '1', 'b2b_paid', '{"tekst":" ","profil":"x","kontekst":"x"}', 'brak_tekstu'],
            [null, '1', 'b2b_paid', '{"tekst":"x","profil":"x","kontekst":"x"}', 'profil_niedozwolony'],
            [null, '1', 'b2b_paid', '{"tekst":"x","profil":"forum_teen","kontekst":"x"}', 'bledny_kontekst'],
        ];
        foreach ($steps as [$key, $open, $class, $body, $code]) {
            $cfg = $this->config(['MINOS_MOCK_KEY_CLASS' => $class, 'MINOS_MOCK_SYNC' => $open]);
            $answer = (new Intake($cfg))->handleSync($key ?? $cfg->key, null, $body);
            self::assertSame($code, $answer['body']['blad']['kod']);
        }
    }

    public function testAnyRefusalOfTheRouteCanBeForcedAfterTheKeyAndClassAndNoOther(): void
    {
        $cfg = $this->config();
        foreach (Intake::syncCodes() as $code) {
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

        // A queue's, a webhook's or a batch's refusal is never sent by this route.
        foreach (['kolejka_pelna', 'brak_webhooka', 'kolejka_niedostepna', 'brak_elementow', 'bledny_identyfikator',
            'powtorzony_identyfikator', 'za_duzo_elementow', 'cos_innego'] as $code) {
            $answer = (new Intake($cfg))->handleSync($cfg->key, $code, '{"tekst":"x"}');
            self::assertSame([400, 'atrapa_nieznany_kod'], [$answer['status'], $answer['body']['blad']['kod']], $code);
        }
        self::assertQueueUntouched($cfg);
    }

    public function testTheRouteForcesExactlyItsDocumentedErrorCodes(): void
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
        self::assertGreaterThanOrEqual(14, count($documented), 'the error table must be found');

        $cfg = $this->config();
        $mock = [];
        foreach (Intake::syncCodes() as $code) {
            $mock[$code] = (new Intake($cfg))->handleSync($cfg->key, $code, '{"tekst":"x"}')['status'];
        }
        ksort($documented);
        ksort($mock);
        self::assertSame($documented, $mock);
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
