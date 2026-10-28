<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Mock\Config;
use Minos\Mock\Queue;
use PHPUnit\Framework\TestCase;

/**
 * A mock configuration with its own queue directory, removed after each test.
 */
abstract class MockTestCase extends TestCase
{
    /** @var string */
    protected $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/minos-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * A configuration with a webhook, no delivery delay and the synchronous route open.
     *
     * @param array<string,string> $env Variables over the test defaults.
     */
    protected function config(array $env = []): Config
    {
        return Config::fromEnv($env + [
            'MINOS_MOCK_WEBHOOK_URL' => 'https://forum.example/wergiliusz/webhook',
            'MINOS_MOCK_DATA_DIR'    => $this->dir,
            'MINOS_MOCK_DELAY_S'     => '0',
            'MINOS_MOCK_SYNC'        => '1',
        ]);
    }

    protected function queue(Config $cfg): Queue
    {
        return new Queue($cfg->queuePath());
    }

    /**
     * A JSON body grown to exactly `$bytes` bytes by filling its one empty `padding` field
     * (a `meta` field the gateway drops) with `a`.
     *
     * @param string $json  A JSON body holding `"padding":""` once.
     * @param int    $bytes The size wanted.
     * @return string The body, `$bytes` long.
     */
    protected static function sized(string $json, int $bytes): string
    {
        $parts = explode('"padding":""', $json);
        self::assertCount(2, $parts, 'the body must hold one empty padding field');
        self::assertGreaterThanOrEqual(strlen($json), $bytes, 'the body must not be over the size already');
        $body = $parts[0] . '"padding":"' . str_repeat('a', $bytes - strlen($json)) . '"' . $parts[1];
        self::assertSame($bytes, strlen($body));
        return $body;
    }

    /**
     * A comment at the default character limit in which every character lies outside the
     * BMP, so `json_encode` (without `JSON_UNESCAPED_UNICODE`) writes each one as a 12-byte
     * escaped surrogate pair.
     *
     * @return string 3000 characters, 12,000 bytes of UTF-8.
     */
    protected static function fullEscapedText(): string
    {
        return str_repeat("\u{1F600}", 3000);
    }

    /**
     * A conversation context at its limits: 8 entries of 500 characters outside the BMP,
     * each written by `json_encode` as a 12-byte escaped surrogate pair.
     *
     * @return array<int,array{rola:string,tekst:string}>
     */
    protected static function fullEscapedContext(): array
    {
        return array_map(static function (int $i): array {
            return ['rola' => $i % 2 === 0 ? 'inny' : 'autor', 'tekst' => str_repeat("\u{1F600}", 500)];
        }, range(0, 7));
    }

    /**
     * The section of `docs/contract.md` that starts with `## <heading>`, up to the next one.
     *
     * @param string $heading The heading, without `## `.
     * @return string The section.
     */
    protected static function contractSection(string $heading): string
    {
        $document = (string)file_get_contents(__DIR__ . '/../../docs/contract.md');
        self::assertSame(1, preg_match('/^## ' . preg_quote($heading, '/') . '\n(.*?)(?=^## |\z)/ms', $document, $m),
            "the section {$heading} must be found in docs/contract.md");
        return $m[1];
    }

    /**
     * The number of bytes the contract gives one route's `za_duze_zadanie` ceiling.
     *
     * @param string $heading The route's section heading.
     * @return int Bytes.
     */
    protected static function documentedCeiling(string $heading): int
    {
        self::assertSame(1, preg_match('/^\| 413 \| `za_duze_zadanie` \|[^|\n]*?([0-9][0-9,]*) bytes/m',
            self::contractSection($heading), $m), 'the za_duze_zadanie row must name its ceiling');
        return (int)str_replace(',', '', $m[1]);
    }
}
