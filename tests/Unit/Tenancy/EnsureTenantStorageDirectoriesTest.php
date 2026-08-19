<?php

namespace Tests\Unit\Tenancy;

use App\Actions\Tenancy\EnsureTenantStorageDirectories;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureTenantStorageDirectoriesTest extends TestCase
{
    #[Test]
    public function it_creates_tenant_storage_directories(): void
    {
        $tenantId = 'test-storage-'.str_replace('.', '', uniqid('', true));

        $tenant = new Tenant([
            'id' => $tenantId,
            'name' => 'Storage Test',
            'profile' => TenantProfile::Pharmacy,
        ]);
        $tenant->id = $tenantId;

        $root = storage_path('tenant'.$tenantId);

        try {
            $result = app(EnsureTenantStorageDirectories::class)->handle($tenant);

            $this->assertSame($root, $result['path']);
            $this->assertDirectoryExists($root.'/app/livewire-tmp');
            $this->assertDirectoryExists($root.'/app/private');
            $this->assertDirectoryExists($root.'/app/labels/sscc');
            $this->assertDirectoryExists($root.'/app/epcis/outbound');
            $this->assertDirectoryExists($root.'/framework/cache');
            $this->assertDirectoryExists($root.'/logs');
            $this->assertTrue(is_writable($root.'/app/livewire-tmp'));
            $this->assertTrue(is_writable($root.'/app/labels/sscc'));

            // Idempotent
            $second = app(EnsureTenantStorageDirectories::class)->handle($tenant);
            $this->assertSame([], $second['created']);
        } finally {
            if (File::isDirectory($root)) {
                File::deleteDirectory($root);
            }
        }
    }
}
