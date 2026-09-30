<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Client\Signature;
use Minos\Client\WebhookPayload;
use Minos\Mock\Config;

/**
 * The mock as a developer runs it: the built-in server takes a batch over HTTP, the worker
 * CLI delivers to a real HTTP receiver, and the delivery verifies with the client library;
 * the synchronous route answers a verdict over HTTP and queues nothing.
 */
final class EndToEndTest extends MockTestCase
{
    /** A censored comment with whitespace around it, which the gateway trims. */
    private const CENSORED = "  no to jest [[żałosny]] pomysł [minos:cenzuruj]\n";

    /** @var array<int,array{0:resource,1:int}> Started servers and their ports. */
    private $servers = [];

    /** @var array<int,string> Variables this test put into the environment. */
    private $env = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$server, $port]) {
            proc_terminate($server);
            proc_close($server);
            // A server that outlives its test is a leak (CI's runner had to kill them).
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            self::assertFalse($socket, "the server on port {$port} is still running");
        }
        foreach ($this->env as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    public function testABatchPostedOverHttpComesBackSignedToTheWebhook(): void
    {
        mkdir($this->dir, 0700, true);
        $received = $this->dir . '/received.jsonl';
        $receiverPort = $this->serve(__DIR__ . '/fixtures', 'receiver.php', ['RECEIVER_OUT' => $received]);
        $env = [
            'MINOS_MOCK_WEBHOOK_URL' => "http://127.0.0.1:{$receiverPort}/webhook",
            'MINOS_MOCK_DATA_DIR'    => $this->dir,
            'MINOS_MOCK_DELAY_S'     => '0',
        ];
        $mockPort = $this->serve(__DIR__ . '/../../mock-gateway/public', null, $env);

        [$status, $answer] = $this->post("http://127.0.0.1:{$mockPort}/api/v1/b2b/oceny", [
            'X-Gateway-Key: ' . Config::DEFAULT_KEY,
            'Content-Type: application/json',
        ], ['elementy' => [
            ['id' => 'k-1', 'tekst' => 'Świetny wpis!'],
            ['id' => 'k-2', 'tekst' => 'spadaj [minos:blokuj] [minos:kategoria=nekanie]'],
            ['id' => 'k-3', 'tekst' => self::CENSORED],
        ]]);
        self::assertSame(202, $status);
        self::assertSame(['przyjete' => ['k-1', 'k-2', 'k-3']], $answer);

        exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../../mock-gateway/bin/worker.php') . ' --once 2>&1',
            $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        $deliveries = array_map(static function (string $line): array {
            return json_decode($line, true);
        }, file($received, FILE_IGNORE_NEW_LINES) ?: []);
        self::assertCount(3, $deliveries);
        $verdicts = [];
        $masked = [];
        foreach ($deliveries as $delivery) {
            self::assertSame('application/json; charset=utf-8', $delivery['typ']);
            self::assertTrue(Signature::verify(Config::DEFAULT_SECRET, (string)$delivery['podpis'],
                $delivery['body'], time()));
            $read = WebhookPayload::parse($delivery['body']);
            $verdicts[$read['id']] = $read['kwalifikacja'];
            $masked[$read['id']] = $read['ocenzurowany'];
        }
        ksort($verdicts);
        self::assertSame(['k-1' => 'bezpieczne', 'k-2' => 'zablokowane', 'k-3' => 'ocenzurowane'], $verdicts);
        self::assertSame(mb_strlen(trim(self::CENSORED)), mb_strlen((string)$masked['k-3']),
            'the masked text is as long as the trimmed text that was sent');
    }

    public function testOneCommentPostedOverHttpGetsItsVerdictInTheResponse(): void
    {
        $port = $this->serve(__DIR__ . '/../../mock-gateway/public', null, ['MINOS_MOCK_DATA_DIR' => $this->dir]);
        $headers = ['X-Gateway-Key: ' . Config::DEFAULT_KEY, 'Content-Type: application/json'];

        foreach (['/api/v1/b2b/ocena', '/api/b2b/ocena'] as $path) {
            [$status, $answer] = $this->post("http://127.0.0.1:{$port}{$path}", $headers,
                ['tekst' => 'spadaj [minos:blokuj] [minos:kategoria=nekanie]']);
            self::assertSame(200, $status, $path);
            self::assertSame(['status', 'kwalifikacja', 'kategorie', 'wsparcie', 'wersja'], array_keys($answer), $path);
            self::assertSame(['zablokowane', ['nekanie']], [$answer['kwalifikacja'], $answer['kategorie']], $path);
        }

        [$status, $answer] = $this->post("http://127.0.0.1:{$port}/api/v1/b2b/ocena", $headers,
            ['tekst' => self::CENSORED]);
        self::assertSame([200, 'ocenzurowane'], [$status, $answer['kwalifikacja']]);
        self::assertSame(mb_strlen(trim(self::CENSORED)), mb_strlen((string)$answer['ocenzurowany']),
            'the masked text is as long as the trimmed text that was sent');

        [$status, $answer] = $this->post("http://127.0.0.1:{$port}/api/v1/b2b/ocena", $headers,
            ['tekst' => 'x [minos:nieocenione]']);
        self::assertSame([200, ['status' => 'nieocenione']], [$status, $answer]);

        [$status, $answer] = $this->post("http://127.0.0.1:{$port}/api/v1/b2b/ocena",
            array_merge($headers, ['X-Minos-Mock-Error: silnik_przeciazony']), ['tekst' => 'x']);
        self::assertSame([503, 'silnik_przeciazony'], [$status, $answer['blad']['kod']]);

        // A synchronous request is never queued: the mock's data directory stays empty.
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testAFreeKeyIsRefusedOnTheSynchronousRouteOverHttp(): void
    {
        $port = $this->serve(__DIR__ . '/../../mock-gateway/public', null,
            ['MINOS_MOCK_DATA_DIR' => $this->dir, 'MINOS_MOCK_KEY_CLASS' => 'b2b_free']);
        [$status, $answer] = $this->post("http://127.0.0.1:{$port}/api/v1/b2b/ocena",
            ['X-Gateway-Key: ' . Config::DEFAULT_KEY], ['tekst' => 'x']);
        self::assertSame([403, 'tylko_klucze_platne'], [$status, $answer['blad']['kod']]);
    }

    public function testAnotherPathIs404(): void
    {
        $port = $this->serve(__DIR__ . '/../../mock-gateway/public', null, ['MINOS_MOCK_DATA_DIR' => $this->dir]);
        [$status, $answer] = $this->post("http://127.0.0.1:{$port}/api/v1/ask", [], []);
        self::assertSame([404, 'nie_znaleziono'], [$status, $answer['blad']['kod']]);
    }

    /**
     * Starts PHP's built-in server and waits until it answers. The variables are also put
     * into this process's environment, for the worker CLI the test runs afterwards.
     *
     * @param array<string,string> $env
     * @return int The port.
     */
    private function serve(string $docroot, ?string $router, array $env): int
    {
        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $this->env[] = $name;
        }
        for ($try = 0; $try < 5; $try++) {
            $port = random_int(20000, 40000);
            $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot];
            if ($router !== null) {
                $command[] = $docroot . '/' . $router;
            }
            // An array, not a string: a string runs through `sh -c`, and terminating the
            // shell leaves PHP's server running.
            $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, null, getenv() + $env);
            self::assertIsResource($server);
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->servers[] = [$server, $port];
                    return $port;
                }
                usleep(100000);
            }
            proc_terminate($server);
            proc_close($server);
        }
        self::fail('the built-in server did not start');
    }

    /**
     * @param array<int,string>   $headers
     * @param array<string,mixed> $body
     * @return array{0:int,1:array}
     */
    private function post(string $url, array $headers, array $body): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY          => '',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $answer = (string)curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, (array)json_decode($answer, true)];
    }
}
