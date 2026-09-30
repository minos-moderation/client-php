<?php

declare(strict_types=1);

namespace Minos\Client;

/**
 * The signature the Wergiliusz gateway puts on every webhook delivery.
 *
 * Header `X-Wergiliusz-Podpis: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, keyed
 * with the webhook secret printed once when the key was issued. A plugin verifies it
 * BEFORE it parses the body, in constant time, and refuses a timestamp more than five
 * minutes from its own clock, so a delivery captured on the way cannot be replayed later.
 *
 * {@see verify} is the reference verifier of the contract (`docs/contract.md`), unchanged:
 * `SignatureTest` checks both against the same test vector. The class has no dependencies
 * and no PHP 8 syntax, because it runs inside plugins on customers' hosts.
 */
final class Signature
{
    /** The header the gateway signs with (a wire label — never renamed). */
    public const HEADER = 'X-Wergiliusz-Podpis';

    /** How far, in seconds, a delivery's timestamp may be from the receiver's clock. */
    public const DEFAULT_TOLERANCE_S = 300;

    /**
     * Whether a delivery carries a valid signature.
     *
     * @param string $secret     The key's webhook secret.
     * @param string $header     The value of the `X-Wergiliusz-Podpis` header.
     * @param string $body       The EXACT bytes of the request body, before any parsing.
     * @param int    $now        The receiver's clock, in unix seconds.
     * @param int    $toleranceS The largest accepted distance between the two clocks.
     * @return bool True only for a well-formed header, a fresh timestamp and a matching MAC.
     */
    public static function verify(
        string $secret,
        string $header,
        string $body,
        int $now,
        int $toleranceS = self::DEFAULT_TOLERANCE_S
    ): bool {
        if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', trim($header), $m)) {
            return false;
        }
        if (abs($now - (int)$m[1]) > $toleranceS) {
            return false;
        }
        $expected = hash_hmac('sha256', $m[1] . '.' . $body, $secret);
        return hash_equals($expected, $m[2]);
    }

    /**
     * The header value the gateway would send for a body at a moment.
     *
     * Plugins never need it; the mock gateway and the tests do, to produce deliveries the
     * way the real gateway does.
     *
     * @param string $secret    The key's webhook secret.
     * @param string $body      The exact bytes of the body.
     * @param int    $timestamp The signing moment, in unix seconds.
     * @return string `t=<timestamp>,v1=<64 hex characters>`.
     */
    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
