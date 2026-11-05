<?php

declare(strict_types=1);

namespace Minos\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The PreToolUse hook that refuses staging the whole work tree (`.claude/hooks/no-add-all.sh`
 * and `no_add_all.py`), run through the command `.claude/settings.json` registers, as Claude
 * Code runs it: what it refuses, what it lets through (a quoted message, a here-document, an
 * explicit path), and that unreadable input never stops a session.
 */
final class AddAllHookTest extends TestCase
{
    private string $command = '';

    protected function setUp(): void
    {
        self::assertNotSame('', trim((string)shell_exec('command -v python3')), 'python3 is needed: the hook parses with it');
        $settings = json_decode((string)file_get_contents(__DIR__ . '/../../.claude/settings.json'), true);
        foreach ($settings['hooks']['PreToolUse'] ?? [] as $entry) {
            foreach ($entry['hooks'] ?? [] as $hook) {
                if (strpos((string)($hook['command'] ?? ''), 'no-add-all.sh') !== false) {
                    $this->command = (string)$hook['command'];
                }
            }
        }
        self::assertNotSame('', $this->command, 'the hook is not registered in .claude/settings.json');
    }

    /** @return iterable<string,array{0:string}> */
    public static function refused(): iterable
    {
        foreach ([
            'git add -A', 'git add --all', 'git add .', 'git add -Av', 'git add --al', 'git add --no-ignore-removal',
            'git stage -A', 'git add -- .', 'git add *', 'git add :/', 'git -C /tmp/w add .', 'git --no-pager add -A',
            'cd /tmp/w && git add -A', "git status\ngit add .", 'GIT_DIR=.git git add -A', 'sudo -u me git add -A',
            "bash -c 'git add -A'", 'sh -c "cd x && git add ."', 'eval git add -A', '(git add -A)', 'git add . 2>&1',
            "git add \\\n  -A", 'git add -u', 'git add --update', 'git add -uv', 'git stage --up',
            'git commit -a -m x', 'git commit -am "x"', "git commit -qam 'x'", 'git commit --all -m x',
            'git commit -C HEAD -a', 'git -C /tmp/w commit --al',
        ] as $command) {
            yield $command => [$command];
        }
    }

    /** @dataProvider refused */
    public function testStagingTheWholeTreeIsRefused(string $command): void
    {
        [$status, $err] = self::runHook($this->command, $command);
        self::assertSame(2, $status, $err);
        self::assertStringContainsString('Stage explicit paths', $err);
    }

    /** @return iterable<string,array{0:string}> */
    public static function letThrough(): iterable
    {
        foreach ([
            'git add README.md', 'git add src/Signature.php tests/Client/', 'git add -p', 'git add *.php',
            'git log --all', 'echo git add -A', "grep -n 'git add .' CLAUDE.md", "git commit -m 'never git add -A'",
            "git commit -q -F - <<'EOF'\nSubject\n\nNever git add -A, never git add .\nEOF",
            'git add docs/x.md # not git add -A', "git add 'unbalanced", 'git diff --name-only origin/main...HEAD',
            'git add -u src/', 'git add -u -- src/X.php', 'git add --update --pathspec-from-file=list.txt',
            'git commit -m "add -a"', 'git commit -m -a', 'git commit -uall -m x', 'git commit -q -F msg.txt',
            'git commit --amend --no-edit', 'git commit -- src/X.php',
        ] as $command) {
            yield $command => [$command];
        }
    }

    /** @dataProvider letThrough */
    public function testAnythingElseRuns(string $command): void
    {
        [$status, $err] = self::runHook($this->command, $command);
        self::assertSame(0, $status, $err);
        self::assertSame('', $err);
    }

    public function testInputItCannotReadNeverStopsTheSession(): void
    {
        foreach (['', 'not json', '{"tool_input": {"command": 7}}', '{"tool_name": "Bash"}'] as $input) {
            $process = proc_open(['bash', '-c', $this->command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, null, ['CLAUDE_PROJECT_DIR' => (string)realpath(__DIR__ . '/../..'), 'PATH' => (string)getenv('PATH')]);
            self::assertIsResource($process);
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), "input: {$input}");
        }
    }

    /**
     * Runs the registered command on a Bash call, as Claude Code does: the call as JSON on
     * stdin, from the repository's root.
     *
     * @return array{0:int,1:string} The exit status and stderr.
     */
    private static function runHook(string $command, string $bashCommand): array
    {
        $root = (string)realpath(__DIR__ . '/../..');
        $process = proc_open(['bash', '-c', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root, ['CLAUDE_PROJECT_DIR' => $root, 'PATH' => (string)getenv('PATH')]);
        self::assertIsResource($process);
        fwrite($pipes[0], (string)json_encode(['session_id' => 'test', 'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Bash', 'tool_input' => ['command' => $bashCommand, 'description' => 'test']]));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $err];
    }
}
