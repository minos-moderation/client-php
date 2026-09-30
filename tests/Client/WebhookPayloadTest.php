<?php

declare(strict_types=1);

namespace Minos\Tests\Client;

use Minos\Client\WebhookPayload;
use PHPUnit\Framework\TestCase;

/**
 * Reading a delivery: what is a verdict, and what must never pass for one.
 */
final class WebhookPayloadTest extends TestCase
{
    public function testTheVectorsBodyReadsAsItsVerdict(): void
    {
        $vector = json_decode((string)file_get_contents(__DIR__ . '/../../vectors/signature.json'), true);
        self::assertSame([
            'id'           => 'k-1',
            'status'       => 'ocenione',
            'kwalifikacja' => 'ocenzurowane',
            'kategorie'    => ['wulgaryzmy'],
            'ocenzurowany' => 'no to jest ███████ pomysł',
            'wsparcie'     => false,
            'wersja'       => '3f0c9a41d2b7e8c5',
        ], WebhookPayload::parse($vector['body']));
    }

    public function testNieocenioneCarriesNoVerdict(): void
    {
        $read = WebhookPayload::parse('{"id":"k-2","status":"nieocenione"}');
        self::assertSame('nieocenione', $read['status']);
        self::assertNull($read['kwalifikacja']);
        self::assertSame([], $read['kategorie']);
        self::assertFalse($read['wsparcie']);
    }

    public function testAnUnknownStatusOrQualificationIsNoVerdict(): void
    {
        // The gateway's own rule for an engine action it does not know: nobody knows what
        // was decided, so nothing is guessed — `bezpieczne` would fail open.
        foreach ([
            '{"id":"k","status":"ocenione","kwalifikacja":"przepuszczone","kategorie":[]}',
            '{"id":"k","status":"ocenione","kategorie":[]}',
            '{"id":"k","status":"nowy","kwalifikacja":"zablokowane"}',
            '{"id":"k","kwalifikacja":"bezpieczne"}',
        ] as $body) {
            $read = WebhookPayload::parse($body);
            self::assertSame('nieocenione', $read['status'], $body);
            self::assertNull($read['kwalifikacja'], $body);
        }
    }

    public function testTheMaskedTextIsReadOnlyWithOcenzurowane(): void
    {
        $read = WebhookPayload::parse(
            '{"id":"k","status":"ocenione","kwalifikacja":"zablokowane","kategorie":[],"ocenzurowany":"x"}');
        self::assertNull($read['ocenzurowany']);
    }

    public function testAnUnknownCategoryIsKeptAndRepeatsAreNot(): void
    {
        $read = WebhookPayload::parse('{"id":"k","status":"ocenione","kwalifikacja":"zablokowane",'
            . '"kategorie":["grozba","nowa_kategoria","grozba",7,""]}');
        self::assertSame(['grozba', 'nowa_kategoria'], $read['kategorie']);
    }

    public function testWsparcieIsTrueOnlyWhenTrue(): void
    {
        foreach (['true' => true, '"true"' => false, '1' => false, 'null' => false] as $json => $expected) {
            $read = WebhookPayload::parse('{"id":"k","status":"ocenione","kwalifikacja":"bezpieczne",'
                . '"kategorie":["samookaleczenie"],"wsparcie":' . $json . '}');
            self::assertSame($expected, $read['wsparcie'], (string)$json);
        }
    }

    public function testAMalformedVersionReadsAsNone(): void
    {
        foreach (['"3F0C9A41D2B7E8C5"', '"3f0c"', '123', 'null'] as $json) {
            $read = WebhookPayload::parse('{"id":"k","status":"ocenione","kwalifikacja":"bezpieczne",'
                . '"kategorie":[],"wersja":' . $json . '}');
            self::assertNull($read['wersja'], $json);
        }
    }

    public function testABodyWithoutAUsableIdIsNotAPayload(): void
    {
        foreach (['', 'nie json', '[]', '"k"', '{"status":"ocenione"}', '{"id":""}',
            '{"id":"k 1"}', "{\"id\":\"k-1\\n\"}", '{"id":' . json_encode(str_repeat('a', 65)) . '}'] as $body) {
            self::assertNull(WebhookPayload::parse($body), $body);
        }
    }
}
