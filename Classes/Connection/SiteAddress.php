<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

use Psr\Http\Message\ServerRequestInterface;
use Solution25\AiConnector\Gateway\GatewayException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class SiteAddress
{
    public const API_PATH = '/ai-connector/api/';

    public const EXTENSION_KEY = 'ai_connector';

    private const UNKNOWN_VERSION = '0.0.0';

    private const HTTPS_SCHEME = 'https';

    private const MAX_NAME_LENGTH = 100;

    public function details(Site $site, ServerRequestInterface $request): SiteDetails
    {
        $base = $site->getBase();
        $scheme = $base->getScheme();
        $host = $base->getHost();
        $port = $base->getPort();

        if ($host === '') {
            $normalizedParams = $request->getAttribute('normalizedParams');

            if (!$normalizedParams instanceof NormalizedParams) {
                throw GatewayException::invalidSiteUrl();
            }

            $requestUri = $request->getUri();
            $scheme = $normalizedParams->isHttps() ? self::HTTPS_SCHEME : $requestUri->getScheme();
            $host = $normalizedParams->getRequestHostOnly();
            $port = $requestUri->getPort();
        }

        if ($scheme !== self::HTTPS_SCHEME || $host === '') {
            throw GatewayException::invalidSiteUrl();
        }

        $origin = self::HTTPS_SCHEME . '://' . $host . ($port === null ? '' : ':' . $port);
        $path = '/' . ltrim($base->getPath(), '/');

        return new SiteDetails(
            $site->getIdentifier(),
            $site->getRootPageId(),
            rtrim($origin . $path, '/'),
            $origin . self::apiPath($request),
            $this->name($site, $host),
            (new Typo3Version())->getVersion(),
            self::extensionVersion(),
        );
    }

    public static function extensionVersion(): string
    {
        try {
            return ExtensionManagementUtility::getExtensionVersion(self::EXTENSION_KEY) ?: self::UNKNOWN_VERSION;
        } catch (\Throwable) {
            return self::UNKNOWN_VERSION;
        }
    }

    public static function apiPath(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        $sitePath = $normalizedParams instanceof NormalizedParams ? $normalizedParams->getSitePath() : '/';

        return rtrim($sitePath, '/') . self::API_PATH;
    }

    private function name(Site $site, string $host): string
    {
        $title = $site->getConfiguration()['websiteTitle'] ?? '';
        $name = is_string($title) && trim($title) !== '' ? trim($title) : $host;

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
