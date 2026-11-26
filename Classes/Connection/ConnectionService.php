<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

use Psr\Log\LoggerInterface;
use Solution25\AiConnector\Gateway\GatewayClient;
use Solution25\AiConnector\Gateway\GatewayException;
use Solution25\AiConnector\Gateway\GatewayProtocol;
use Solution25\AiConnector\Gateway\PairingRequest;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

class ConnectionService
{
    public const TOKEN_PREFIX = 't3ai_';

    public const TOKEN_HASH_ALGORITHM = 'sha256';

    private const CODE_PATTERN = '/^S25-[2-9A-HJKMNP-TV-Z]{4}-[2-9A-HJKMNP-TV-Z]{4}$/';

    private const TOKEN_LENGTH = 64;

    private const LOCK_ID = 'ai_connector_connection';

    private const ACCESS_LEVEL = 'Custom';

    private const MAX_PERMISSIONS = 200;

    public function __construct(
        private readonly GatewayClient $gatewayClient,
        private readonly GatewayProtocol $protocol,
        private readonly ConnectionRepository $connections,
        private readonly ConnectorAccount $account,
        private readonly AuditTrail $auditTrail,
        private readonly LockFactory $lockFactory,
        private readonly Random $random,
        private readonly LoggerInterface $logger,
    ) {}

    public static function hashToken(string $token): string
    {
        return hash(self::TOKEN_HASH_ALGORITHM, $token);
    }

    public function request(string $code): PairingRequest
    {
        return $this->ownRequest($this->gatewayClient->pairingRequest($this->validCode($code)));
    }

    private function ownRequest(PairingRequest $request): PairingRequest
    {
        $own = array_values(array_filter($request->permissions, $this->protocol->isGrantable(...)));

        if ($own === []) {
            throw GatewayException::otherPlatformCode($this->gatewayClient->mcpUrl());
        }

        return new PairingRequest($request->clientName, $own, $request->startedAt, $request->verifiedClient, $request->returnHost);
    }

    /**
     * @param list<string> $permissions
     */
    public function connect(SiteDetails $site, string $code, array $permissions, int $backendUserId): void
    {
        try {
            $this->exclusively(fn() => $this->link($site, $code, $permissions, $backendUserId));
        } catch (GatewayException $e) {
            $this->auditTrail->record($site->identifier, AuditTrail::ACTION_CONNECT, AuditTrail::OUTCOME_FAILURE, $backendUserId, $e->reason);

            throw $e;
        }
    }

    public function disconnect(string $siteIdentifier, int $backendUserId): void
    {
        $this->exclusively(fn() => $this->unlink($siteIdentifier, $backendUserId));
    }

