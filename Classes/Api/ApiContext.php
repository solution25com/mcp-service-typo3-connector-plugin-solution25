<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

use Solution25\AiConnector\Connection\ConnectorRights;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ApiContext
{
    private const VIEW_PERMISSIONS = [
        'pages' => ConnectorRights::PERMISSION_PAGE_VIEW,
        'tt_content' => ConnectorRights::PERMISSION_CONTENT_VIEW,
    ];

    /**
     * @param array<string, mixed> $connection
     */
    public function __construct(
        public readonly array $connection,
        public readonly Site $site,
        public readonly BackendUserAuthentication $user,
    ) {}

    public function hasPermission(string $permission): bool
    {
        $permissions = json_decode((string)($this->connection['permissions'] ?? '[]'), true);

        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    public function canRead(string $table): bool
    {
        $permission = self::VIEW_PERMISSIONS[$table] ?? null;

        return $permission !== null && $this->hasPermission($permission) && $this->user->check('tables_select', $table);
    }

    public function canModify(string $table): bool
    {
        return $this->user->check('tables_modify', $table);
    }

    public function canEditField(string $table, string $field): bool
    {
        $column = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;

        if (!is_array($column)) {
            return false;
        }

        if (!($column['exclude'] ?? false)) {
            return true;
        }

        return $this->user->check('non_exclude_fields', $table . ':' . $field);
    }
}
