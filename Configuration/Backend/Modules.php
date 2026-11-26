<?php

declare(strict_types=1);

use Solution25\AiConnector\Controller\ConnectorModuleController;
use TYPO3\CMS\Core\Information\Typo3Version;

$isTypo3V14 = (new Typo3Version())->getMajorVersion() >= 14;

return [
    'integrations_aiconnector' => [
        'parent' => $isTypo3V14 ? 'integrations' : 'system',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/integrations/ai-connector',
        'iconIdentifier' => 'ai-connector-module',
        'labels' => [
            'title' => 'LLL:EXT:ai_connector/Resources/Private/Language/module.xlf:title',
            'description' => 'LLL:EXT:ai_connector/Resources/Private/Language/module.xlf:description',
            'shortDescription' => 'LLL:EXT:ai_connector/Resources/Private/Language/module.xlf:short_description',
        ],
        'routes' => [
            '_default' => [
                'target' => ConnectorModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
