<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Solution25\AiConnector\Connection\SiteAddress;
use TYPO3\CMS\Core\Http\JsonResponse;

final class ApiMiddleware implements MiddlewareInterface
{
    private const MAX_BODY_BYTES = 524288;

    private const MAX_JSON_DEPTH = 16;

    private const DEFAULT_TREE_DEPTH = 3;

    private const DEFAULT_SEARCH_LIMIT = 20;

    private const STATUS_OK = 200;

    private const STATUS_CREATED = 201;

    private const STATUS_SERVER_ERROR = 500;

    private const METHOD_GET = 'GET';

    private const METHOD_POST = 'POST';

    private const METHOD_PATCH = 'PATCH';

    private const NO_STORE_HEADERS = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];

    public function __construct(
        private readonly ApiAuthenticator $authenticator,
        private readonly PageReader $reader,
        private readonly ContentWriter $writer,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $apiPath = SiteAddress::apiPath($request);

        if (!str_starts_with($path, $apiPath)) {
            return $handler->handle($request);
        }

        try {
            $context = $this->authenticator->authenticate($request);
            [$status, $payload] = $this->route($request, $context, trim(substr($path, strlen($apiPath)), '/'));

            return new JsonResponse($payload, $status, self::NO_STORE_HEADERS);
        } catch (ApiException $e) {
            return new JsonResponse(['reason' => $e->reason, 'message' => $e->getMessage()], $e->status, self::NO_STORE_HEADERS);
        } catch (\Throwable $e) {
            $this->logger->error('AI connector API request failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return new JsonResponse(['reason' => 'server_error', 'message' => 'The request could not be completed.'], self::STATUS_SERVER_ERROR, self::NO_STORE_HEADERS);
        }
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function route(ServerRequestInterface $request, ApiContext $context, string $route): array
    {
        $method = $request->getMethod();
        $query = $request->getQueryParams();
        $segments = $route === '' ? [] : explode('/', $route);

        return match (true) {
            $segments === ['status'] => $this->only($method, self::METHOD_GET, fn() => [self::STATUS_OK, $this->status($context)]),
            $segments === ['pages', 'tree'] => $this->only($method, self::METHOD_GET, fn() => [self::STATUS_OK, $this->reader->tree(
                $context,
                isset($query['root']) ? $this->id((string)$query['root']) : null,
                isset($query['depth']) ? (int)$query['depth'] : self::DEFAULT_TREE_DEPTH
            )]),
            count($segments) === 2 && $segments[0] === 'pages' && $method === self::METHOD_PATCH => [
                self::STATUS_OK,
                ['page' => $this->writer->updatePage($context, $this->id($segments[1]), $this->body($request))],
            ],
            count($segments) === 2 && $segments[0] === 'pages' => $this->only($method, self::METHOD_GET, fn() => [
                self::STATUS_OK,
                $this->reader->page($context, $this->id($segments[1])),
            ]),
            count($segments) === 3 && $segments[0] === 'pages' && $segments[2] === 'seo' => $this->only($method, self::METHOD_PATCH, fn() => [
                self::STATUS_OK,
                ['page' => $this->writer->updatePageSeo($context, $this->id($segments[1]), $this->body($request))],
            ]),
            $segments === ['content', 'search'] => $this->only($method, self::METHOD_GET, fn() => [self::STATUS_OK, $this->reader->search(
                $context,
                (string)($query['q'] ?? ''),
                isset($query['limit']) ? (int)$query['limit'] : self::DEFAULT_SEARCH_LIMIT
            )]),
            $segments === ['content'] => $this->only($method, self::METHOD_POST, fn() => [
                self::STATUS_CREATED,
                ['content' => $this->writer->createContent($context, $this->body($request))],
            ]),
            count($segments) === 2 && $segments[0] === 'content' && $method === self::METHOD_GET => [
                self::STATUS_OK,
                ['content' => $this->reader->content($context, $this->id($segments[1]))],
            ],
            count($segments) === 2 && $segments[0] === 'content' => $this->only($method, self::METHOD_PATCH, fn() => [
                self::STATUS_OK,
                ['content' => $this->writer->updateContent($context, $this->id($segments[1]), $this->body($request))],
            ]),
            default => throw ApiException::notFound(),
        };
    }

    /**
     * @param \Closure(): array{int, array<string, mixed>} $action
     *
     * @return array{int, array<string, mixed>}
     */
    private function only(string $method, string $allowed, \Closure $action): array
    {
        if ($method !== $allowed) {
            throw ApiException::methodNotAllowed();
        }

        return $action();
    }

    /**
     * @return array<string, mixed>
     */
    private function status(ApiContext $context): array
    {
        return [
            'site' => [
                'identifier' => $context->site->getIdentifier(),
                'rootPageId' => $context->site->getRootPageId(),
                'url' => (string)$context->connection['site_url'],
            ],
            'connectionId' => (string)$context->connection['gateway_connection_id'],
            'permissions' => json_decode((string)$context->connection['permissions'], true) ?: [],
            'rights' => [
                'readPages' => $context->canRead(PageReader::TABLE_PAGES),
                'readContent' => $context->canRead(PageReader::TABLE_CONTENT),
                'modifyPages' => $context->canModify(PageReader::TABLE_PAGES),
                'modifyContent' => $context->canModify(PageReader::TABLE_CONTENT),
            ],
            'extensionVersion' => SiteAddress::extensionVersion(),
        ];
    }

    private function id(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1) {
            throw ApiException::notFound();
        }

        return (int)$value;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        $raw = '';

        while (strlen($raw) <= self::MAX_BODY_BYTES && ($chunk = $stream->read(self::MAX_BODY_BYTES + 1 - strlen($raw))) !== '') {
            $raw .= $chunk;
        }

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw ApiException::payloadTooLarge();
        }

        try {
            $body = json_decode($raw, true, self::MAX_JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::invalidBody('The body must be a JSON object.');
        }

        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw ApiException::invalidBody('The body must be a JSON object.');
        }

        return $body;
    }
}
