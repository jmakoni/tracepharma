<?php

namespace App\Actions\Catalog;

/**
 * @deprecated Catalog partners are no longer the write path. Delegates to FDA organizations.
 */
final class EnsureMajorWholesalerCatalogPartners
{
    public function handle(): int
    {
        return app(EnsureMajorWholesalerFdaOrganizations::class)->handle();
    }
}
