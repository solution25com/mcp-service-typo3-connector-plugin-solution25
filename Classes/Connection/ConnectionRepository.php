<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ConnectionRepository
{
    public const TABLE = 'tx_aiconnector_connection';

    private const LIST_FIELDS = ['permissions', 'requested_permissions'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function database(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySite(string $siteIdentifier): ?array
    {
        $row = $this->database()->select(['*'], self::TABLE, ['site_identifier' => $siteIdentifier])->fetchAssociative();

        return $row === false ? null : $this->decode($row);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by site identifier
     */
    public function findAll(): array
    {
        $connections = [];

        foreach ($this->database()->select(['*'], self::TABLE)->fetchAllAssociative() as $row) {
            $connections[(string)$row['site_identifier']] = $this->decode($row);
        }

        return $connections;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        $this->database()->insert(self::TABLE, $this->encode($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $uid, array $data): void
    {
        $this->database()->update(self::TABLE, $this->encode($data), ['uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $this->database()->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (self::LIST_FIELDS as $field) {
            $list = json_decode((string)($row[$field] ?? '[]'), true);
            $row[$field] = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function encode(array $data): array
    {
        foreach (self::LIST_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = json_encode(array_values($data[$field] ?? []), \JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}
