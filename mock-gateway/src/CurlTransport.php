<?php

declare(strict_types=1);

namespace Minos\Mock;

/**
 * The mock worker's HTTP client: one `POST`, no redirects, no proxy from the environment.
 */
final class CurlTransport
{
    /** @var int */
    private $timeoutS;

    /**
     * @param int $timeoutS The whole request's timeout, in seconds.
     */
    public function __construct(int $timeoutS)
    {
        $this->timeoutS = $timeoutS;
    }

    /**
     * Sends a delivery.
     *
     * @param string            $url     The webhook.
     * @param array<int,string> $headers Header lines.
     * @param string            $body    The body.
     * @return int The HTTP status, or 0 when there was no answer.
     */
    public function post(string $url, array $headers, string $body): int
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return 0;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_TIMEOUT        => $this->timeoutS,
        ]);
        $answered = curl_exec($handle) !== false;
        $status = $answered ? (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
        curl_close($handle);
        return $status;
    }
}
