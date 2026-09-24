<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccAggregationDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccAggregationDocumentTest extends TestCase
{
    private const TEST_SETTINGS = ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'];

    #[Test]
    public function test_builds_aggregation_document_for_batch(): void
    {
        $batch = new SsccLabelBatch([
            'company_prefix' => '030116',
            'extension_digit' => '0',
        ]);
        $batch->id = 1;

        $label = new SsccLabel([
            'sscc_18' => '003011600002101675',
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
            'extension_digit' => '0',
            'company_prefix' => '030116',
        ]);
        $label->id = 10;

        $child = new SsccLabelChild([
            'child_epc' => 'urn:epc:id:sgtin:030116.5200116.00000000413101',
        ]);

        $label->setRelation('children', new Collection([$child]));
        $batch->setRelation('labels', new Collection([$label]));

        $xml = app(GenerateSsccAggregationDocument::class)->forBatch($batch, settings: self::TEST_SETTINGS);

        $this->assertStringContainsString('<AggregationEvent>', $xml);
        $this->assertStringContainsString('<action>ADD</action>', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.00000210167', $xml);
        $this->assertStringContainsString('urn:epc:id:sgtin:030116.5200116.00000000413101', $xml);
        $this->assertStringContainsString('EPCISDocument', $xml);
        $this->assertStringNotContainsString('<childQuantityList>', $xml);
    }

    #[Test]
    public function homogeneous_sgtins_add_lgtin_child_quantity_list_without_dropping_instances(): void
    {
        $this->initializeDemo2Tenant();
        $epcIds = [];

        try {
            $firstUri = 'urn:epc:id:sgtin:030116.5200116.HQ'.random_int(10000000, 99999999);
            $secondUri = 'urn:epc:id:sgtin:030116.5200116.HQ'.random_int(10000000, 99999999);
            foreach ([$firstUri, $secondUri] as $uri) {
                $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
                $epcIds[] = (int) $epc->getKey();
                EpcIlmd::query()->create([
                    'epc_id' => $epc->getKey(),
                    'gtin14' => $epc->gtin14,
                    'lot_number' => 'LOT-A',
                ]);
            }

            $label = new SsccLabel([
                'sscc_18' => '003011600002101675',
                'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
                'extension_digit' => '0',
                'company_prefix' => '030116',
            ]);
            $label->id = 11;
            $label->setRelation('children', new Collection([
                new SsccLabelChild(['child_epc' => $firstUri]),
                new SsccLabelChild(['child_epc' => $secondUri]),
            ]));

            $xml = app(GenerateSsccAggregationDocument::class)->forLabel($label, settings: self::TEST_SETTINGS);

            $this->assertStringContainsString('<childEPCs>', $xml);
            $this->assertStringContainsString($firstUri, $xml);
            $this->assertStringContainsString($secondUri, $xml);
            $this->assertStringContainsString('<childQuantityList>', $xml);
            $this->assertStringContainsString('urn:epc:class:lgtin:030116.5200116.LOT-A', $xml);
            $this->assertStringContainsString('<quantity>2</quantity>', $xml);
        } finally {
            if ($epcIds !== []) {
                EpcIlmd::query()->whereIn('epc_id', $epcIds)->delete();
                Epc::query()->whereKey($epcIds)->delete();
            }
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function initializeDemo2Tenant(): void
    {
        $tenant = Tenant::query()->find('13fe9068-cb05-4bab-9e0e-a89f2a458832');
        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => '13fe9068-cb05-4bab-9e0e-a89f2a458832',
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->firstOrCreate(['domain' => 'demo2.internal.vatengi.com']);
        }

        $this->artisan('tenants:migrate', [
            '--tenants' => ['13fe9068-cb05-4bab-9e0e-a89f2a458832'],
            '--force' => true,
        ])->assertSuccessful();

        tenancy()->initialize($tenant);
    }
}
