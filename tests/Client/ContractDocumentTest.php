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
        foreach ($this->cases() as $name => [$secret, $header, $body, $now]) {
            self::assertSame(
                \verify_wergiliusz_signature($secret, $header, $body, $now),
                Signature::verify($secret, $header, $body, $now),
                $name
            );
        }
    }

    /**
     * `Signature::check` gives the reference verifier's answer with a reason: `OK` exactly
     * when the contract's verifier accepts, and on every case the reason its first failing
     * step names.
     */
    public function testCheckIsOkExactlyWhenTheDocumentedVerifierAccepts(): void
    {
        self::defineReferenceVerifier($this->block('reference-verifier'));
        $accepted = 0;
        foreach ($this->cases() as $name => [$secret, $header, $body, $now, $reason]) {
            $verdict = \verify_wergiliusz_signature($secret, $header, $body, $now);
            $check = Signature::check($secret, $header, $body, $now);
            self::assertSame($verdict, $check === Signature::OK, $name);
            self::assertSame($reason, $check, $name);
            $accepted += $verdict ? 1 : 0;
        }
        self::assertGreaterThan(0, $accepted, 'some case must be accepted, or the property says nothing');
    }

    /**
     * The cases both verifiers are run on, built on the documented vector: [secret, header,
     * body, now, the reason `check` gives].
     *
     * @return array<string,array{0:string,1:string,2:string,3:int,4:string}>
     */
    private function cases(): array
    {
        $v = $this->documentedVector();
        [$secret, $header, $body, $t] = [$v['secret'], $v['header'], $v['body'], $v['timestamp']];
        $mac = substr($header, strpos($header, 'v1=') + 3);
        $zeros = '000' . $t;
        return [
            'the vector'                    => [$secret, $header, $body, $t, Signature::OK],
            'five minutes behind'           => [$secret, $header, $body, $t + 300, Signature::OK],
            'five minutes ahead'            => [$secret, $header, $body, $t - 300, Signature::OK],
            'one second too late'           => [$secret, $header, $body, $t + 301, Signature::STALE],
            'one second too early'          => [$secret, $header, $body, $t - 301, Signature::STALE],
            'a body one byte longer'        => [$secret, $header, $body . "\n", $t, Signature::MISMATCH],
            'a body with a space'           => [$secret, $header, $body . ' ', $t, Signature::MISMATCH],
            'another secret'                => [$secret . 'x', $header, $body, $t, Signature::MISMATCH],
            'an empty secret'               => ['', $header, $body, $t, Signature::MISMATCH],
            'a header with whitespace'      => [$secret, ' ' . $header . "\r\n", $body, $t, Signature::OK],
            'a space after the comma'       => [$secret, 't=' . $t . ', v1=' . $mac, $body, $t, Signature::MALFORMED],
            'an upper-case MAC'             => [$secret, 't=' . $t . ',v1=' . strtoupper($mac), $body, $t, Signature::MALFORMED],
            'the fields swapped'            => [$secret, 'v1=' . $mac . ',t=' . $t, $body, $t, Signature::MALFORMED],
            'an empty header'               => [$secret, '', $body, $t, Signature::MALFORMED],
            'leading zeros, signed so'      => [$secret, 't=' . $zeros . ',v1=' . hash_hmac('sha256', $zeros . '.' . $body, $secret),
                $body, $t, Signature::OK],
            'leading zeros, signed without' => [$secret, 't=' . $zeros . ',v1=' . $mac, $body, $t, Signature::MISMATCH],
            'a zero MAC at its time'        => [$secret, 't=1,v1=' . str_repeat('0', 64), $body, 1, Signature::MISMATCH],
            'stale and wrong'               => [$secret . 'x', $header, $body, $t + 301, Signature::STALE],
        ];
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

    public function testTheDocumentedPersonalDataValuesAreTheLibrarys(): void
    {
        foreach (['rodzaj' => WebhookPayload::PERSONAL_DATA_KINDS, 'reakcja' => WebhookPayload::PERSONAL_DATA_REACTIONS]
            as $field => $constant) {
            self::assertSame(1, preg_match('/^\| `' . $field . '` \| (.+) \|$/m', $this->document, $m),
                "the {$field} row must be found in docs/contract.md");
            preg_match_all('/`([a-z_]+)`/', $m[1], $values);
            self::assertSame($constant, $values[1], $field);
        }
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
