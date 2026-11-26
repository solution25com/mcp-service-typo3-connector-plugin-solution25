<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

final class ApiException extends \RuntimeException
{
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_METHOD_NOT_ALLOWED = 405;
    public const STATUS_PAYLOAD_TOO_LARGE = 413;
    public const STATUS_UNPROCESSABLE = 422;

    private function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(self::STATUS_UNAUTHORIZED, 'unauthorized', 'Missing or unknown API token.');
    }

    public static function notFound(string $what = 'route'): self
    {
        return new self(self::STATUS_NOT_FOUND, 'not_found', sprintf('The %s does not exist or is not part of this site.', $what));
    }

    public static function methodNotAllowed(): self
    {
        return new self(self::STATUS_METHOD_NOT_ALLOWED, 'method_not_allowed', 'This route does not accept this HTTP method.');
    }

    public static function forbidden(string $message): self
    {
        return new self(self::STATUS_FORBIDDEN, 'forbidden', $message);
    }

    public static function invalidBody(string $message): self
    {
        return new self(self::STATUS_BAD_REQUEST, 'invalid_request', $message);
    }

    public static function payloadTooLarge(): self
    {
        return new self(self::STATUS_PAYLOAD_TOO_LARGE, 'payload_too_large', 'The request body is too large.');
    }

    public static function rejected(string $message): self
    {
        return new self(self::STATUS_UNPROCESSABLE, 'rejected', $message);
    }
}
