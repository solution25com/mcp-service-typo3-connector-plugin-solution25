<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

class PageReader
{
    public const TABLE_PAGES = 'pages';

    public const TABLE_CONTENT = 'tt_content';

    public const DEFAULT_LANGUAGE = 0;

    private const ALL_LANGUAGES = -1;

    private const LIVE_WORKSPACE = 0;

    private const MAX_TREE_DEPTH = 10;

    private const MAX_TREE_PAGES = 1000;

    private const MAX_SITE_PAGES = 5000;

    private const MAX_SEARCH_RESULTS = 50;

    private const MIN_SEARCH_LENGTH = 2;

    private const MAX_SEARCH_LENGTH = 100;

    private const SNIPPET_LENGTH = 200;

    private const PAGE_FIELDS = ['uid', 'pid', 'title', 'nav_title', 'slug', 'doktype', 'hidden', 'nav_hide', 'seo_title', 'description', 'tstamp'];

    private const TREE_FIELDS = ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'nav_hide'];

    private const CONTENT_FIELDS = ['uid', 'pid', 'CType', 'colPos', 'sorting', 'header', 'subheader', 'bodytext', 'hidden', 'tstamp'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function page(ApiContext $context, int $pageId): array
    {
        $this->assertCanRead($context, self::TABLE_PAGES);
        $page = $this->pageInSite($context, $pageId, self::PAGE_FIELDS);

        return [
            'page' => $this->formatPage($page),
            'content' => $context->canRead(self::TABLE_CONTENT) ? $this->contentOfPage($pageId) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tree(ApiContext $context, ?int $rootId, int $depth): array
    {
        $this->assertCanRead($context, self::TABLE_PAGES);
        $rootId ??= $context->site->getRootPageId();
        $root = $this->pageInSite($context, $rootId, self::TREE_FIELDS);
        $depth = max(0, min($depth, self::MAX_TREE_DEPTH));
        $count = 1;

        return ['tree' => $this->branch($root, $depth, $count, $this->otherSiteRoots($context)), 'truncated' => $count >= self::MAX_TREE_PAGES];
    }

    /**
     * @return array<string, mixed>
     */
    public function search(ApiContext $context, string $query, int $limit): array
    {
        $readPages = $context->canRead(self::TABLE_PAGES);
        $readContent = $context->canRead(self::TABLE_CONTENT);

        if (!$readPages && !$readContent) {
            throw ApiException::forbidden('This connection may not read pages or content.');
        }

        $query = trim($query);

        if (mb_strlen($query) < self::MIN_SEARCH_LENGTH || mb_strlen($query) > self::MAX_SEARCH_LENGTH) {
            throw ApiException::invalidBody(sprintf('Search for %d to %d characters.', self::MIN_SEARCH_LENGTH, self::MAX_SEARCH_LENGTH));
        }

        $limit = max(1, min($limit, self::MAX_SEARCH_RESULTS));
        $pageIds = $this->sitePageIds($context->site->getRootPageId(), $this->otherSiteRoots($context));
        $pages = $readPages ? $this->searchPages($pageIds, $query, $limit) : [];
        $content = $readContent ? $this->searchContent($pageIds, $query, $limit) : [];

        return ['query' => $query, 'pages' => $pages, 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    public function writtenPage(ApiContext $context, int $pageId): array
    {
        return $this->formatPage($this->pageInSite($context, $pageId, self::PAGE_FIELDS));
    }

    /**
     * @return array<string, mixed>
     */
    public function content(ApiContext $context, int $contentId): array
    {
        $this->assertCanRead($context, self::TABLE_CONTENT);

        return $this->contentInSite($context, $contentId);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    public function pageInSite(ApiContext $context, int $pageId, array $fields = self::PAGE_FIELDS): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_PAGES);
        $page = $queryBuilder
            ->select(...$fields)
            ->from(self::TABLE_PAGES)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($page === false || !$this->belongsToSite($context, $pageId)) {
            throw ApiException::notFound('page');
        }

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    public function contentInSite(ApiContext $context, int $contentId): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_CONTENT);
        $content = $queryBuilder
            ->select(...self::CONTENT_FIELDS)
            ->addSelect('sys_language_uid')
            ->from(self::TABLE_CONTENT)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($content === false
            || !in_array((int)$content['sys_language_uid'], [self::DEFAULT_LANGUAGE, self::ALL_LANGUAGES], true)
            || !$this->belongsToSite($context, (int)$content['pid'])) {
            throw ApiException::notFound('content element');
        }

        unset($content['sys_language_uid']);

        return $this->formatContent($content);
    }

    private function belongsToSite(ApiContext $context, int $pageId): bool
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId)->getIdentifier() === $context->site->getIdentifier();
        } catch (SiteNotFoundException) {
            return false;
        }
    }

    private function assertCanRead(ApiContext $context, string $table): void
    {
        if (!$context->canRead($table)) {
            throw ApiException::forbidden('This connection may not read ' . $table . '.');
        }
    }

    /**
     * @return list<int>
     */
    private function otherSiteRoots(ApiContext $context): array
    {
        $roots = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($site->getIdentifier() !== $context->site->getIdentifier()) {
                $roots[] = $site->getRootPageId();
            }
        }

        return $roots;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<int>            $excluded
     *
     * @return array<string, mixed>
     */
    private function branch(array $page, int $depth, int &$count, array $excluded): array
    {
        $node = $this->formatPage($page);
        $node['children'] = [];

        if ($depth === 0) {
            return $node;
        }

        foreach ($this->children([(int)$page['uid']], self::TREE_FIELDS, $excluded, self::MAX_TREE_PAGES - $count + 1) as $child) {
            if ($count >= self::MAX_TREE_PAGES) {
                break;
            }
            ++$count;
            $node['children'][] = $this->branch($child, $depth - 1, $count, $excluded);
        }

        return $node;
    }

    /**
     * @param list<int>    $parentIds
     * @param list<string> $fields
     * @param list<int>    $excluded
     *
     * @return list<array<string, mixed>>
     */
    private function children(array $parentIds, array $fields, array $excluded, int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_PAGES);
        $queryBuilder
            ->select(...$fields)
            ->from(self::TABLE_PAGES)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parentIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT))
            );

        if ($excluded !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($excluded, Connection::PARAM_INT_ARRAY)));
        }

        return $queryBuilder
            ->orderBy('sorting')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $excluded
     *
     * @return list<int>
     */
    private function sitePageIds(int $rootPageId, array $excluded): array
    {
        $all = [$rootPageId];
        $level = [$rootPageId];

        while ($level !== [] && count($all) < self::MAX_SITE_PAGES) {
            $level = array_map(static fn(array $row): int => (int)$row['uid'], $this->children($level, ['uid'], $excluded, self::MAX_SITE_PAGES - count($all)));
            $all = [...$all, ...$level];
        }

        return array_slice($all, 0, self::MAX_SITE_PAGES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentOfPage(int $pageId): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_CONTENT);
        $rows = $queryBuilder
            ->select(...self::CONTENT_FIELDS)
            ->from(self::TABLE_CONTENT)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([self::DEFAULT_LANGUAGE, self::ALL_LANGUAGES], Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->formatContent(...), $rows);
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchPages(array $pageIds, string $query, int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_PAGES);
        $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($query) . '%');
        $rows = $queryBuilder
            ->select(...self::TREE_FIELDS)
            ->from(self::TABLE_PAGES)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('title', $like),
                    $queryBuilder->expr()->like('nav_title', $like),
                    $queryBuilder->expr()->like('seo_title', $like),
                    $queryBuilder->expr()->like('description', $like)
                )
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->formatPage(...), $rows);
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchContent(array $pageIds, string $query, int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_CONTENT);
        $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($query) . '%');
        $rows = $queryBuilder
            ->select(...self::CONTENT_FIELDS)
            ->from(self::TABLE_CONTENT)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([self::DEFAULT_LANGUAGE, self::ALL_LANGUAGES], Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('header', $like),
                    $queryBuilder->expr()->like('subheader', $like),
                    $queryBuilder->expr()->like('bodytext', $like)
                )
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $row) use ($query): array {
            $content = $this->formatContent($row);
            $content['snippet'] = $this->snippet((string)$row['bodytext'], $query);
            unset($content['bodytext']);

            return $content;
        }, $rows);
    }

    private function snippet(string $html, string $query): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5)));
        $position = mb_stripos($text, $query);
        $start = $position === false ? 0 : max(0, $position - (int)(self::SNIPPET_LENGTH / 2));

        return mb_substr($text, $start, self::SNIPPET_LENGTH);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function formatPage(array $page): array
    {
        $formatted = [
            'uid' => (int)$page['uid'],
            'pid' => (int)$page['pid'],
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
            'doktype' => (int)$page['doktype'],
            'hidden' => (bool)$page['hidden'],
            'hiddenInMenu' => (bool)$page['nav_hide'],
        ];

        if (array_key_exists('seo_title', $page)) {
            $formatted['navTitle'] = (string)$page['nav_title'];
            $formatted['seoTitle'] = (string)$page['seo_title'];
            $formatted['description'] = (string)$page['description'];
            $formatted['updatedAt'] = date(\DATE_ATOM, (int)$page['tstamp']);
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function formatContent(array $content): array
    {
        return [
            'uid' => (int)$content['uid'],
            'pid' => (int)$content['pid'],
            'type' => (string)$content['CType'],
            'colPos' => (int)$content['colPos'],
            'header' => (string)$content['header'],
            'subheader' => (string)$content['subheader'],
            'bodytext' => (string)$content['bodytext'],
            'hidden' => (bool)$content['hidden'],
            'updatedAt' => date(\DATE_ATOM, (int)$content['tstamp']),
        ];
    }

    private function queryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction())->add(new WorkspaceRestriction(self::LIVE_WORKSPACE));

        return $queryBuilder;
    }
}
