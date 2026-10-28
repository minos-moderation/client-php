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
            'dane_osobowe' => [],
        ], WebhookPayload::parse($vector['body']));
    }

    /**
     * The `dane_osobowe` section adds to the verdict and changes nothing else of it.
     */
    public function testAPersonalDataSectionLeavesTheVerdictAsItIs(): void
    {
        $body = self::vectorBody();
        $withSection = substr($body, 0, -1) . ',"dane_osobowe":{"rodzaj":"telefon","reakcja":"ukryj"}}';
        self::assertNotSame($body, $withSection);

        $plain = WebhookPayload::parse($body);
        $read = WebhookPayload::parse($withSection);
        self::assertSame(['rodzaj' => 'telefon', 'reakcja' => 'ukryj'], $read['dane_osobowe']);
        unset($read['dane_osobowe'], $plain['dane_osobowe']);
        self::assertSame($plain, $read);
    }

    /**
     * An answer the library does not know is no answer (`nieocenione`); a kind it does not
     * know is kept, because the list may grow; a section that is there is never read as none.
     */
    public function testAPersonalDataSectionNeverPassesAnUnknownAnswer(): void
    {
        $cases = [
            '{"rodzaj":"adres","reakcja":"pozostaw"}'    => ['rodzaj' => 'adres', 'reakcja' => 'pozostaw'],
            '{"rodzaj":"pesel","reakcja":"nieocenione"}' => ['rodzaj' => 'pesel', 'reakcja' => 'nieocenione'],
            '{"rodzaj":"telefon","reakcja":"usun"}'      => ['rodzaj' => 'telefon', 'reakcja' => 'nieocenione'],
            '{"rodzaj":"telefon","reakcja":"UKRYJ"}'     => ['rodzaj' => 'telefon', 'reakcja' => 'nieocenione'],
            '{"rodzaj":"telefon"}'                       => ['rodzaj' => 'telefon', 'reakcja' => 'nieocenione'],
            '{"rodzaj":"nowy_rodzaj","reakcja":"ukryj"}' => ['rodzaj' => 'nowy_rodzaj', 'reakcja' => 'ukryj'],
            '{"rodzaj":7,"reakcja":"ukryj"}'             => ['rodzaj' => null, 'reakcja' => 'ukryj'],
            '{}'                                         => ['rodzaj' => null, 'reakcja' => 'nieocenione'],
            '"telefon"'                                  => ['rodzaj' => null, 'reakcja' => 'nieocenione'],
            'false'                                      => ['rodzaj' => null, 'reakcja' => 'nieocenione'],
            'null'                                       => [],
        ];
        foreach ($cases as $section => $expected) {
            $read = WebhookPayload::parse(substr(self::vectorBody(), 0, -1) . ',"dane_osobowe":' . $section . '}');
            self::assertSame($expected, $read['dane_osobowe'], $section);
            self::assertSame('ocenzurowane', $read['kwalifikacja'], $section);
        }
        self::assertSame([], WebhookPayload::parse('{"id":"k","status":"nieocenione","dane_osobowe":{"rodzaj":"adres","reakcja":"ukryj"}}')['dane_osobowe'],
            'no verdict, no section');
    }

    /**
     * The body of the gateway's signature vector.
     */
    private static function vectorBody(): string
    {
        $vector = json_decode((string)file_get_contents(__DIR__ . '/../../vectors/signature.json'), true);
        self::assertStringEndsWith('}', $vector['body']);
        return $vector['body'];
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
            '{"id":"k","status":"ocenione","kwalifikacja":"zablokowane","kategorie":[],"ocenzurowany":"x █"}');
        self::assertNull($read['ocenzurowany']);
    }

    /**
     * The contract sends `ocenzurowany` only when a fragment was masked. On the vector's own
     * body, a masked text that is empty, masks nothing or is no text at all reads as absent,
     * so the plugin holds the comment instead of publishing it; the vector's mask is kept.
     */
    public function testAMaskedTextThatMasksNothingReadsAsAbsent(): void
    {
        $body = self::vectorBody();
        $mask = '"ocenzurowany":"no to jest ███████ pomysł"';
        self::assertStringContainsString($mask, $body, 'the vector must carry its mask');
        self::assertSame('no to jest ███████ pomysł', WebhookPayload::parse($body)['ocenzurowany']);

        foreach (['""', '"no to jest głupi pomysł"', '"no to jest ####### pomysł"', '"▇▇▇"', 'null', '7',
            '["███"]', '{"a":"███"}'] as $replacement) {
            $read = WebhookPayload::parse(str_replace($mask, '"ocenzurowany":' . $replacement, $body));
            self::assertSame('ocenzurowane', $read['kwalifikacja'], $replacement);
            self::assertNull($read['ocenzurowany'], $replacement);
        }
        // One masked character is a mask; the length is the plugin's own check.
        $partial = WebhookPayload::parse(str_replace($mask, '"ocenzurowany":"no to jest █ pomysł"', $body));
        self::assertSame('no to jest █ pomysł', $partial['ocenzurowany']);
    }

    public function testAnUnknownCategoryIsKeptAndRepeatsAreNot(): void
    {
        $read = WebhookPayload::parse('{"id":"k","status":"ocenione","kwalifikacja":"zablokowane",'
            . '"kategorie":["grozba","nowa_kategoria","grozba",7,""]}');
        self::assertSame(['grozba', 'nowa_kategoria'], $read['kategorie']);
    }

    /**
     * `kategorie` is a JSON list. An object, even one keyed like a list, names no category;
     * the verdict itself stays.
     */
    public function testCategoriesAreReadOnlyFromAJsonList(): void
    {
        $body = self::vectorBody();
        $list = '"kategorie":["wulgaryzmy"]';
        self::assertStringContainsString($list, $body, 'the vector must carry its categories');
        foreach (['{"0":"wulgaryzmy"}', '{"a":"grozba"}', '"wulgaryzmy"', 'null', 'true'] as $replacement) {
            $read = WebhookPayload::parse(str_replace($list, '"kategorie":' . $replacement, $body));
            self::assertSame([], $read['kategorie'], $replacement);
            self::assertSame('ocenzurowane', $read['kwalifikacja'], $replacement);
        }
        self::assertSame([], WebhookPayload::parse(str_replace($list . ',', '', $body))['kategorie'], 'missing');
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
        foreach (['', 'nie json', '[]', '"k"', '[{"id":"k-1"}]', '{"status":"ocenione"}', '{"id":""}',
            '{"id":"k 1"}', "{\"id\":\"k-1\\n\"}", '{"id":' . json_encode(str_repeat('a', 65)) . '}'] as $body) {
            self::assertNull(WebhookPayload::parse($body), $body);
        }
    }
}
