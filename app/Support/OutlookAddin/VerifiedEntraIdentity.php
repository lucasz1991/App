<?php

namespace App\Support\OutlookAddin;

final readonly class VerifiedEntraIdentity
{
    public function __construct(
        public string $tenantId,
        public string $objectId,
        public string $principal,
        public string $displayName,
    ) {}
}