    /**
     * @param list<string> $permissions
     */
    public function changePermissions(string $siteIdentifier, array $permissions, int $backendUserId): void
    {
        try {
            $this->exclusively(fn() => $this->applyPermissions($siteIdentifier, $permissions, $backendUserId));
        } catch (GatewayException $e) {
            if ($e->reason === GatewayException::REASON_PERMISSIONS_NOT_SYNCED) {
                throw $e;
            }

            $this->auditTrail->record($siteIdentifier, AuditTrail::ACTION_PERMISSIONS, AuditTrail::OUTCOME_FAILURE, $backendUserId, $e->reason);

            throw $e;
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function link(SiteDetails $site, string $code, array $permissions, int $backendUserId): void
    {
        $code = $this->validCode($code);
        $permissions = $this->grantable($permissions);

        if ($this->connections->findBySite($site->identifier) !== null) {
            throw GatewayException::alreadyConnected();
        }

        $request = $this->ownRequest($this->gatewayClient->pairingRequest($code));

        if (array_diff($permissions, $request->permissions) !== []) {
            throw GatewayException::requestChanged();
        }

        $gatewayUrl = $this->gatewayClient->gatewayUrl();
        $rights = $this->protocol->rights($permissions);
        $token = self::TOKEN_PREFIX . $this->random->generateRandomHexString(self::TOKEN_LENGTH);
        $paired = null;

        try {
            $this->connections->database()->transactional(function () use ($site, $code, $permissions, $backendUserId, $request, $gatewayUrl, $rights, $token, &$paired): void {
                $account = $this->account->create($site, $rights);
                $paired = $this->gatewayClient->completePairing(
                    $this->protocol->pairingPayload($code, $site, $token, self::ACCESS_LEVEL, $permissions)
                );
                $this->connections->insert([
                    'site_identifier' => $site->identifier,
                    'site_url' => $site->url,
                    'gateway_url' => $gatewayUrl,
                    'gateway_connection_id' => $paired['connectionId'],
                    'connection_secret' => $paired['connectionSecret'],
                    'client_name' => $request->clientName,
                    'access_level' => self::ACCESS_LEVEL,
                    'permissions' => $permissions,
                    'requested_permissions' => $request->permissions,
                    'token_hash' => self::hashToken($token),
                    'be_user' => $account['userId'],
                    'be_group' => $account['groupId'],
                    'connected_at' => time(),
                    'connected_by' => $backendUserId,
                ]);
            });
        } catch (\Throwable $e) {
            if ($paired !== null) {
                $this->tellGatewayQuietly($gatewayUrl, $paired['connectionId'], $paired['connectionSecret']);
            }

            throw $e instanceof GatewayException ? $e : GatewayException::unreachable();
        }

        $this->auditTrail->record($site->identifier, AuditTrail::ACTION_CONNECT, AuditTrail::OUTCOME_SUCCESS, $backendUserId, '', [
            'client' => $request->clientName,
            'permissions' => $permissions,
        ]);
    }

    /**
     * @param list<string> $permissions
     */
    private function applyPermissions(string $siteIdentifier, array $permissions, int $backendUserId): void
    {
        $connection = $this->connections->findBySite($siteIdentifier);

        if ($connection === null) {
            throw GatewayException::notConnected();
        }

        $permissions = $this->grantable($permissions);
        $requested = $connection['requested_permissions'] !== [] ? $connection['requested_permissions'] : $connection['permissions'];

        if (array_diff($permissions, $requested) !== []) {
            throw GatewayException::invalidPermissions();
        }

        $this->connections->database()->transactional(function () use ($connection, $permissions, $requested): void {
            $this->account->updateRights((int)$connection['be_group'], $this->protocol->rights($permissions));
            $this->connections->update((int)$connection['uid'], ['permissions' => $permissions, 'requested_permissions' => $requested]);
        });

        try {
            $this->gatewayClient->updatePermissions(
                (string)$connection['gateway_url'],
                (string)$connection['gateway_connection_id'],
                (string)$connection['connection_secret'],
                (string)$connection['access_level'],
                $permissions
            );
        } catch (GatewayException $e) {
            $this->auditTrail->record($siteIdentifier, AuditTrail::ACTION_PERMISSIONS, AuditTrail::OUTCOME_GATEWAY_NOT_TOLD, $backendUserId, $e->reason, ['permissions' => $permissions]);

            throw GatewayException::permissionsNotSynced();
        }

        $this->auditTrail->record($siteIdentifier, AuditTrail::ACTION_PERMISSIONS, AuditTrail::OUTCOME_SUCCESS, $backendUserId, '', ['permissions' => $permissions]);
    }

    private function unlink(string $siteIdentifier, int $backendUserId): void
    {
        $connection = $this->connections->findBySite($siteIdentifier);

        if ($connection === null) {
            throw GatewayException::notConnected();
        }

        $this->connections->database()->transactional(function () use ($connection): void {
            $this->account->remove((int)$connection['be_user'], (int)$connection['be_group']);
            $this->connections->delete((int)$connection['uid']);
        });

        try {
            $this->gatewayClient->disconnect(
                (string)$connection['gateway_url'],
                (string)$connection['gateway_connection_id'],
                (string)$connection['connection_secret']
            );
        } catch (GatewayException $e) {
            $this->auditTrail->record($siteIdentifier, AuditTrail::ACTION_DISCONNECT, AuditTrail::OUTCOME_GATEWAY_NOT_TOLD, $backendUserId, $e->reason);

            return;
        }

        $this->auditTrail->record($siteIdentifier, AuditTrail::ACTION_DISCONNECT, AuditTrail::OUTCOME_SUCCESS, $backendUserId);
    }

    private function tellGatewayQuietly(string $gatewayUrl, string $connectionId, string $connectionSecret): void
    {
        try {
            $this->gatewayClient->disconnect($gatewayUrl, $connectionId, $connectionSecret);
        } catch (GatewayException $e) {
            $this->logger->notice('The connector server was not told about a failed connection: {reason}', ['reason' => $e->reason]);
        }
    }

    /**
     * @param list<mixed> $permissions
     *
     * @return list<string>
     */
    private function grantable(array $permissions): array
    {
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        sort($permissions);

        if ($permissions === [] || count($permissions) > self::MAX_PERMISSIONS) {
            throw GatewayException::invalidPermissions();
        }

        foreach ($permissions as $permission) {
            if (!$this->protocol->isGrantable($permission)) {
                throw GatewayException::invalidPermissions();
            }
        }

        return $permissions;
    }

    public function validCode(string $code): string
    {
        $code = strtoupper((string)preg_replace('/\s+/', '', $code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw GatewayException::invalidCode();
        }

        return $code;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function exclusively(\Closure $work): mixed
    {
        $lock = $this->lockFactory->createLocker(
            self::LOCK_ID,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );

        try {
            if (!$lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                throw GatewayException::busy();
            }
        } catch (GatewayException $e) {
            throw $e;
        } catch (\Throwable) {
            throw GatewayException::busy();
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
