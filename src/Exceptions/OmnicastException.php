<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * OmnicastException
 *
 * Thrown for API errors, timeout failures, missing configuration,
 * JWT signing issues, or webhook verification errors.
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
     * @param  string  $message  Human-readable error message.
     * @param  int  $code  Internal exception code (default 0).
     * @param  int|null  $httpStatusCode  HTTP status code from the API response.
     * @param  string|null  $responseBody  Raw response body from the API.
     * @param  Throwable|null  $previous  Previous exception for chaining.
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
        $this->responseBody = $responseBody;
    }

    /**
     * Create an exception for a missing or null configuration value.
     */
    public static function missingConfiguration(string $key): self
    {
        return new self(
            message: "OmniCast configuration key [{$key}] is missing or empty. Please set the corresponding environment variable.",
            code: 0,
        );
    }

    /**
     * Create an exception based on HTTP response status code.
     */
    public static function fromResponse(string $endpoint, int $httpStatusCode, string $responseBody = ''): self
    {
        return match ($httpStatusCode) {
            401 => self::unauthorized($endpoint, $responseBody),
            403 => self::forbidden("OmniCast API request to [{$endpoint}] was forbidden: {$responseBody}"),
            404 => self::notFound($endpoint, $responseBody),
            409 => self::conflict($endpoint, $responseBody),
            503 => self::serverDraining($endpoint, $responseBody),
            default => self::requestFailed($endpoint, $httpStatusCode, $responseBody),
        };
    }

    /**
     * Create an exception for a generic failed HTTP API request.
     */
    public static function requestFailed(
        string $endpoint,
        int $httpStatusCode,
        string $responseBody = '',
    ): self {
        return new self(
            message: "OmniCast API request to [{$endpoint}] failed with HTTP status [{$httpStatusCode}].",
            code: $httpStatusCode,
            httpStatusCode: $httpStatusCode,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for 401 Unauthorized.
     */
    public static function unauthorized(string $endpoint, string $responseBody = ''): self
    {
        return new self(
            message: "OmniCast API request to [{$endpoint}] was unauthorized (401). Check API key and secret.",
            code: 401,
            httpStatusCode: 401,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for 403 Forbidden.
     */
    public static function forbidden(string $message = 'Access forbidden'): self
    {
        return new self(
            message: $message,
            code: 403,
            httpStatusCode: 403,
        );
    }

    /**
     * Create an exception for 404 Not Found.
     */
    public static function notFound(string $endpoint, string $responseBody = ''): self
    {
        return new self(
            message: "OmniCast resource at [{$endpoint}] was not found (404).",
            code: 404,
            httpStatusCode: 404,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for 409 Conflict.
     */
    public static function conflict(string $endpoint, string $responseBody = ''): self
    {
        return new self(
            message: "OmniCast resource conflict at [{$endpoint}] (409): {$responseBody}",
            code: 409,
            httpStatusCode: 409,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for 503 Server Draining / Service Unavailable.
     */
    public static function serverDraining(string $endpoint, string $responseBody = ''): self
    {
        return new self(
            message: "OmniCast server is currently draining connections and unavailable for [{$endpoint}] (503).",
            code: 503,
            httpStatusCode: 503,
            responseBody: $responseBody,
        );
    }

    /**
     * Create an exception for invalid webhook signature.
     */
    public static function invalidWebhookSignature(string $message = 'Invalid OmniCast webhook signature.'): self
    {
        return new self(
            message: $message,
            code: 403,
            httpStatusCode: 403,
        );
    }

    /**
     * Create an exception for a connection timeout.
     */
    public static function timeout(string $endpoint, ?Throwable $previous = null): self
    {
        return new self(
            message: "OmniCast API request to [{$endpoint}] timed out.",
            code: 408,
            httpStatusCode: 408,
            previous: $previous,
        );
    }

    /**
     * Create an exception for a JWT signing / encoding failure.
     */
    public static function jwtError(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            message: "OmniCast JWT token generation failed: {$reason}",
            code: 0,
            previous: $previous,
        );
    }

    /**
     * Create an exception for an unexpected / network-level connection error.
     */
    public static function connectionError(string $endpoint, ?Throwable $previous = null): self
    {
        return new self(
            message: "OmniCast SDK could not connect to [{$endpoint}]. Check your OMNICAST_BASE_URL and network connectivity.",
            code: 0,
            previous: $previous,
        );
    }

    // -------------------------------------------------------------------------
    // Query Helpers
    // -------------------------------------------------------------------------

    public function isNotFound(): bool
    {
        return $this->httpStatusCode === 404;
    }

    public function isConflict(): bool
    {
        return $this->httpStatusCode === 409;
    }

    public function isDraining(): bool
    {
        return $this->httpStatusCode === 503;
    }

    public function isUnauthorized(): bool
    {
        return $this->httpStatusCode === 401;
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
