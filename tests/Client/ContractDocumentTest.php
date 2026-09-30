<?php

declare(strict_types=1);

namespace Minos\Tests\Client;

use Minos\Client\Signature;
use Minos\Client\WebhookPayload;
use PHPUnit\Framework\TestCase;

/**
 * `docs/contract.md` is executed, not only read: its reference verifier runs against its
 * test vector, and the code and data built on it must say the same thing.
 */
final class ContractDocumentTest extends TestCase
{
    /** @var string */
    private $document;

    protected function setUp(): void
    {
        $this->document = (string)file_get_contents(__DIR__ . '/../../docs/contract.md');
    }

    public function testTheDocumentedVerifierAcceptsTheDocumentedVector(): void
    {
        self::defineReferenceVerifier($this->block('reference-verifier'));
        $v = $this->documentedVector();

        self::assertTrue(\verify_wergiliusz_signature($v['secret'], $v['header'], $v['body'], $v['timestamp']));
        self::assertFalse(\verify_wergiliusz_signature($v['secret'], $v['header'], $v['body'] . ' ', $v['timestamp']));
    }

    public function testTheLibraryAgreesWithTheDocumentedVerifier(): void
    {
        self::defineReferenceVerifier($this->block('reference-verifier'));
        $v = $this->documentedVector();
        $cases = [
            [$v['header'], $v['body'], $v['timestamp']],
            [$v['header'], $v['body'], $v['timestamp'] + 301],
            [$v['header'], $v['body'] . "\n", $v['timestamp']],
            [' ' . $v['header'] . "\n", $v['body'], $v['timestamp']],
            ['t=1,v1=' . str_repeat('0', 64), $v['body'], 1],
        ];
        foreach ($cases as [$header, $body, $now]) {
            self::assertSame(
                \verify_wergiliusz_signature($v['secret'], $header, $body, $now),
                Signature::verify($v['secret'], $header, $body, $now),
                $header
            );
        }
    }

    public function testTheDocumentedVectorIsTheMachineReadableOne(): void
    {
        $file = json_decode((string)file_get_contents(__DIR__ . '/../../vectors/signature.json'), true);
        $documented = $this->documentedVector();
        foreach (['secret', 'timestamp', 'body', 'header'] as $field) {
            self::assertSame($file[$field], $documented[$field], $field);
        }
    }

    public function testTheDocumentedCategoriesAreTheLibrarys(): void
    {
        self::assertSame(1, preg_match('/The possible labels are (.+?)\. A category/', $this->document, $m),
            'the category list must be found in docs/contract.md');
        preg_match_all('/`([a-z_]+)`/', $m[1], $labels);
        self::assertSame(WebhookPayload::CATEGORIES, $labels[1]);
    }

    /**
     * The code or text between `<!-- <name>:start -->` and `<!-- <name>:end -->`, without
     * its fence.
     */
    private function block(string $name): string
    {
        $pattern = '/<!-- ' . preg_quote($name, '/') . ':start -->\s*```[a-z]*\n(.*?)```\s*<!-- '
            . preg_quote($name, '/') . ':end -->/s';
        self::assertSame(1, preg_match_all($pattern, $this->document, $m),
            "exactly one {$name} block must be in docs/contract.md");
        return $m[1][0];
    }

    /**
     * @return array{secret:string,timestamp:int,body:string,header:string}
     */
    private function documentedVector(): array
    {
        $vector = [];
        foreach (explode("\n", trim($this->block('test-vector'))) as $line) {
            [$field, $value] = explode(': ', $line, 2);
            $vector[$field] = $value;
        }
        $vector['timestamp'] = (int)$vector['timestamp'];
        return $vector;
    }

    private static function defineReferenceVerifier(string $code): void
    {
        if (!function_exists('verify_wergiliusz_signature')) {
            eval($code);
        }
        self::assertTrue(function_exists('verify_wergiliusz_signature'));
    }
}
