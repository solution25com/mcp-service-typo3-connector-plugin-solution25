<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solution25\AiConnector\Connection\AuditTrail;
use Solution25\AiConnector\Connection\ConnectionRepository;
use Solution25\AiConnector\Connection\ConnectionService;
use Solution25\AiConnector\Connection\ConnectorAccount;
use Solution25\AiConnector\Connection\SiteAddress;
use Solution25\AiConnector\Gateway\GatewayClient;
use Solution25\AiConnector\Gateway\GatewayException;
use Solution25\AiConnector\Gateway\PairingRequest;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final class ConnectorModuleController
{
    private const ROUTE = 'integrations_aiconnector';

    private const ACTION_CHECK = 'check';

    private const ACTION_CONNECT = 'connect';

    private const ACTION_DISCONNECT = 'disconnect';

    private const ACTION_PERMISSIONS = 'permissions';

    private const HIDDEN_CONNECTION_FIELDS = ['connection_secret' => true, 'token_hash' => true];

    private const LABELS = 'LLL:EXT:ai_connector/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly SiteAddress $siteAddress,
        private readonly ConnectionService $connectionService,
        private readonly ConnectionRepository $connections,
        private readonly GatewayClient $gatewayClient,
        private readonly AuditTrail $auditTrail,
        private readonly ConnectorAccount $account,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('module.title'));

        if ($request->getMethod() !== 'POST') {
            return $this->overview($view, $request);
        }

        $body = (array)$request->getParsedBody();
        $action = (string)($body['action'] ?? '');
        $siteIdentifier = (string)($body['site'] ?? '');
        $code = (string)($body['code'] ?? '');

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);

            switch ($action) {
                case self::ACTION_CHECK:
                    $code = $this->connectionService->validCode($code);
                    $pending = $this->connectionService->request($code);

                    return $this->overview($view, $request, ['site' => $site, 'code' => $code, 'pending' => $pending]);
                case self::ACTION_CONNECT:
                    $this->connectionService->connect(
                        $this->siteAddress->details($site, $request),
                        $code,
                        array_values((array)($body['permissions'] ?? [])),
                        $this->backendUserId()
                    );
                    $view->addFlashMessage($this->label('flash.connected'), '', ContextualFeedbackSeverity::OK);
                    break;
                case self::ACTION_DISCONNECT:
                    $this->connectionService->disconnect($site->getIdentifier(), $this->backendUserId());
                    $view->addFlashMessage($this->label('flash.disconnected'), '', ContextualFeedbackSeverity::OK);
                    break;
                case self::ACTION_PERMISSIONS:
                    $this->connectionService->changePermissions(
                        $site->getIdentifier(),
                        array_values((array)($body['permissions'] ?? [])),
                        $this->backendUserId()
                    );
                    $view->addFlashMessage($this->label('flash.permissionsSaved'), '', ContextualFeedbackSeverity::OK);
                    break;
            }
        } catch (SiteNotFoundException) {
            $view->addFlashMessage($this->label('flash.siteNotFound'), '', ContextualFeedbackSeverity::ERROR);
        } catch (GatewayException $e) {
            $view->addFlashMessage($e->getMessage(), $this->label('flash.failed'), ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
    }

    /**
     * @param array{site?: Site, code?: string, pending?: PairingRequest} $state
     */
    private function overview(ModuleTemplate $view, ServerRequestInterface $request, array $state = []): ResponseInterface
    {
        $connections = $this->connections->findAll();
        $activeSite = isset($state['site']) ? $state['site']->getIdentifier() : null;
        $sites = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            $isActive = $identifier === $activeSite;
            $connection = $connections[$identifier] ?? null;
            $address = null;
            $addressProblem = null;

            try {
                $address = $this->siteAddress->details($site, $request);
            } catch (GatewayException $e) {
                $addressProblem = $e->getMessage();
            }

            $sites[] = [
                'identifier' => $identifier,
                'title' => $site->getConfiguration()['websiteTitle'] ?? $identifier,
                'rootPageId' => $site->getRootPageId(),
                'address' => $address,
                'addressProblem' => $addressProblem,
                'connection' => $connection === null ? null : array_diff_key($connection, self::HIDDEN_CONNECTION_FIELDS),
                'permissionChoices' => $connection === null ? [] : $this->permissionChoices($connection),
                'accountMissing' => $connection !== null && !$this->account->exists((int)$connection['be_user'], (int)$connection['be_group']),
                'pending' => $isActive ? ($state['pending'] ?? null) : null,
                'pendingCode' => $isActive ? ($state['code'] ?? '') : '',
            ];
        }

        $view->assignMultiple([
            'sites' => $sites,
            'gatewayUrl' => $this->gatewayUrlOrProblem(),
            'audit' => $this->auditTrail->recent(),
            'actionUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
        ]);

        return $view->renderResponse('Connector/Index');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return list<array{key: string, granted: bool}>
     */
    private function permissionChoices(array $connection): array
    {
        $requested = $connection['requested_permissions'] !== [] ? $connection['requested_permissions'] : $connection['permissions'];

        return array_map(
            static fn(string $key): array => ['key' => $key, 'granted' => in_array($key, $connection['permissions'], true)],
            $requested
        );
    }

    private function gatewayUrlOrProblem(): string
    {
        try {
            return $this->gatewayClient->mcpUrl();
        } catch (GatewayException $e) {
            return $e->getMessage();
        }
    }

    private function backendUserId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? (int)($backendUser->user['uid'] ?? 0) : 0;
    }

    private function label(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService->sL(self::LABELS . $key) : $key;
    }
}
