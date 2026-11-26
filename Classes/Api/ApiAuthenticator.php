<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

use Psr\Http\Message\ServerRequestInterface;
use Solution25\AiConnector\Connection\ConnectionService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ApiAuthenticator
{
    private const BEARER_PREFIX = 'Bearer ';

    private const CONNECTION_TABLE = 'tx_aiconnector_connection';

    private const USERS_TABLE = 'be_users';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function authenticate(ServerRequestInterface $request): ApiContext
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, self::BEARER_PREFIX)) {
            throw ApiException::unauthorized();
        }

        $token = trim(substr($header, strlen(self::BEARER_PREFIX)));

        if (!str_starts_with($token, ConnectionService::TOKEN_PREFIX)) {
            throw ApiException::unauthorized();
        }

        $connection = $this->connectionPool->getConnectionForTable(self::CONNECTION_TABLE)
            ->select(['*'], self::CONNECTION_TABLE, ['token_hash' => ConnectionService::hashToken($token)])
            ->fetchAssociative();

        if ($connection === false) {
            throw ApiException::unauthorized();
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier((string)$connection['site_identifier']);
        } catch (SiteNotFoundException) {
            throw ApiException::unauthorized();
        }

        return new ApiContext($connection, $site, $this->connectorUser((int)$connection['be_user']));
    }

    private function connectorUser(int $userId): BackendUserAuthentication
    {
        $record = $this->connectionPool->getConnectionForTable(self::USERS_TABLE)
            ->select(['uid', 'admin', 'disable', 'deleted'], self::USERS_TABLE, ['uid' => $userId])
            ->fetchAssociative();

        if ($record === false || (int)$record['admin'] !== 0 || (int)$record['disable'] !== 0 || (int)$record['deleted'] !== 0) {
            throw ApiException::unauthorized();
        }

        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($userId);

        if (empty($user->user['uid'])) {
            throw ApiException::unauthorized();
        }

        $user->fetchGroupData();

        return $user;
    }
}
