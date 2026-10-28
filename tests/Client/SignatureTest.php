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

    /**
     * The header is trimmed before it is read, so the whitespace and line ending a server
     * may leave around it change nothing; whitespace inside it is a malformed header.
     */
    public function testSurroundingWhitespaceIsTrimmedAndInnerWhitespaceIsNot(): void
    {
        $v = $this->vector;
        foreach ([' ' . $v['header'] . "\r\n", "\t" . $v['header'], $v['header'] . "\n"] as $header) {
            self::assertTrue(Signature::verify($v['secret'], $header, $v['body'], $v['timestamp']), json_encode($header));
            self::assertSame(Signature::OK, Signature::check($v['secret'], $header, $v['body'], $v['timestamp']));
        }
        $mac = substr($v['header'], strpos($v['header'], 'v1=') + 3);
        foreach (['t=' . $v['timestamp'] . ', v1=' . $mac, 't= ' . $v['timestamp'] . ',v1=' . $mac] as $header) {
            self::assertFalse(Signature::verify($v['secret'], $header, $v['body'], $v['timestamp']), $header);
            self::assertSame(Signature::MALFORMED, Signature::check($v['secret'], $header, $v['body'], $v['timestamp']));
        }
    }

    /**
     * The MAC covers `t` exactly as the header writes it: a timestamp with leading zeros
     * verifies when it was signed in that form, and not with the MAC of the plain number.
     */
    public function testATimestampWithLeadingZerosIsSignedAsWritten(): void
    {
        $v = $this->vector;
        $t = '00' . $v['timestamp'];
        $signed = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $v['body'], $v['secret']);
        self::assertTrue(Signature::verify($v['secret'], $signed, $v['body'], $v['timestamp']));

        $mac = substr($v['header'], strpos($v['header'], 'v1=') + 3);
        self::assertFalse(Signature::verify($v['secret'], 't=' . $t . ',v1=' . $mac, $v['body'], $v['timestamp']));
    }

    /**
     * On the gateway's vector, each way a delivery can fail has its own reason.
     */
    public function testCheckNamesTheReason(): void
    {
        $v = $this->vector;
        self::assertSame(Signature::OK, Signature::check($v['secret'], $v['header'], $v['body'], $v['timestamp']));
        self::assertSame(Signature::STALE, Signature::check($v['secret'], $v['header'], $v['body'], $v['timestamp'] + 301));
        self::assertSame(Signature::MISMATCH, Signature::check($v['secret'], $v['header'], $v['body'] . ' ', $v['timestamp']));
        self::assertSame(Signature::MISMATCH, Signature::check($v['secret'] . 'x', $v['header'], $v['body'], $v['timestamp']));
        self::assertSame(Signature::MALFORMED, Signature::check($v['secret'], 'v1=' . $v['header'], $v['body'], $v['timestamp']));
        self::assertSame(4, count(array_unique([Signature::OK, Signature::MALFORMED, Signature::STALE, Signature::MISMATCH])),
            'four distinct reasons');
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
            self::assertSame(Signature::MALFORMED, Signature::check($v['secret'], $header, $v['body'], $v['timestamp']), $header);
        }
    }
}
