<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Connection;

final class SiteDetails
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $rootPageId,
        public readonly string $url,
        public readonly string $apiUrl,
        public readonly string $name,
        public readonly string $typo3Version,
        public readonly string $extensionVersion,
    ) {}
}
