<?php

declare(strict_types=1);

/*
 * The mock gateway's worker: delivers queued verdicts to `MINOS_MOCK_WEBHOOK_URL`.
 *
 *   php mock-gateway/bin/worker.php          # every second, until stopped
 *   php mock-gateway/bin/worker.php --once   # one pass, then exit (tests, scripts)
 *
 * Prints one line per entry it touched: the item id, the outcome and the webhook's status.
 */

require __DIR__ . '/../autoload.php';

use Minos\Mock\Config;
use Minos\Mock\Worker;

$cfg = Config::fromEnv();
if ($cfg->webhookUrl === null) {
    fwrite(STDERR, "MINOS_MOCK_WEBHOOK_URL is not set — there is nowhere to deliver.\n");
    exit(2);
}
$worker = new Worker($cfg);
$once = in_array('--once', $argv, true);

do {
    foreach ($worker->runOnce(time()) as $line) {
        fwrite(STDOUT, sprintf("%s %s %s %d\n", date('H:i:s'), $line['id'], $line['wynik'], $line['status']));
    }
    if (!$once) {
        sleep(1);
    }
} while (!$once);
