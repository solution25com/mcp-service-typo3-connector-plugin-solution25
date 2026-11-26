<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

use Solution25\AiConnector\Connection\ConnectorRights;
use Solution25\AiConnector\Connection\SiteDetails;

interface GatewayProtocol
{
    public function isGrantable(string $permission): bool;

    /**
     * @param list<string> $permissions
     */
    public function rights(array $permissions): ConnectorRights;

    /**
     * @param list<string> $permissions
     *
     * @return array<string, mixed>
     */
    public function pairingPayload(string $code, SiteDetails $site, string $apiToken, string $accessLevel, array $permissions): array;
}
