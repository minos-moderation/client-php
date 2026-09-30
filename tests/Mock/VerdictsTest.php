<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Client\WebhookPayload;
use Minos\Mock\Verdicts;
use PHPUnit\Framework\TestCase;

/**
 * The mock's markers produce every shape of the contract's payload — and only those.
 */
final class VerdictsTest extends TestCase
{
    /** The payload's fields, in the order the gateway builds them. */
    private const FIELDS = ['id', 'status', 'kwalifikacja', 'kategorie', 'ocenzurowany', 'wsparcie', 'wersja'];

    public function testWithoutMarkersTheCommentIsSafe(): void
    {
        self::assertSame([
            'id' => 'k-1', 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
            'kategorie' => [], 'wsparcie' => false, 'wersja' => Verdicts::VERSION,
        ], Verdicts::payload('k-1', 'zwykły komentarz'));
    }

    public function testBlokujBlocksAndNamesItsCategoriesSorted(): void
    {
        $payload = Verdicts::payload('k', 'x [minos:blokuj] [minos:kategoria=spam] [minos:kategoria=grozba] [minos:kategoria=wymyslona]');
        self::assertSame('zablokowane', $payload['kwalifikacja']);
        self::assertSame(['grozba', 'spam'], $payload['kategorie']);
        self::assertArrayNotHasKey('ocenzurowany', $payload);
    }

    public function testCenzurujMasksEveryMarkedFragmentCharacterByCharacter(): void
    {
        $payload = Verdicts::payload('k', 'no to jest [[żałosny]] i [[głupi]] pomysł [minos:cenzuruj]');
        self::assertSame('ocenzurowane', $payload['kwalifikacja']);
        self::assertSame('no to jest ███████ i █████ pomysł [minos:cenzuruj]', $payload['ocenzurowany']);
    }

    public function testCenzurujWithoutAFragmentHasNoMaskedText(): void
    {
        $payload = Verdicts::payload('k', 'nic do maskowania [minos:cenzuruj]');
        self::assertSame('ocenzurowane', $payload['kwalifikacja']);
        self::assertArrayNotHasKey('ocenzurowany', $payload);
    }

    public function testSelfHarmSetsWsparcieEvenWhenLetThrough(): void
    {
        $payload = Verdicts::payload('k', 'nie daję rady [minos:kategoria=samookaleczenie]');
        self::assertSame('bezpieczne', $payload['kwalifikacja']);
        self::assertTrue($payload['wsparcie']);
    }

    public function testNieocenioneIsOnlyTheIdAndTheStatus(): void
    {
        self::assertSame(['id' => 'k', 'status' => 'nieocenione'],
            Verdicts::payload('k', '[minos:nieocenione] [minos:blokuj]'));
    }

    public function testBezWersjiSendsANullVersion(): void
    {
        self::assertNull(Verdicts::payload('k', '[minos:bez-wersji]')['wersja']);
    }

    public function testDeliveryFlagsAreReadFromTheComment(): void
    {
        self::assertSame(['dwa-razy', 'cisza'], Verdicts::deliveryFlags('[minos:cisza] a [minos:dwa-razy] [minos:blokuj]'));
        self::assertSame([], Verdicts::deliveryFlags('[minos:blokuj]'));
    }

    /**
     * @dataProvider markedComments
     */
    public function testEveryPayloadIsAContractPayloadAndNothingMore(string $text): void
    {
        $payload = Verdicts::payload('k-9', $text);
        $fields = array_keys($payload);

        self::assertSame(array_values(array_intersect(self::FIELDS, $fields)), $fields,
            'only contract fields, in the presenter\'s order');
        $read = WebhookPayload::parse((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        self::assertNotNull($read);
        self::assertSame($payload['status'], $read['status']);
        self::assertSame($payload['kwalifikacja'] ?? null, $read['kwalifikacja']);
        self::assertSame($payload['kategorie'] ?? [], $read['kategorie']);
        self::assertSame($payload['ocenzurowany'] ?? null, $read['ocenzurowany']);
        self::assertSame($payload['wsparcie'] ?? false, $read['wsparcie']);
        self::assertSame($payload['wersja'] ?? null, $read['wersja']);
    }

    public function markedComments(): array
    {
        return [
            'plain'        => ['komentarz'],
            'blocked'      => ['[minos:blokuj] [minos:kategoria=mowa_nienawisci]'],
            'censored'     => ['a [[b]] [minos:cenzuruj] [minos:kategoria=wulgaryzmy]'],
            'support'      => ['[minos:kategoria=samookaleczenie]'],
            'unassessed'   => ['[minos:nieocenione]'],
            'no version'   => ['[minos:bez-wersji] [minos:blokuj]'],
        ];
    }
}
