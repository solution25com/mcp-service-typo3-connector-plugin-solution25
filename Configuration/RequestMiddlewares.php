<?php

declare(strict_types=1);

use Solution25\AiConnector\Api\ApiMiddleware;

return [
    'frontend' => [
        'solution25/ai-connector/api' => [
            'target' => ApiMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/eid',
                'typo3/cms-frontend/maintenance-mode',
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
