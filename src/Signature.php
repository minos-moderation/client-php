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
 * `ContractDocumentTest` runs the contract's copy of it and this one on the same cases, and
 * `SignatureTest` checks this one on the gateway's vector (`vectors/signature.json`).
 * {@see check} gives the same answer with its reason, for the administrator's log. The class
 * has no dependencies and no PHP 8 syntax, because it runs inside plugins on customers'
 * hosts. An empty secret is the caller's to refuse, before either is called (README).
 */
final class Signature
{
    /** The header the gateway signs with (a wire label — never renamed). */
    public const HEADER = 'X-Wergiliusz-Podpis';

    /** How far, in seconds, a delivery's timestamp may be from the receiver's clock. */
    public const DEFAULT_TOLERANCE_S = 300;

    /** {@see check}: the signature is valid. */
    public const OK = 'ok';

    /** {@see check}: the header is not `t=<digits>,v1=<64 lower-case hex>` (once trimmed). */
    public const MALFORMED = 'malformed';

    /** {@see check}: the timestamp is further than the tolerance from the receiver's clock. */
    public const STALE = 'stale';

    /** {@see check}: the MAC does not match: another secret, or another body. */
    public const MISMATCH = 'mismatch';

    /** The header's form: the timestamp and the MAC. */
    private const FORMAT = '/^t=(\d+),v1=([0-9a-f]{64})$/';

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
     * Why a delivery's signature is valid or not: the answer of {@see verify} with its
     * reason, checked in the same order (the header's form, the timestamp, the MAC compared
     * in constant time). `check(...) === Signature::OK` exactly when `verify(...)` is true.
     *
     * The reason is for the forum administrator's log, to tell a clock that drifted from a
     * wrong secret. It never goes into the HTTP answer: the gateway, or anyone else sending
     * to the receiver, gets the same `401` whatever the reason.
     *
     * @param string $secret     The key's webhook secret.
     * @param string $header     The value of the `X-Wergiliusz-Podpis` header.
     * @param string $body       The EXACT bytes of the request body, before any parsing.
     * @param int    $now        The receiver's clock, in unix seconds.
     * @param int    $toleranceS The largest accepted distance between the two clocks.
     * @return string {@see OK}, {@see MALFORMED}, {@see STALE} or {@see MISMATCH}.
     */
    public static function check(
        string $secret,
        string $header,
        string $body,
        int $now,
        int $toleranceS = self::DEFAULT_TOLERANCE_S
    ): string {
        if (!preg_match(self::FORMAT, trim($header), $m)) {
            return self::MALFORMED;
        }
        if (abs($now - (int)$m[1]) > $toleranceS) {
            return self::STALE;
        }
        $expected = hash_hmac('sha256', $m[1] . '.' . $body, $secret);
        return hash_equals($expected, $m[2]) ? self::OK : self::MISMATCH;
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
