<?php

declare(strict_types=1);

namespace Minos\Tests\Mock;

use Minos\Mock\Config;
use Minos\Mock\Queue;
use PHPUnit\Framework\TestCase;

/**
 * A mock configuration with its own queue directory, removed after each test.
 */
abstract class MockTestCase extends TestCase
{
    /** @var string */
    protected $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/minos-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * @param array<string,string> $env Variables over the test defaults.
     */
    protected function config(array $env = []): Config
    {
        return Config::fromEnv($env + [
            'MINOS_MOCK_WEBHOOK_URL' => 'https://forum.example/wergiliusz/webhook',
            'MINOS_MOCK_DATA_DIR'    => $this->dir,
            'MINOS_MOCK_DELAY_S'     => '0',
        ]);
    }

    protected function queue(Config $cfg): Queue
    {
        return new Queue($cfg->queuePath());
    }
}
