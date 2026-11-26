<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

use Solution25\AiConnector\Connection\ConnectorRights;
use Solution25\AiConnector\Connection\SiteDetails;

final class Typo3Protocol implements GatewayProtocol
{
    public const PLATFORM = 'typo3';

    public function isGrantable(string $permission): bool
    {
        return in_array($permission, ConnectorRights::TYPO3_PERMISSIONS, true);
    }

    public function rights(array $permissions): ConnectorRights
    {
        return ConnectorRights::forPermissions($permissions);
    }

    public function pairingPayload(string $code, SiteDetails $site, string $apiToken, string $accessLevel, array $permissions): array
    {
        return [
            'code' => $code,
            'shop' => [
                'platform' => self::PLATFORM,
                'url' => $site->url,
                'apiUrl' => $site->apiUrl,
                'name' => $site->name,
                'typo3Version' => $site->typo3Version,
                'extensionVersion' => $site->extensionVersion,
            ],
            'credentials' => [
                'apiToken' => $apiToken,
            ],
            'accessLevel' => $accessLevel,
            'permissions' => $permissions,
        ];
    }
}
