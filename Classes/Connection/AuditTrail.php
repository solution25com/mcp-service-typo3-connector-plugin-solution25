<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

use TYPO3\CMS\Core\Database\ConnectionPool;

class AuditTrail
{
    public const TABLE = 'tx_aiconnector_audit';

    public const ACTION_CONNECT = 'connect';

    public const ACTION_DISCONNECT = 'disconnect';

    public const ACTION_PERMISSIONS = 'permissions';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const OUTCOME_GATEWAY_NOT_TOLD = 'gateway_not_told';

    private const RECENT_LIMIT = 20;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public function record(string $siteIdentifier, string $action, string $outcome, int $backendUserId, string $reason = '', array $details = []): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'crdate' => time(),
            'site_identifier' => $siteIdentifier,
            'action' => $action,
            'outcome' => $outcome,
            'reason' => $reason,
            'be_user' => $backendUserId,
            'details' => json_encode($details, \JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('audit.*', 'user.username')
            ->from(self::TABLE, 'audit')
            ->leftJoin('audit', 'be_users', 'user', $queryBuilder->expr()->eq('user.uid', $queryBuilder->quoteIdentifier('audit.be_user')))
            ->orderBy('audit.crdate', 'DESC')
            ->addOrderBy('audit.uid', 'DESC')
            ->setMaxResults(self::RECENT_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
