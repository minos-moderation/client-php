<?php

declare(strict_types=1);

/*
 * A webhook receiver for EndToEndTest, run by PHP's built-in server: it appends every
 * delivery (its signature header and exact body) as one JSON line to RECEIVER_OUT and
 * answers 204.
 */

file_put_contents((string)getenv('RECEIVER_OUT'), json_encode([
    'podpis' => $_SERVER['HTTP_X_WERGILIUSZ_PODPIS'] ?? null,
    'typ'    => $_SERVER['CONTENT_TYPE'] ?? null,
    'body'   => file_get_contents('php://input'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(204);
