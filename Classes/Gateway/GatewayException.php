<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

final class GatewayException extends \RuntimeException
{
    public const REASON_INVALID_CODE = 'invalid_code';
    public const REASON_INVALID_GATEWAY_URL = 'invalid_gateway_url';
    public const REASON_UNREACHABLE = 'gateway_unreachable';
    public const REASON_INVALID_PERMISSIONS = 'invalid_permissions';
    public const REASON_REQUEST_CHANGED = 'request_changed';
    public const REASON_OTHER_PLATFORM = 'other_platform';
    public const REASON_BUSY = 'connection_busy';
    public const REASON_ALREADY_CONNECTED = 'already_connected';
    public const REASON_NOT_CONNECTED = 'not_connected';
    public const REASON_INVALID_SITE_URL = 'invalid_site_url';
    public const REASON_GATEWAY_ERROR = 'gateway_error';
    public const REASON_PERMISSIONS_NOT_SYNCED = 'permissions_not_synced';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(self::REASON_INVALID_CODE, 'A connection code looks like S25-XXXX-XXXX. Copy it again from the sign-in page opened by your AI client.');
    }

    public static function invalidGatewayUrl(): self
    {
        return new self(self::REASON_INVALID_GATEWAY_URL, 'This connection uses an unsupported connector server. Disconnect it and connect again.');
    }

    public static function unreachable(): self
    {
        return new self(self::REASON_UNREACHABLE, 'The Solution25 connector server could not be reached. Try again later.');
    }

    public static function invalidPermissions(): self
    {
        return new self(self::REASON_INVALID_PERMISSIONS, 'Choose at least one thing the AI client may do on this site, out of what it asked for.');
    }

    public static function otherPlatformCode(string $mcpUrl): self
    {
        return new self(self::REASON_OTHER_PLATFORM, sprintf('This code asks for no TYPO3 permissions; it was created through a connector address for another platform. In your AI client add the connector %s, sign in there and paste the new code.', $mcpUrl));
    }

    public static function requestChanged(): self
    {
        return new self(self::REASON_REQUEST_CHANGED, 'The AI client changed what it asks for. Check the code again and review the permissions.');
    }

    public static function busy(): self
    {
        return new self(self::REASON_BUSY, 'Another change to the AI connection is in progress. Wait a moment and try again.');
    }

    public static function alreadyConnected(): self
    {
        return new self(self::REASON_ALREADY_CONNECTED, 'This site is already connected. Disconnect it before connecting another AI client.');
    }

    public static function notConnected(): self
    {
        return new self(self::REASON_NOT_CONNECTED, 'This site has no AI connection. Reload the page.');
    }

    public static function invalidSiteUrl(): self
    {
        return new self(self::REASON_INVALID_SITE_URL, 'The site has to be reachable over https:// on its own domain. Check the site configuration base URL, or open the backend through the site\'s public https address.');
    }

    public static function permissionsNotSynced(): self
    {
        return new self(self::REASON_PERMISSIONS_NOT_SYNCED, 'The new permissions apply on this site, but the connector server could not be told. Save them again later so the AI client shows the right tools.');
    }

    public static function rejected(string $reason, string $message, int $status): self
    {
        return new self($reason, $message, $status);
    }
}
