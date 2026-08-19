<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Epcis\OutboundEpcisFilename;
use App\Support\Epcis\SbdhInstanceIdentifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundEpcisFilenameTest extends TestCase
{
    #[Test]
    public function filename_and_sbdh_instance_follow_product_conventions(): void
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Filename Test Co',
            'profile' => TenantProfile::DrugWholesaler,
            'status' => 'active',
        ]);
        $tenant->domains()->create(['domain' => 'acme.example.test']);

        try {
            tenancy()->initialize($tenant);

            $at = Carbon::parse('2026-08-08T02:54:26.000Z');
            $filename = OutboundEpcisFilename::forShippingEvent($tenant, $at);

            $this->assertSame(
                'acme_stage_tracepharma_io_20260808T025426Z_'.$tenant->getKey().'-processed_data.xml',
                $filename,
            );
            $this->assertSame(
                'epcis/outbound/'.$filename,
                OutboundEpcisFilename::storagePath($tenant, $at),
            );
            $this->assertSame('urn:uuid:20260808025426000', SbdhInstanceIdentifier::fromEventTime($at));
        } finally {
            tenancy()->end();
            $tenant->domains()->delete();
            $tenant->delete();
        }
    }
}
