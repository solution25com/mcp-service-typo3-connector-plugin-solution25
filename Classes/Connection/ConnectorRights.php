<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

final class ConnectorRights
{
    public const PERMISSION_PAGE_VIEW = 'page.viewer';
    public const PERMISSION_PAGE_EDIT = 'page.editor';
    public const PERMISSION_CONTENT_VIEW = 'content.viewer';
    public const PERMISSION_CONTENT_CREATE = 'content.creator';
    public const PERMISSION_CONTENT_EDIT = 'content.editor';
    public const PERMISSION_SEO_EDIT = 'seo.editor';

    public const TYPO3_PERMISSIONS = [
        self::PERMISSION_PAGE_VIEW,
        self::PERMISSION_PAGE_EDIT,
        self::PERMISSION_CONTENT_VIEW,
        self::PERMISSION_CONTENT_CREATE,
        self::PERMISSION_CONTENT_EDIT,
        self::PERMISSION_SEO_EDIT,
    ];

    public const CONTENT_TYPES = ['header', 'text', 'textpic', 'textmedia', 'bullets', 'table'];

    private const TABLE_PAGES = 'pages';

    private const TABLE_CONTENT = 'tt_content';

    private const CONTENT_FIELDS = ['tt_content:subheader'];

    private const CONTENT_HIDDEN_FIELD = 'tt_content:hidden';

    private const PAGE_FIELDS = ['pages:nav_title'];

    private const SEO_FIELDS = ['pages:seo_title', 'pages:description'];

    /**
     * @param list<string> $tablesSelect
     * @param list<string> $tablesModify
     * @param list<string> $nonExcludeFields "table:field"
     * @param list<string> $contentTypes     CType values the connector may create or edit
     */
    public function __construct(
        public readonly array $tablesSelect,
        public readonly array $tablesModify,
        public readonly array $nonExcludeFields,
        public readonly array $contentTypes,
    ) {}

    /**
     * @param list<string> $permissions keys from TYPO3_PERMISSIONS
     */
    public static function forPermissions(array $permissions): self
    {
        $has = static fn(string $permission): bool => in_array($permission, $permissions, true);
        $tablesModify = [];
        $fields = [];
        $contentTypes = [];

        if ($has(self::PERMISSION_CONTENT_CREATE) || $has(self::PERMISSION_CONTENT_EDIT)) {
            $tablesModify[] = self::TABLE_CONTENT;
            $fields = [...$fields, ...self::CONTENT_FIELDS];
            $contentTypes = self::CONTENT_TYPES;
        }

        if ($has(self::PERMISSION_CONTENT_CREATE)) {
            $fields[] = self::CONTENT_HIDDEN_FIELD;
        }

        if ($has(self::PERMISSION_PAGE_EDIT)) {
            $tablesModify[] = self::TABLE_PAGES;
            $fields = [...$fields, ...self::PAGE_FIELDS];
        }

        if ($has(self::PERMISSION_SEO_EDIT)) {
            $tablesModify[] = self::TABLE_PAGES;
            $fields = [...$fields, ...self::SEO_FIELDS];
        }

        return new self(
            [self::TABLE_PAGES, self::TABLE_CONTENT],
            array_values(array_unique($tablesModify)),
            array_values(array_unique($fields)),
            $contentTypes,
        );
    }
}
