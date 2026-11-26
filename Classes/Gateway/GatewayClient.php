<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

use TYPO3\CMS\Core\Http\RequestFactory;

class GatewayClient
{
    private const API_PREFIX = '/api/v1/';

    private const TIMEOUT_SECONDS = 10;

    private const STATUS_NO_CONTENT = 204;

    private const STATUS_NOT_FOUND = 404;

    private const STATUS_SUCCESS_MIN = 200;

    private const STATUS_SUCCESS_MAX = 299;

    private const MAX_JSON_DEPTH = 16;

    private const DEFAULT_CLIENT_NAME = 'AI client';

    private const DEFAULT_ERROR_MESSAGE = 'The connector server refused the request.';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly GatewayConfiguration $configuration,
    ) {}

    public function gatewayUrl(): string
    {
        return $this->configuration->gatewayUrl();
    }

    public function mcpUrl(): string
    {
        return $this->configuration->mcpUrl();
    }

    public function pairingRequest(string $code): PairingRequest
    {
        $payload = $this->send('pairing/request', ['json' => ['code' => $code]]);
        $permissions = $payload['permissions'] ?? null;

        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw GatewayException::unreachable();
        }

        return new PairingRequest(
            $this->stringOrNull($payload['clientName'] ?? null) ?? self::DEFAULT_CLIENT_NAME,
            array_values(array_filter($permissions, 'is_string')),
            $this->stringOrNull($payload['startedAt'] ?? null),
            $this->stringOrNull($payload['verifiedClient'] ?? null),
            $this->stringOrNull($payload['returnHost'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $pairing
     *
     * @return array{connectionId: string, connectionSecret: string}
     */
    public function completePairing(array $pairing): array
    {
        $payload = $this->send('pairing/complete', ['json' => $pairing]);
        $connectionId = $payload['connectionId'] ?? null;
        $connectionSecret = $payload['connectionSecret'] ?? null;

        if (!is_string($connectionId) || $connectionId === '' || !is_string($connectionSecret) || $connectionSecret === '') {
            throw GatewayException::unreachable();
        }

        return ['connectionId' => $connectionId, 'connectionSecret' => $connectionSecret];
    }

    public function disconnect(string $gatewayUrl, string $connectionId, string $connectionSecret): void
    {
        $this->assertCurrentGateway($gatewayUrl);
        $this->send(
            'connections/' . rawurlencode($connectionId) . '/disconnect',
            ['headers' => ['Authorization' => 'Bearer ' . $connectionSecret]],
            true
        );
    }

    /**
     * @param list<string> $permissions
     */
    public function updatePermissions(string $gatewayUrl, string $connectionId, string $connectionSecret, string $accessLevel, array $permissions): void
    {
        $this->assertCurrentGateway($gatewayUrl);
        $this->send(
            'connections/' . rawurlencode($connectionId) . '/permissions',
            [
                'headers' => ['Authorization' => 'Bearer ' . $connectionSecret],
                'json' => ['accessLevel' => $accessLevel, 'permissions' => $permissions],
            ]
        );
    }

    private function assertCurrentGateway(string $gatewayUrl): void
    {
        if ($gatewayUrl !== $this->gatewayUrl()) {
            throw GatewayException::invalidGatewayUrl();
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function send(string $path, array $options, bool $notFoundIsDone = false): array
    {
        [$status, $payload] = $this->post($path, $options);

        if ($status === self::STATUS_NO_CONTENT || ($notFoundIsDone && $status === self::STATUS_NOT_FOUND)) {
            return [];
        }

        if ($status >= self::STATUS_SUCCESS_MIN && $status <= self::STATUS_SUCCESS_MAX) {
            return $payload;
        }

        if ($payload === []) {
            throw GatewayException::unreachable();
        }

        throw GatewayException::rejected(
            $this->stringOrNull($payload['reason'] ?? null) ?? GatewayException::REASON_GATEWAY_ERROR,
            $this->stringOrNull($payload['message'] ?? null) ?? self::DEFAULT_ERROR_MESSAGE,
            $status
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, array<string, mixed>}
     */
    private function post(string $path, array $options): array
    {
        $url = $this->configuration->gatewayUrl() . self::API_PREFIX . $path;

        try {
            $response = $this->requestFactory->request($url, 'POST', $options + [
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            throw GatewayException::unreachable();
        }

        try {
            $payload = json_decode((string)$response->getBody(), true, self::MAX_JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        return [$response->getStatusCode(), is_array($payload) ? $payload : []];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
