<?php

namespace Tests\Unit\Models;

use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use App\Models\Fda\FdaProduct;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaProductLinkedToTenantProductsScopeTest extends TestCase
{
    #[Test]
    public function linked_to_tenant_products_scope_uses_cross_database_exists_on_tenant_products(): void
    {
        $tenantDatabase = DB::connection((new Product)->getConnectionName())->getDatabaseName();

        $sql = FdaProduct::query()
            ->linkedToTenantProducts()
            ->toSql();

        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString($tenantDatabase, $sql);
        $this->assertStringContainsString('products', $sql);
        $this->assertStringContainsString('fda_product_id', $sql);
    }

    #[Test]
    public function app_fda_product_resource_eloquent_query_scopes_to_linked_prescription_rows(): void
    {
        $sql = FdaProductResource::getEloquentQuery()->toSql();

        $this->assertStringContainsString('product_type', $sql);
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringContainsString('fda_product_id', $sql);
    }
}
