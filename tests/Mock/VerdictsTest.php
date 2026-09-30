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
        self::assertSame('no to jest [[███████]] i [[█████]] pomysł [minos:cenzuruj]', $payload['ocenzurowany']);
    }

    /**
     * The gateway's masked text is as long as the text that was sent, `█` per masked
     * character, and a plugin may refuse one of another length.
     *
     * @dataProvider censoredComments
     */
    public function testAMaskedTextKeepsTheSentTextsLengthCharacterByCharacter(string $text): void
    {
        $masked = Verdicts::payload('k', $text)['ocenzurowany'] ?? null;
        self::assertIsString($masked);
        self::assertSame(mb_strlen($text), mb_strlen($masked), 'characters, not bytes');

        $sent = self::characters($text);
        $changed = 0;
        foreach (self::characters($masked) as $position => $character) {
            if ($character !== $sent[$position]) {
                self::assertSame('█', $character, "position {$position} is either kept or masked");
                $changed++;
            }
        }
        self::assertGreaterThan(0, $changed, 'something was masked');
    }

    public function censoredComments(): array
    {
        return [
            'one fragment'            => ['no to jest [[głupi]] pomysł [minos:cenzuruj]'],
            'two fragments'           => ['[[żałosny]] i [[głupi]] [minos:cenzuruj] [minos:kategoria=wulgaryzmy]'],
            'four-byte characters'    => ['a [[😀ź😀]] b [minos:cenzuruj]'],
            'a fragment on two lines' => ["a [[b\nc]] d [minos:cenzuruj]"],
        ];
    }

    /**
     * @dataProvider unclearBrackets
     */
    public function testNestedOrUnbalancedBracketsMaskNothing(string $text): void
    {
        $payload = Verdicts::payload('k', $text);
        self::assertSame('ocenzurowane', $payload['kwalifikacja']);
        self::assertArrayNotHasKey('ocenzurowany', $payload, 'the mock cannot tell what to mask');
    }

    public function unclearBrackets(): array
    {
        return [
            'nested'       => ['a [[b [[c]] d]] [minos:cenzuruj]'],
            'never closed' => ['a [[b]] c [[d [minos:cenzuruj]'],
            'never opened' => ['a b]] [minos:cenzuruj]'],
            'closed twice' => ['a [[b]] c]] [minos:cenzuruj]'],
        ];
    }

    public function testCenzurujWithoutAFragmentHasNoMaskedText(): void
    {
        $payload = Verdicts::payload('k', 'nic do maskowania [minos:cenzuruj]');
        self::assertSame('ocenzurowane', $payload['kwalifikacja']);
        self::assertArrayNotHasKey('ocenzurowany', $payload);
        self::assertArrayNotHasKey('ocenzurowany', Verdicts::payload('k', 'pusty [[]] fragment [minos:cenzuruj]'));
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

    /**
     * @return array<int,string> The characters (code points) of a UTF-8 text.
     */
    private static function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
