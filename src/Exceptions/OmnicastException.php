<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * OmnicastException
 *
 * Thrown for API errors, timeout failures, missing configuration,
 * or JWT signing issues in the OmniCast Laravel SDK.
 */
class OmnicastException extends RuntimeException
{
    /**
     * The HTTP status code returned by the OmniCast API, if applicable.
     */
    private readonly ?int $httpStatusCode;

    /**
     * The raw response body from the API, if applicable.
     */
    private readonly ?string $responseBody;

    /**
     * @param string         $message        Human-readable error message.
     * @param int            $code           Internal exception code (default 0).
     * @param int|null       $httpStatusCode HTTP status code from the API response.
     * @param string|null    $responseBody   Raw response body from the API.
     * @param Throwable|null $previous       Previous exception for chaining.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?int $httpStatusCode = null,
        ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->httpStatusCode = $httpStatusCode;
        $this->responseBody   = $responseBody;
    }

    /**
     * Create an exception for a missing or null configuration value.
     */
    public static function missingConfiguration(string $key): static
    {
        return new static(
            message: "OmniCast configuration key [{$key}] is missing or empty. "
                   . 'Please set the corresponding environment variable.',
            code: 0,
        );
    }

    /**
     * Create an exception for a failed HTTP API request.
     */
    public static function requestFailed(
        string $endpoint,
        int $httpStatusCode,
        string $responseBody = '',
    ): static {
        return new static(
            message: "OmniCast API request to [{$endpoint}] failed with HTTP status [{$httpStatusCode}].",
            code: $httpStatusCode,
            httpStatusCode: $httpStatusCode,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for a connection timeout.
     */
    public static function timeout(string $endpoint, ?Throwable $previous = null): static
    {
        return new static(
            message: "OmniCast API request to [{$endpoint}] timed out.",
            code: 408,
            httpStatusCode: 408,
            previous: $previous,
        );
    }

    /**
     * Create an exception for a JWT signing / encoding failure.
     */
    public static function jwtError(string $reason, ?Throwable $previous = null): static
    {
        return new static(
            message: "OmniCast JWT token generation failed: {$reason}",
            code: 0,
            previous: $previous,
        );
    }

    /**
     * Create an exception for an unexpected / network-level connection error.
     */
    public static function connectionError(string $endpoint, ?Throwable $previous = null): static
    {
        return new static(
            message: "OmniCast SDK could not connect to [{$endpoint}]. "
                   . 'Check your OMNICAST_API_URL and network connectivity.',
            code: 0,
            previous: $previous,
        );
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Returns the HTTP status code from the API response (null if N/A).
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Returns the raw response body from the API (null if N/A).
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
