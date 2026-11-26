<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

use Psr\Log\LoggerInterface;
use Solution25\AiConnector\Connection\ConnectorRights;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ContentWriter
{
    private const NEW_ID = 'NEW1';

    private const MAX_HEADER_LENGTH = 255;

    private const MAX_BODYTEXT_LENGTH = 100000;

    private const MAX_SEO_TITLE_LENGTH = 255;

    private const MAX_DESCRIPTION_LENGTH = 1000;

    private const HIDDEN = 1;

    private const DEFAULT_COLUMN = 0;

    private const CONTENT_TEXT_FIELDS = ['header' => self::MAX_HEADER_LENGTH, 'subheader' => self::MAX_HEADER_LENGTH, 'bodytext' => self::MAX_BODYTEXT_LENGTH];

    private const SEO_FIELDS = ['seoTitle' => ['seo_title', self::MAX_SEO_TITLE_LENGTH], 'description' => ['description', self::MAX_DESCRIPTION_LENGTH]];

    private const PAGE_FIELDS = ['title' => ['title', self::MAX_HEADER_LENGTH], 'navTitle' => ['nav_title', self::MAX_HEADER_LENGTH]];

    public function __construct(
        private readonly PageReader $reader,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $body {pid, type, header?, subheader?, bodytext?, colPos?, after?}
     *
     * @return array<string, mixed> the created content element
     */
    public function createContent(ApiContext $context, array $body): array
    {
        $this->assertPermission($context, ConnectorRights::PERMISSION_CONTENT_CREATE);
        $this->assertCanModify($context, PageReader::TABLE_CONTENT);

        if (!$context->canEditField(PageReader::TABLE_CONTENT, 'hidden')) {
            throw ApiException::forbidden('This connection may not create content: new content must be saved hidden.');
        }

        $pageId = $this->integer($body, 'pid');
        $this->reader->pageInSite($context, $pageId);
        $type = $body['type'] ?? null;

        if (!is_string($type) || !in_array($type, ConnectorRights::CONTENT_TYPES, true)) {
            throw ApiException::invalidBody('"type" must be one of: ' . implode(', ', ConnectorRights::CONTENT_TYPES) . '.');
        }

        $fields = $this->textFields($context, $body);
        $target = $pageId;

        if (isset($body['after'])) {
            $after = $this->reader->contentInSite($context, $this->integer($body, 'after'));

            if ($after['pid'] !== $pageId) {
                throw ApiException::invalidBody('"after" must be a content element on the same page.');
            }
            $target = -$after['uid'];
            $fields['colPos'] = $after['colPos'];
        } else {
            $fields['colPos'] = isset($body['colPos']) ? $this->integer($body, 'colPos') : self::DEFAULT_COLUMN;
        }

        $fields += ['pid' => $target, 'CType' => $type, 'hidden' => self::HIDDEN, 'sys_language_uid' => PageReader::DEFAULT_LANGUAGE];
        $dataHandler = $this->process($context, [PageReader::TABLE_CONTENT => [self::NEW_ID => $fields]]);
        $uid = (int)($dataHandler->substNEWwithIDs[self::NEW_ID] ?? 0);

        if ($uid === 0) {
            throw ApiException::rejected('TYPO3 did not create the content element.');
        }

        return $this->reader->contentInSite($context, $uid);
    }

    /**
     * @param array<string, mixed> $body {header?, subheader?, bodytext?}
     *
     * @return array<string, mixed> the updated content element
     */
    public function updateContent(ApiContext $context, int $contentId, array $body): array
    {
        $this->assertPermission($context, ConnectorRights::PERMISSION_CONTENT_EDIT);
        $this->assertCanModify($context, PageReader::TABLE_CONTENT);
        $content = $this->reader->contentInSite($context, $contentId);

        if (!in_array($content['type'], ConnectorRights::CONTENT_TYPES, true)) {
            throw ApiException::forbidden('Content of type "' . $content['type'] . '" cannot be edited through this connection.');
        }

        $fields = $this->textFields($context, $body);

        if ($fields === []) {
            throw ApiException::invalidBody('Send at least one of: header, subheader, bodytext.');
        }

        $this->process($context, [PageReader::TABLE_CONTENT => [$contentId => $fields]]);

        return $this->reader->contentInSite($context, $contentId);
    }

    /**
     * @param array<string, mixed> $body {seoTitle?, description?}
     *
     * @return array<string, mixed> the updated page
     */
    public function updatePageSeo(ApiContext $context, int $pageId, array $body): array
    {
        $this->assertPermission($context, ConnectorRights::PERMISSION_SEO_EDIT);

        return $this->updatePageFields($context, $pageId, $body, self::SEO_FIELDS);
    }

    /**
     * @param array<string, mixed> $body {title?, navTitle?}
     *
     * @return array<string, mixed> the updated page
     */
    public function updatePage(ApiContext $context, int $pageId, array $body): array
    {
        $this->assertPermission($context, ConnectorRights::PERMISSION_PAGE_EDIT);

        if (array_key_exists('title', $body) && (!is_string($body['title']) || trim($body['title']) === '')) {
            throw ApiException::invalidBody('"title" must not be empty.');
        }

        return $this->updatePageFields($context, $pageId, $body, self::PAGE_FIELDS);
    }

    /**
     * @param array<string, mixed>                       $body
     * @param array<string, array{0: string, 1: int}>    $allowed API key => [pages field, max length]
     *
     * @return array<string, mixed>
     */
    private function updatePageFields(ApiContext $context, int $pageId, array $body, array $allowed): array
    {
        $this->assertCanModify($context, PageReader::TABLE_PAGES);
        $this->reader->pageInSite($context, $pageId);
        $fields = [];

        foreach ($allowed as $key => [$field, $maxLength]) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (!$context->canEditField(PageReader::TABLE_PAGES, $field)) {
                throw ApiException::forbidden('This connection may not change the page ' . $key . '.');
            }
            $fields[$field] = $this->text($body, $key, $maxLength);
        }

        if ($fields === []) {
            throw ApiException::invalidBody('Send at least one of: ' . implode(', ', array_keys($allowed)) . '.');
        }

        $this->process($context, [PageReader::TABLE_PAGES => [$pageId => $fields]]);

        return $this->reader->writtenPage($context, $pageId);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function textFields(ApiContext $context, array $body): array
    {
        $fields = [];

        foreach (self::CONTENT_TEXT_FIELDS as $field => $maxLength) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if (!$context->canEditField(PageReader::TABLE_CONTENT, $field)) {
                throw ApiException::forbidden('This connection may not change the content ' . $field . '.');
            }
            $fields[$field] = $this->text($body, $field, $maxLength);
        }

        return $fields;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     */
    private function process(ApiContext $context, array $data): DataHandler
    {
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousLanguage = $GLOBALS['LANG'] ?? null;
        try {
            $GLOBALS['BE_USER'] = $context->user;
            $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($context->user);
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->bypassAccessCheckForRecords = true;
            $dataHandler->start($data, [], $context->user);
            $dataHandler->process_datamap();
        } finally {
            $GLOBALS['BE_USER'] = $previousUser;
            $GLOBALS['LANG'] = $previousLanguage;
        }

        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('AI connector change refused by TYPO3: {errors}', ['errors' => implode(' ', $dataHandler->errorLog)]);

            throw ApiException::rejected('TYPO3 refused the change. Details are in the TYPO3 log.');
        }

        return $dataHandler;
    }

    private function assertPermission(ApiContext $context, string $permission): void
    {
        if (!$context->hasPermission($permission)) {
            throw ApiException::forbidden('This connection does not have the "' . $permission . '" permission.');
        }
    }

    private function assertCanModify(ApiContext $context, string $table): void
    {
        if (!$context->canModify($table)) {
            throw ApiException::forbidden('This connection may not change ' . $table . '.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function integer(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        if (!is_int($value) || $value < 0) {
            throw ApiException::invalidBody('"' . $key . '" must be a non-negative integer.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function text(array $body, string $key, int $maxLength): string
    {
        $value = $body[$key];

        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw ApiException::invalidBody(sprintf('"%s" must be a string of at most %d characters.', $key, $maxLength));
        }

        return $value;
    }
}
