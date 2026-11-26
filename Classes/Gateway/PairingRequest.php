<?php

declare(strict_types=1);

namespace Solution25\AiConnector\Gateway;

final class PairingRequest
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly string $clientName,
        public readonly array $permissions,
        public readonly ?string $startedAt,
        public readonly ?string $verifiedClient,
        public readonly ?string $returnHost,
    ) {}
}
