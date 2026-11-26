<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI connector',
    'description' => 'Connects a TYPO3 site to AI clients such as Claude through the Solution25 MCP gateway.',
    'category' => 'be',
    'state' => 'beta',
    'version' => '0.1.0',
    'author' => 'Solution25',
    'author_company' => 'Solution25',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
            'backend' => '13.4.0-14.3.99',
            'seo' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
