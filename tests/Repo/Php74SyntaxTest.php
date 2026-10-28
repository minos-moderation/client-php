<?php

declare(strict_types=1);

namespace Minos\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The library and the mock run on PHP 7.4 (customers' hosts, the plugins' oldest PHP). CI's
 * 7.4 job has the final word, but it only sees the code the suite happens to load; this test
 * reads the tokens of EVERY `.php` file under `src/`, `mock-gateway/` and `tests/` and fails
 * on the PHP 8 syntax `CLAUDE.md` forbids: `match`, `?->`, attributes, enums, `readonly`,
 * `mixed`, union and intersection types, a `static` return type, constructor promotion,
 * named arguments, a trailing comma in a parameter or `use` list, a `catch` without a
 * variable, and calls to the functions PHP 8 added.
 *
 * It works with the tokenizers of both 7.4 and 8.x: on 8.x `match`, `?->`, `#[`, `enum` and
 * `readonly` have tokens of their own; on 7.4 they are plain strings, a `?` before `->` or
 * a comment starting with `#[`, so the context decides.
 */
final class Php74SyntaxTest extends TestCase
{
    /** Functions PHP 8 added that a 7.4 host does not have. */
    private const PHP8_FUNCTIONS = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list',
        'get_debug_type', 'fdiv', 'get_resource_id', 'preg_last_error_msg'];

    /** Token names that exist only for PHP 8 syntax. */
    private const PHP8_TOKENS = ['T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE', 'T_ENUM', 'T_READONLY'];

    /** The roots scanned, relative to the repository. */
    private const ROOTS = ['src', 'mock-gateway', 'tests'];

    public function testNoFileUsesPhp8Syntax(): void
    {
        $files = self::files();
        self::assertGreaterThanOrEqual(20, count($files), 'the scan must find the repository\'s files: it is broken');
        foreach (['src/Signature.php', 'mock-gateway/src/Intake.php', 'tests/Repo/Php74SyntaxTest.php'] as $known) {
            self::assertContains($known, array_keys($files), "the scan must read {$known}");
        }
        $found = [];
        foreach ($files as $path => $file) {
            foreach (self::violations((string)file_get_contents($file)) as $violation) {
                $found[] = "{$path}:{$violation}";
            }
        }
        self::assertSame([], $found, 'PHP 8 syntax in a file that must run on PHP 7.4');
    }

    /**
     * @dataProvider forbidden
     */
    public function testEachForbiddenConstructIsFound(string $source): void
    {
        self::assertNotSame([], self::violations($source), $source);
    }

    public function forbidden(): array
    {
        return [
            'match'                      => ['<?php $a = match ($b) { 1 => 2, default => 3 };'],
            'nullsafe operator'          => ['<?php $a = $b?->c;'],
            'attribute'                  => ["<?php\n#[Pure]\nfunction f() {}"],
            'enum'                       => ['<?php enum Suit { case Hearts; }'],
            'readonly property'          => ['<?php class A { public readonly int $x; }'],
            'readonly class'             => ['<?php readonly class A {}'],
            'mixed parameter'            => ['<?php function f(mixed $x) {}'],
            'mixed return type'          => ['<?php function f(): mixed { return 1; }'],
            'mixed property'             => ['<?php class A { public mixed $x; }'],
            'union parameter'            => ['<?php function f(int|string $x) {}'],
            'union return type'          => ['<?php function f(): int|false { return 1; }'],
            'union property'             => ['<?php class A { private int|string $x; }'],
            'nullable union'             => ['<?php $f = fn(int|null $x): int => 1;'],
            'intersection type'          => ['<?php function f(A&B $x) {}'],
            'static return type'         => ['<?php class A { public static function f(): static { return new static(); } }'],
            'promoted constructor'       => ['<?php class A { public function __construct(private int $x) {} }'],
            'promoted without a type'    => ['<?php class A { public function __construct(public $x) {} }'],
            'named argument'             => ['<?php f(name: 1);'],
            'named argument after one'   => ['<?php $o->f(1, flags: 2);'],
            'named keyword argument'     => ['<?php f(array: [1]);'],
            'trailing comma, parameters' => ["<?php function f(\n    \$a,\n    \$b,\n) {}"],
            'trailing comma, closure'    => ['<?php $f = function ($a,) {};'],
            'trailing comma, use list'   => ['<?php $f = function () use ($a, $b,) {};'],
            'catch without a variable'   => ['<?php try {} catch (\Exception) {}'],
            'str_contains'               => ['<?php $a = str_contains($b, "c");'],
            'str_starts_with'            => ['<?php $a = str_starts_with($b, "c");'],
            'str_ends_with, qualified'   => ['<?php $a = \str_ends_with($b, "c");'],
            'array_is_list'              => ['<?php $a = array_is_list($b);'],
        ];
    }

    /**
     * @dataProvider allowed
     */
    public function testValidPhp74IsNotFlagged(string $source): void
    {
        self::assertSame([], self::violations($source), $source);
    }

    public function allowed(): array
    {
        return [
            'methods named like keywords' => ['<?php $o->match(1); A::match(2); $o->str_contains("x"); A::enum();'],
            'parameter forms'             => ['<?php function f(?int $a = null, array &$b = [], string ...$c): ?string { return null; }'],
            'by-reference return'         => ['<?php function &f(array &$a) { return $a; }'],
            'a bitwise or in a default'   => ['<?php function g(int $f = A | B) { return C | D; }'],
            'ternaries in arguments'      => ['<?php f($a ? B : C, D ?: E, $x ? "a" : "b", $y?$z->w:$v);'],
            'a trailing comma in a call'  => ["<?php f(\n    1,\n    2,\n);"],
            'switch and alternative syntax' => ['<?php switch ($a) { case B: break; default: break; } if ($a): echo 1; else: echo 2; endif;'],
            'closures and arrow functions' => ['<?php $f = function ($a) use ($b): int { return 1; }; $g = fn(int $x): int => $x;'],
            'typed and static properties' => ['<?php class A { public static $x; private ?int $y = null; var $z; public function __construct(int $x) {} }'],
            'catch with a variable'       => ['<?php try {} catch (\Exception | \Error $e) {}'],
            'comments and strings'        => ["<?php\n// match (\$x) str_contains( #[x] \$a?->b\n# not an attribute\n\$s = 'match (\$x) f(name: 1) str_contains(';"],
            'imports and declarations'    => ['<?php declare(strict_types=1); use function Foo\bar; use Foo\{A, B};'],
            'a static method'             => ['<?php class A { public static function f(): self { return new static(); } }'],
        ];
    }

    /**
     * The PHP 8 syntax in one source, as `line N: what` lines.
     *
     * @param string $source PHP source.
     * @return array<int,string> The violations; empty when the source is PHP 7.4.
     */
    private static function violations(string $source): array
    {
        $found = [];
        $forbiddenIds = [];
        foreach (self::PHP8_TOKENS as $name) {
            if (defined($name)) {
                $forbiddenIds[constant($name)] = $name;
            }
        }
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source) as $raw) {
            if (is_array($raw)) {
                $line = $raw[2];
                if (strncmp($raw[1], '#[', 2) === 0) {
                    $found[] = "{$line}: an attribute";
                }
                if (in_array($raw[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    continue;
                }
                // PHP 8.1 gives `&` tokens of its own; 7.4 a plain string.
                $tokens[] = [$raw[1] === '&' ? '&' : $raw[0], $raw[1], $line];
            } else {
                $tokens[] = [$raw, $raw, $line];
            }
        }

        foreach ($tokens as $i => [$id, $text, $at]) {
            $previous = $tokens[$i - 1][0] ?? null;
            $next = $tokens[$i + 1] ?? [null, '', $at];
            $member = in_array($previous, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true);
            $word = strtolower($text);
            // After `->`, `::` or `function`, a keyword is a member's name (`A::match()`).
            if (is_int($id) && isset($forbiddenIds[$id]) && !$member) {
                $found[] = "{$at}: {$forbiddenIds[$id]}";
            }
            if ($id === T_STRING && $word === 'match' && $next[0] === '(' && !$member) {
                $found[] = "{$at}: match";
            }
            if ($id === '?' && $next[0] === T_OBJECT_OPERATOR) {
                $found[] = "{$at}: ?->";
            }
            if ($id === T_STRING && $word === 'enum' && $next[0] === T_STRING && !$member) {
                $found[] = "{$at}: enum";
            }
            if ($id === T_STRING && $text === 'readonly' && $next[0] !== '(' && !$member) {
                $found[] = "{$at}: readonly";
            }
            if (self::isName($id) && $next[0] === '(' && !$member
                && in_array(strtolower(substr((string)strrchr('\\' . $text, '\\'), 1)), self::PHP8_FUNCTIONS, true)) {
                $found[] = "{$at}: {$text}() needs PHP 8";
            }
            if (($previous === '(' || $previous === ',') && is_int($id) && preg_match('/^[A-Za-z_]\w*$/', $text)
                && $next[0] === ':') {
                $found[] = "{$at}: a named argument ({$text}:)";
            }
            if ($id === T_CATCH) {
                $close = self::closing($tokens, $i + 1);
                $variables = array_filter(array_slice($tokens, $i, $close - $i + 1), static function (array $t): bool {
                    return $t[0] === T_VARIABLE;
                });
                if ($variables === []) {
                    $found[] = "{$at}: a catch without a variable";
                }
            }
            if ($id === T_FUNCTION || $id === T_FN) {
                $found = array_merge($found, self::signatureViolations($tokens, $i));
            }
            if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
                $type = [];
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    $t = $tokens[$j];
                    if (in_array($t[0], [T_VARIABLE, T_FUNCTION, T_CONST, ';', '{', '=', ',', ')'], true)) {
                        break;
                    }
                    $type[] = $t;
                }
                if (isset($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $found = array_merge($found, self::typeViolations($type, 'a property'));
                }
            }
        }
        return $found;
    }

    /**
     * The violations of one function's signature: its parameters (promotion, types, a
     * trailing comma), a closure's `use` list (a trailing comma) and its return type.
     *
     * @param array<int,array{0:int|string,1:string,2:int}> $tokens The significant tokens.
     * @param int                                             $at     The `function`/`fn` token.
     * @return array<int,string>
     */
    private static function signatureViolations(array $tokens, int $at): array
    {
        $open = $at + 1;
        while (isset($tokens[$open]) && !in_array($tokens[$open][0], ['(', ';', '{'], true)) {
            $open++;
        }
        if (!isset($tokens[$open]) || $tokens[$open][0] !== '(') {
            return []; // `use function …;`
        }
        $found = [];
        $close = self::closing($tokens, $open);
        $line = $tokens[$open][2];
        $parameters = self::split($tokens, $open, $close);
        if (count($parameters) > 1 && end($parameters) === []) {
            $found[] = "{$line}: a trailing comma in a parameter list";
        }
        foreach ($parameters as $parameter) {
            $type = [];
            foreach ($parameter as $t) {
                if ($t[0] === T_VARIABLE) {
                    break;
                }
                $type[] = $t;
            }
            // `&$x` and `...$x` are not types.
            while ($type !== [] && in_array(end($type)[0], ['&', T_ELLIPSIS], true)) {
                array_pop($type);
            }
            foreach ($type as $t) {
                if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                    $found[] = "{$t[2]}: a promoted constructor parameter";
                }
            }
            $found = array_merge($found, self::typeViolations($type, 'a parameter'));
        }

        $next = $close + 1;
        if (isset($tokens[$next]) && $tokens[$next][0] === T_USE && ($tokens[$next + 1][0] ?? null) === '(') {
            $useClose = self::closing($tokens, $next + 1);
            $uses = self::split($tokens, $next + 1, $useClose);
            if (count($uses) > 1 && end($uses) === []) {
                $found[] = "{$tokens[$next][2]}: a trailing comma in a use list";
            }
            $next = $useClose + 1;
        }
        if (isset($tokens[$next]) && $tokens[$next][0] === ':') {
            $type = [];
            for ($j = $next + 1; isset($tokens[$j]) && !in_array($tokens[$j][0], ['{', ';', T_DOUBLE_ARROW], true); $j++) {
                $type[] = $tokens[$j];
            }
            foreach ($type as $t) {
                if ($t[0] === T_STATIC) {
                    $found[] = "{$t[2]}: a static return type";
                }
            }
            $found = array_merge($found, self::typeViolations($type, 'a return type'));
        }
        return $found;
    }

    /**
     * The PHP 8 forms of a type: `mixed`, a union, an intersection.
     *
     * @param array<int,array{0:int|string,1:string,2:int}> $type The type's tokens.
     * @param string                                          $what Where it is declared.
     * @return array<int,string>
     */
    private static function typeViolations(array $type, string $what): array
    {
        $found = [];
        foreach ($type as $t) {
            if ($t[0] === '|') {
                $found[] = "{$t[2]}: a union type in {$what}";
            } elseif ($t[0] === '&') {
                $found[] = "{$t[2]}: an intersection type in {$what}";
            } elseif ($t[0] === T_STRING && $t[1] === 'mixed') {
                $found[] = "{$t[2]}: mixed in {$what}";
            }
        }
        return $found;
    }

    /**
     * The tokens between a `(` and its `)`, split at their top-level commas.
     *
     * @param array<int,array{0:int|string,1:string,2:int}> $tokens
     * @return array<int,array<int,array{0:int|string,1:string,2:int}>> One list per part; an
     *     empty last part is a trailing comma.
     */
    private static function split(array $tokens, int $open, int $close): array
    {
        $parts = [[]];
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $id = $tokens[$j][0];
            if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($id, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($id === ',' && $depth === 0) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $tokens[$j];
        }
        return $parts === [[]] ? [] : $parts;
    }

    /**
     * The index of the `)` that closes the `(` at or after `$from`.
     *
     * @param array<int,array{0:int|string,1:string,2:int}> $tokens
     */
    private static function closing(array $tokens, int $from): int
    {
        $depth = 0;
        for ($j = $from; isset($tokens[$j]); $j++) {
            if ($tokens[$j][0] === '(') {
                $depth++;
            } elseif ($tokens[$j][0] === ')' && --$depth === 0) {
                return $j;
            }
        }
        return count($tokens) - 1;
    }

    /**
     * Whether a token is a name a function call may start with (`f`, `\f`, `A\f`).
     *
     * @param int|string $id The token's id.
     */
    private static function isName($id): bool
    {
        foreach (['T_STRING', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED'] as $name) {
            if (defined($name) && $id === constant($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every `.php` file under the scanned roots.
     *
     * @return array<string,string> Path relative to the repository → absolute path.
     */
    private static function files(): array
    {
        $root = (string)realpath(__DIR__ . '/../..');
        $files = [];
        foreach (self::ROOTS as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($files);
        return $files;
    }
}
