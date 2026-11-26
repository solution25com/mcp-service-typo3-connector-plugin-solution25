<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

use TYPO3\CMS\Core\Core\Environment;

final class GatewayConfiguration
{
    public const DEFAULT_MCP_URL = 'https://shopware-mcp.development-s25.com/mcp';

    public const ENVIRONMENT_VARIABLE = 'AI_CONNECTOR_GATEWAY_URL';

    private const MCP_PATH = '/mcp';

    private const HTTPS_SCHEME = 'https';

    private const HTTP_SCHEME = 'http';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    public function mcpUrl(): string
    {
        $parts = $this->parts();
        $path = rtrim($parts['path'], '/');

        return $this->origin($parts) . ($path === '' ? self::MCP_PATH : $path);
    }

    public function gatewayUrl(): string
    {
        return $this->origin($this->parts());
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, path: string}
     */
    private function parts(): array
    {
        $override = getenv(self::ENVIRONMENT_VARIABLE);
        $address = is_string($override) && $override !== '' ? $override : self::DEFAULT_MCP_URL;
        $parts = filter_var($address, \FILTER_VALIDATE_URL) !== false ? parse_url($address) : false;

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw GatewayException::invalidGatewayUrl();
        }

        $scheme = strtolower($parts['scheme']);
        $isLocal = in_array($parts['host'], self::LOCAL_HOSTS, true);
        $allowed = $scheme === self::HTTPS_SCHEME
            || ($scheme === self::HTTP_SCHEME && ($isLocal || Environment::getContext()->isDevelopment()));

        if (!$allowed) {
            throw GatewayException::invalidGatewayUrl();
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
        ];
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, path: string} $parts
     */
    private function origin(array $parts): string
    {
        return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] !== null ? ':' . $parts['port'] : '');
    }
}
