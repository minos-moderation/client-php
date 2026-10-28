<?php

declare(strict_types=1);

namespace Minos\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * `docs/plugin-behaviour.md` is what every plugin's compliance table is checked against, by
 * its identifiers: each one must be stated once, in bold, and named in the index, so a
 * plugin that reads them finds every rule and no rule twice. The identifiers are stable: the
 * declared list may only grow, so removing or renaming one fails here.
 */
final class PluginBehaviourTest extends TestCase
{
    /** Every identifier the file has carried; a new rule is added here, none is removed. */
    private const IDENTIFIERS = [
        'F1', 'F2', 'F3', 'F4', 'F5a', 'F5b', 'F5c', 'F5d', 'F6', 'F7', 'F8', 'F9',
        'S1', 'S2', 'S3', 'S4a', 'S4b', 'S4c', 'S5', 'S6', 'S7',
        'A1', 'A2a', 'A2b', 'A2c', 'A2d', 'A2e', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8',
        'P1',
        'D1', 'D2',
    ];

    public function testEveryRuleHasOneStableIdentifierAndTheIndexNamesThemAll(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../docs/plugin-behaviour.md');
        preg_match_all('/\*\*([FSAPD]\d+[a-z]?)\*\*/', $text, $stated);
        self::assertGreaterThanOrEqual(count(self::IDENTIFIERS), count($stated[1]),
            'the rules must be found: the pattern is broken');
        self::assertSame(array_values(array_unique($stated[1])), $stated[1], 'an identifier is stated twice');

        self::assertSame(1, preg_match('/^## Index\n(.*)\z/ms', $text, $index), 'the index must be found');
        preg_match_all('/^\| `([FSAPD]\d+[a-z]?)` \|/m', $index[1], $indexed);
        self::assertSame($stated[1], $indexed[1], 'the index names every rule, in the order they are stated');
    }

    public function testNoDeclaredIdentifierIsRemovedOrRenamed(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../docs/plugin-behaviour.md');
        preg_match_all('/\*\*([FSAPD]\d+[a-z]?)\*\*/', $text, $stated);
        self::assertSame([], array_values(array_diff(self::IDENTIFIERS, $stated[1])),
            'a declared identifier is missing: identifiers are never removed or renamed');
    }
}
