<?php

declare(strict_types=1);

namespace Minos\Tests\Repo;

use Minos\Client\WebhookPayload;
use PHPUnit\Framework\TestCase;

/**
 * The public API of `Minos\Client` is what every PHP plugin bundles, so it changes only on
 * purpose: every public method (with its signature) and constant (with its value) of every
 * class in `src/` must be the declared list below, and so must the keys `parse` returns. A
 * change here is a change of the API and is versioned (README → Versioning).
 */
final class PublicApiTest extends TestCase
{
    /** The declared API: class → its constants and its public methods' signatures. */
    private const API = [
        'Minos\Client\Signature' => [
            'constants' => [
                'HEADER'              => 'X-Wergiliusz-Podpis',
                'DEFAULT_TOLERANCE_S' => 300,
            ],
            'methods' => [
                'static sign(string $secret, string $body, int $timestamp): string',
                'static verify(string $secret, string $header, string $body, int $now, int $toleranceS = 300): bool',
            ],
        ],
        'Minos\Client\WebhookPayload' => [
            'constants' => [
                'ASSESSED'                => 'ocenione',
                'UNASSESSED'              => 'nieocenione',
                'QUALIFICATIONS'          => ['bezpieczne', 'ocenzurowane', 'zablokowane'],
                'CATEGORIES'              => [
                    'mowa_nienawisci', 'grozba', 'przemoc', 'nekanie', 'tresc_seksualna',
                    'samookaleczenie', 'oszustwo', 'spam', 'ujawnianie_danych', 'dane_osobowe',
                    'wulgaryzmy', 'uzywki', 'uwodzenie_nieletnich', 'hazard', 'niebezpieczne_zachowanie',
                ],
                'PERSONAL_DATA_KINDS'     => ['adres', 'telefon', 'pesel', 'karta_platnicza', 'rachunek'],
                'PERSONAL_DATA_REACTIONS' => ['ukryj', 'pozostaw', 'nieocenione'],
            ],
            'methods' => [
                'static parse(string $body): ?array',
            ],
        ],
    ];

    /** The keys of every array `WebhookPayload::parse` returns, in order. */
    private const PARSED_KEYS = ['id', 'status', 'kwalifikacja', 'kategorie', 'ocenzurowany', 'wsparcie', 'wersja',
        'dane_osobowe'];

    public function testEveryClassInSrcIsDeclaredAndFinal(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/../../src/*.php') ?: [] as $file) {
            $found[] = 'Minos\Client\\' . basename($file, '.php');
        }
        self::assertNotEmpty($found, 'no class found in src/: the search is broken');
        sort($found);
        $declared = array_keys(self::API);
        sort($declared);
        self::assertSame($declared, $found);
        foreach ($found as $class) {
            self::assertTrue((new \ReflectionClass($class))->isFinal(), "{$class} must stay final");
        }
    }

    public function testThePublicConstantsAreTheDeclaredOnes(): void
    {
        foreach (self::API as $class => $api) {
            $constants = [];
            foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
                if ($constant->isPublic()) {
                    $constants[$constant->getName()] = $constant->getValue();
                }
            }
            self::assertSame($api['constants'], $constants, $class);
        }
    }

    public function testThePublicMethodsAreTheDeclaredOnes(): void
    {
        foreach (self::API as $class => $api) {
            $methods = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $methods[] = self::signature($method);
            }
            sort($methods);
            $declared = $api['methods'];
            sort($declared);
            self::assertSame($declared, $methods, $class);
        }
    }

    public function testParseAlwaysReturnsTheDeclaredShape(): void
    {
        $vector = json_decode((string)file_get_contents(__DIR__ . '/../../vectors/signature.json'), true);
        foreach ([$vector['body'], '{"id":"k-1","status":"nieocenione"}', '{"id":"k-1","status":"nowy"}'] as $body) {
            self::assertSame(self::PARSED_KEYS, array_keys((array)WebhookPayload::parse($body)), $body);
        }
    }

    /**
     * A method's signature as one line: `static name(type $param = default, …): type`.
     */
    private static function signature(\ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $text = self::type($param->getType()) . ' $' . $param->getName();
            if ($param->isDefaultValueAvailable()) {
                $text .= ' = ' . var_export($param->getDefaultValue(), true);
            }
            $params[] = $text;
        }
        return ($method->isStatic() ? 'static ' : '') . $method->getName() . '(' . implode(', ', $params) . ')'
            . ($method->hasReturnType() ? ': ' . self::type($method->getReturnType()) : '');
    }

    private static function type(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return (string)$type;
        }
        return ($type->allowsNull() ? '?' : '') . $type->getName();
    }
}
