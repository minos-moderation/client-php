<?php

declare(strict_types=1);

/*
 * The mock gateway's routes, for PHP's built-in server:
 *
 *   php -S 127.0.0.1:8100 -t mock-gateway/public
 *
 * `POST /api/v1/b2b/oceny` (a batch) and `POST /api/v1/b2b/ocena` (one comment,
 * synchronously) answer like the real ones, also without `/v1` (`/api/b2b/…`), which the
 * real gateway rewrites them to; everything else is `404 nie_znaleziono`. A batch's
 * verdicts are delivered by `bin/worker.php`, not here — as in the real gateway, the
 * request only queues. The synchronous route answers the verdict itself and queues nothing.
 */

require __DIR__ . '/../autoload.php';

use Minos\Mock\Config;
use Minos\Mock\Intake;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$key = isset($_SERVER['HTTP_X_GATEWAY_KEY']) ? (string)$_SERVER['HTTP_X_GATEWAY_KEY'] : null;
$force = isset($_SERVER['HTTP_X_MINOS_MOCK_ERROR']) ? (string)$_SERVER['HTTP_X_MINOS_MOCK_ERROR'] : null;

if (in_array($path, ['/api/v1/b2b/oceny', '/api/b2b/oceny'], true) && $method === 'POST') {
    $answer = (new Intake(Config::fromEnv()))->handle($key, $force, (string)file_get_contents('php://input'), time());
} elseif (in_array($path, ['/api/v1/b2b/ocena', '/api/b2b/ocena'], true) && $method === 'POST') {
    $answer = (new Intake(Config::fromEnv()))->handleSync($key, $force, (string)file_get_contents('php://input'));
} else {
    $answer = ['status' => 404, 'body' => ['blad' => ['kod' => 'nie_znaleziono', 'komunikat' => 'Nie ma tu nic.']]];
}

http_response_code($answer['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($answer['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
