<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ConnectorAccount
{
    private const TABLE_USERS = 'be_users';

    private const TABLE_GROUPS = 'be_groups';

    private const NAME_PREFIX = 'ai_connector_';

    private const TITLE_PREFIX = 'AI connector: ';

    private const DESCRIPTION = 'Created by the AI connector for an AI client connection. Removed on disconnect; do not edit.';

    private const PASSWORD_LENGTH = 64;

    private const MOUNT_FROM_GROUPS = 3;

    private const WORKSPACE_LIVE = 1;

    private const CONTENT_TYPE_PREFIX = 'tt_content:CType:';

    private const LIST_SEPARATOR = ',';

    private const MAX_USERNAME_LENGTH = 50;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PasswordHashFactory $passwordHashFactory,
        private readonly Random $random,
    ) {}

    /**
     * @return array{userId: int, groupId: int}
     */
    public function create(SiteDetails $site, ConnectorRights $rights): array
    {
        $now = time();
        $groups = $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS);
        $groups->insert(self::TABLE_GROUPS, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'title' => self::TITLE_PREFIX . $site->identifier,
            'description' => self::DESCRIPTION,
            'db_mountpoints' => (string)$site->rootPageId,
            'workspace_perms' => self::WORKSPACE_LIVE,
        ] + $this->rightsFields($rights));
        $groupId = (int)$groups->lastInsertId();

        $users = $this->connectionPool->getConnectionForTable(self::TABLE_USERS);
        $users->insert(self::TABLE_USERS, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'username' => $this->username($site->identifier),
            'password' => $this->passwordHashFactory->getDefaultHashInstance('BE')->getHashedPassword($this->random->generateRandomHexString(self::PASSWORD_LENGTH)),
            'description' => self::DESCRIPTION,
            'usergroup' => (string)$groupId,
            'admin' => 0,
            'options' => self::MOUNT_FROM_GROUPS,
            'workspace_perms' => self::WORKSPACE_LIVE,
        ]);

        return ['userId' => (int)$users->lastInsertId(), 'groupId' => $groupId];
    }

    public function updateRights(int $groupId, ConnectorRights $rights): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS)->update(
            self::TABLE_GROUPS,
            ['tstamp' => time()] + $this->rightsFields($rights),
            ['uid' => $groupId]
        );
    }

    public function exists(int $userId, int $groupId): bool
    {
        $user = $this->connectionPool->getConnectionForTable(self::TABLE_USERS)
            ->count('uid', self::TABLE_USERS, ['uid' => $userId, 'deleted' => 0]);
        $group = $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS)
            ->count('uid', self::TABLE_GROUPS, ['uid' => $groupId, 'deleted' => 0]);

        return $user > 0 && $group > 0;
    }

    public function remove(int $userId, int $groupId): void
    {
        if ($userId > 0) {
            $this->connectionPool->getConnectionForTable(self::TABLE_USERS)->delete(self::TABLE_USERS, ['uid' => $userId]);
        }

        if ($groupId > 0) {
            $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS)->delete(self::TABLE_GROUPS, ['uid' => $groupId]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function rightsFields(ConnectorRights $rights): array
    {
        return [
            'tables_select' => implode(self::LIST_SEPARATOR, $rights->tablesSelect),
            'tables_modify' => implode(self::LIST_SEPARATOR, $rights->tablesModify),
            'non_exclude_fields' => implode(self::LIST_SEPARATOR, $rights->nonExcludeFields),
            'explicit_allowdeny' => implode(self::LIST_SEPARATOR, array_map(
                static fn(string $type): string => self::CONTENT_TYPE_PREFIX . $type,
                $rights->contentTypes
            )),
        ];
    }

    private function username(string $siteIdentifier): string
    {
        $slug = strtolower((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $siteIdentifier));

        return mb_substr(self::NAME_PREFIX . $slug, 0, self::MAX_USERNAME_LENGTH);
    }
}
