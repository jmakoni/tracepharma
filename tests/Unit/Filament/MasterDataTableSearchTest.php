<?php

namespace Tests\Unit\Filament;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataTableSearchTest extends TestCase
{
    #[Test]
    public function sites_table_configures_gln_as_searchable(): void
    {
        $source = file_get_contents(app_path('Filament/App/Resources/Sites/Tables/SitesTable.php'));

        $this->assertStringContainsString("TextColumn::make('gln')", $source);
        $this->assertMatchesRegularExpression("/TextColumn::make\('gln'\).*->searchable\(\)/s", $source);
    }

    #[Test]
    public function sites_table_exposes_receive_eligible_column_and_filter(): void
    {
        $source = file_get_contents(app_path('Filament/App/Resources/Sites/Tables/SitesTable.php'));

        $this->assertStringContainsString("TextColumn::make('receive_eligible')", $source);
        $this->assertStringContainsString("TernaryFilter::make('receive_eligible')", $source);
        $this->assertStringContainsString('EligibleReceiveSites::forOrganization()', $source);
        $this->assertStringContainsString('EligibleReceiveSites::isEligible', $source);
    }

    #[Test]
    public function products_table_configures_ndc_as_searchable(): void
    {
        $source = file_get_contents(app_path('Filament/App/Resources/Products/Tables/ProductsTable.php'));

        $this->assertStringContainsString("TextColumn::make('ndc')", $source);
        $this->assertMatchesRegularExpression("/TextColumn::make\('ndc'\).*->searchable\(\)/s", $source);
    }
}
