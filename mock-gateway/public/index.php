<?php

declare(strict_types=1);

/*
 * The mock gateway's only route, for PHP's built-in server:
 *
 *   php -S 127.0.0.1:8100 -t mock-gateway/public
 *
 * `POST /api/v1/b2b/oceny` (also `/api/b2b/oceny`, which the real gateway rewrites it to)
 * answers like the real one; everything else is `404 nie_znaleziono`. Verdicts are
 * delivered by `bin/worker.php`, not here — as in the real gateway, the request only queues.
 */

require __DIR__ . '/../autoload.php';

use Minos\Mock\Config;
use Minos\Mock\Intake;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (in_array($path, ['/api/v1/b2b/oceny', '/api/b2b/oceny'], true) && $method === 'POST') {
    $answer = (new Intake(Config::fromEnv()))->handle(
        isset($_SERVER['HTTP_X_GATEWAY_KEY']) ? (string)$_SERVER['HTTP_X_GATEWAY_KEY'] : null,
        isset($_SERVER['HTTP_X_MINOS_MOCK_ERROR']) ? (string)$_SERVER['HTTP_X_MINOS_MOCK_ERROR'] : null,
        (string)file_get_contents('php://input'),
        time()
    );
} else {
    $answer = ['status' => 404, 'body' => ['blad' => ['kod' => 'nie_znaleziono', 'komunikat' => 'Nie ma tu nic.']]];
}

http_response_code($answer['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($answer['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
