<?php

declare(strict_types=1);

namespace Minos\Tests\Client;

use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The webhook signature against the contract's test vector (`vectors/signature.json`).
 */
final class SignatureTest extends TestCase
{
    /** @var array{secret:string,timestamp:int,body:string,header:string} */
    private $vector;

    protected function setUp(): void
    {
        $this->vector = json_decode(
            (string)file_get_contents(__DIR__ . '/../../vectors/signature.json'), true);
    }

    public function testTheVectorVerifies(): void
    {
        $v = $this->vector;
        self::assertTrue(Signature::verify($v['secret'], $v['header'], $v['body'], $v['timestamp']));
    }

    public function testSigningReproducesTheVectorsHeader(): void
    {
        $v = $this->vector;
        self::assertSame($v['header'], Signature::sign($v['secret'], $v['body'], $v['timestamp']));
    }

    public function testOneChangedByteOfTheBodyFails(): void
    {
        $v = $this->vector;
        $tampered = str_replace('"wsparcie":false', '"wsparcie":true', $v['body']);
        self::assertNotSame($v['body'], $tampered);
        self::assertFalse(Signature::verify($v['secret'], $v['header'], $tampered, $v['timestamp']));
    }

    public function testTheSameJsonReEncodedIsNotTheSameBody(): void
    {
        // The signature covers the exact bytes: a plugin that verifies a re-encoded body
        // (escaped Unicode here) refuses a genuine delivery.
        $v = $this->vector;
        $reEncoded = (string)json_encode(json_decode($v['body'], true));
        self::assertNotSame($v['body'], $reEncoded);
        self::assertFalse(Signature::verify($v['secret'], $v['header'], $reEncoded, $v['timestamp']));
    }

    public function testAnotherSecretFails(): void
    {
        $v = $this->vector;
        self::assertFalse(Signature::verify($v['secret'] . 'x', $v['header'], $v['body'], $v['timestamp']));
    }

    public function testTheTimestampMayBeFiveMinutesOffAndNoMore(): void
    {
        $v = $this->vector;
        foreach ([-300, 300] as $offset) {
            self::assertTrue(Signature::verify($v['secret'], $v['header'], $v['body'], $v['timestamp'] + $offset));
        }
        foreach ([-301, 301] as $offset) {
            self::assertFalse(Signature::verify($v['secret'], $v['header'], $v['body'], $v['timestamp'] + $offset));
        }
    }

    public function testAMalformedHeaderFails(): void
    {
        $v = $this->vector;
        $mac = substr($v['header'], strpos($v['header'], 'v1=') + 3);
        $malformed = [
            '',
            'v1=' . $mac . ',t=' . $v['timestamp'],
            't=' . $v['timestamp'] . ',v1=' . strtoupper($mac),
            't=' . $v['timestamp'] . ',v1=' . substr($mac, 1),
            't=' . $v['timestamp'] . ',v1=' . $mac . ',v0=00',
            't=-' . $v['timestamp'] . ',v1=' . $mac,
        ];
        foreach ($malformed as $header) {
            self::assertFalse(Signature::verify($v['secret'], $header, $v['body'], $v['timestamp']), $header);
        }
    }
}
