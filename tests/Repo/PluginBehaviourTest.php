<?php

declare(strict_types=1);

namespace Minos\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * `docs/plugin-behaviour.md` is what every plugin's compliance table is checked against, by
 * its identifiers: each one must be stated once, in bold, and named in the index, so a
 * plugin that reads them finds every rule and no rule twice.
 */
final class PluginBehaviourTest extends TestCase
{
    /** The rules of 2026-09-30, which the plugins' tables already map. */
    private const BASE_RULES = 24;

    public function testEveryRuleHasOneStableIdentifierAndTheIndexNamesThemAll(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../docs/plugin-behaviour.md');
        preg_match_all('/\*\*([FSAPD]\d+[a-z]?)\*\*/', $text, $stated);
        self::assertGreaterThanOrEqual(self::BASE_RULES, count($stated[1]), 'the rules must be found: the pattern is broken');
        self::assertSame(array_values(array_unique($stated[1])), $stated[1], 'an identifier is stated twice');

        self::assertSame(1, preg_match('/^## Index\n(.*)\z/ms', $text, $index), 'the index must be found');
        preg_match_all('/^\| `([FSAPD]\d+[a-z]?)` \|/m', $index[1], $indexed);
        self::assertSame($stated[1], $indexed[1], 'the index names every rule, in the order they are stated');
    }
}
