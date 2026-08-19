<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\SearchEpcisSchema;
use App\Enums\TenantProfile;
use App\Filament\App\Support\EpcisSchemaSearchForm;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Find / Recall simple filters must return the inbound EPCIS file that owns the value.
 * Fixture values are taken from demo2 document #383 when present.
 */
class FindRecallInboundDocumentFiltersTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DOCUMENT_ID = 383;

    private static bool $demo2TenantReady = false;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function findRecallSimpleFilterProvider(): array
    {
        return [
            'lot' => [
                [
                    'advanced' => false,
                    'gtin14' => null,
                    'lot_number' => '605140A',
                    'asn_or_po' => null,
                ],
                'ilmd.lot_number',
            ],
            'asn' => [
                [
                    'advanced' => false,
                    'gtin14' => null,
                    'lot_number' => null,
                    'asn_or_po' => '02648341546',
                ],
                'doc.asn_or_po',
            ],
            'po' => [
                [
                    'advanced' => false,
                    'gtin14' => null,
                    'lot_number' => null,
                    'asn_or_po' => '8145630974',
                ],
                'doc.asn_or_po',
            ],
            'gtin' => [
                [
                    'advanced' => false,
                    'gtin14' => '50301164005081',
                    'lot_number' => null,
                    'asn_or_po' => null,
                ],
                'epc.gtin14',
            ],
        ];
    }

    #[Test]
    #[DataProvider('findRecallSimpleFilterProvider')]
    public function each_find_recall_simple_filter_returns_document_383(array $form, string $expectedField): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->find(self::DOCUMENT_ID);
            $this->assertNotNull($document, 'Expected inbound EPCIS document #383 on demo2.');

            $this->assertDocument383OwnsFixtureValues($document);

            $payload = EpcisSchemaSearchForm::searchPayloadFromForm($form);

            $this->assertSame('documents', $payload['result_type']);
            $this->assertSame($expectedField, $payload['rules'][0]['field'] ?? null);

            $result = app(SearchEpcisSchema::class)->handle(
                $payload['result_type'],
                $payload['rules'],
            );

            $this->assertSame('documents', $result['type']);
            $this->assertGreaterThanOrEqual(1, $result['total']);
            $this->assertContains(
                self::DOCUMENT_ID,
                $result['rows']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Each case: field, operator, static value (null when resolved at runtime), runtime source key.
     *
     * Runtime sources: document attribute name, `creation_date`/`received_at` (toDateString),
     * `dscsa_affirm` (is_true/is_false), or `bt.value`.
     *
     * @return array<string, array{0: string, 1: string, 2: mixed, 3: ?string}>
     */
    public static function findRecallAdvancedDocumentFilterProvider(): array
    {
        return [
            'epc.gtin14' => ['epc.gtin14', 'eq', '50301164005081', null],
            'ilmd.lot_number' => ['ilmd.lot_number', 'eq', '605140A', null],
            'doc.id' => ['doc.id', 'eq', self::DOCUMENT_ID, null],
            'doc.asn_or_po' => ['doc.asn_or_po', 'eq', '02648341546', null],
            'doc.asn_number' => ['doc.asn_number', 'eq', '02648341546', null],
            'doc.customer_po' => ['doc.customer_po', 'eq', '8145630974', null],
            'doc.ship_from_gln' => ['doc.ship_from_gln', 'eq', null, 'ship_from_gln'],
            'doc.ship_to_gln' => ['doc.ship_to_gln', 'eq', null, 'ship_to_gln'],
            'doc.sender_gln' => ['doc.sender_gln', 'eq', null, 'sender_gln'],
            'doc.receiver_gln' => ['doc.receiver_gln', 'eq', null, 'receiver_gln'],
            'doc.trading_partner_id' => ['doc.trading_partner_id', 'eq', null, 'trading_partner_id'],
            'doc.status' => ['doc.status', 'eq', null, 'status'],
            'doc.direction' => ['doc.direction', 'eq', null, 'direction'],
            'doc.dscsa_affirm' => ['doc.dscsa_affirm', 'is_true', null, 'dscsa_affirm'],
            'doc.creation_date' => ['doc.creation_date', 'eq', null, 'creation_date'],
            'doc.received_at' => ['doc.received_at', 'eq', null, 'received_at'],
            'bt.value' => ['bt.value', 'eq', null, 'bt.value'],
        ];
    }

    #[Test]
    public function advanced_epcs_search_with_only_doc_receiver_gln_coerces_to_documents_and_finds_383(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $form = [
                'advanced' => true,
                'result_type' => 'epcs',
                'rules' => [
                    [
                        'field' => 'doc.receiver_gln',
                        'operator' => 'eq',
                        'value' => '0010939000002',
                    ],
                ],
            ];

            $payload = EpcisSchemaSearchForm::searchPayloadFromForm($form);

            $this->assertSame('documents', $payload['result_type']);
            $this->assertSame('doc.receiver_gln', $payload['rules'][0]['field'] ?? null);

            $result = app(SearchEpcisSchema::class)->handle(
                $payload['result_type'],
                $payload['rules'],
            );

            $this->assertSame('documents', $result['type']);
            $this->assertGreaterThanOrEqual(1, $result['total']);
            $this->assertContains(
                self::DOCUMENT_ID,
                $result['rows']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function advanced_epcs_search_with_epc_gtin14_is_not_coerced_to_documents(): void
    {
        $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
            'advanced' => true,
            'result_type' => 'epcs',
            'rules' => [
                [
                    'field' => 'epc.gtin14',
                    'operator' => 'eq',
                    'value' => '50301164005081',
                ],
            ],
        ]);

        $this->assertSame('epcs', $payload['result_type']);
        $this->assertSame('epc.gtin14', $payload['rules'][0]['field'] ?? null);
    }

    #[Test]
    #[DataProvider('findRecallAdvancedDocumentFilterProvider')]
    public function each_find_recall_advanced_document_filter_returns_document_383(
        string $field,
        string $operator,
        mixed $staticValue,
        ?string $valueSource,
    ): void {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->find(self::DOCUMENT_ID);
            $this->assertNotNull($document, 'Expected inbound EPCIS document #383 on demo2.');

            [$operator, $value] = $this->resolveAdvancedFilterValue(
                $document,
                $operator,
                $staticValue,
                $valueSource,
            );

            $rule = array_filter(
                [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ],
                static fn (mixed $v, string $k): bool => $k !== 'value' || $v !== null,
                ARRAY_FILTER_USE_BOTH,
            );

            // Form remaps a lone doc.asn_number rule to doc.asn_or_po; call search directly.
            if ($field === 'doc.asn_number') {
                $result = app(SearchEpcisSchema::class)->handle('documents', [$rule]);
            } else {
                $payload = EpcisSchemaSearchForm::searchPayloadFromForm([
                    'advanced' => true,
                    'result_type' => 'documents',
                    'more_fields' => true,
                    'rules' => [$rule],
                ]);

                $this->assertSame('documents', $payload['result_type']);
                $this->assertSame($field, $payload['rules'][0]['field'] ?? null);

                $result = app(SearchEpcisSchema::class)->handle(
                    $payload['result_type'],
                    $payload['rules'],
                );
            }

            $this->assertSame('documents', $result['type']);
            $this->assertGreaterThanOrEqual(1, $result['total']);
            $this->assertContains(
                self::DOCUMENT_ID,
                $result['rows']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function resolveAdvancedFilterValue(
        EpcisDocument $document,
        string $operator,
        mixed $staticValue,
        ?string $valueSource,
    ): array {
        if ($valueSource === null) {
            return [$operator, $staticValue];
        }

        if ($valueSource === 'bt.value') {
            $btValue = DB::table('event_biz_transactions')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_biz_transactions.event_id')
                ->where('epcis_events.document_id', self::DOCUMENT_ID)
                ->value('event_biz_transactions.value');

            $this->assertNotNull($btValue, 'Document #383 should have at least one biz transaction value.');

            return [$operator, $btValue];
        }

        if ($valueSource === 'dscsa_affirm') {
            $this->assertNotNull($document->dscsa_affirm);

            return [$document->dscsa_affirm ? 'is_true' : 'is_false', null];
        }

        if ($valueSource === 'creation_date' || $valueSource === 'received_at') {
            $date = $document->{$valueSource};
            $this->assertNotNull($date, "Document #383 {$valueSource} should be set.");

            return [$operator, $date->toDateString()];
        }

        $value = $document->{$valueSource};
        $this->assertNotNull($value, "Document #383 {$valueSource} should be set.");

        return [$operator, $value];
    }

    private function assertDocument383OwnsFixtureValues(EpcisDocument $document): void
    {
        $this->assertSame('02648341546', $document->asn_number);
        $this->assertSame('8145630974', $document->customer_po);

        $lotExists = DB::table('document_epcs')
            ->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'document_epcs.epc_id')
            ->where('document_epcs.document_id', self::DOCUMENT_ID)
            ->where('epc_ilmd.lot_number', '605140A')
            ->exists();
        $this->assertTrue($lotExists, 'Document #383 should contain lot 605140A.');

        $gtinColumn = Schema::hasColumn('epc_ilmd', 'gtin14') ? 'epc_ilmd.gtin14' : 'epcs.gtin14';
        $gtinQuery = DB::table('document_epcs')
            ->where('document_epcs.document_id', self::DOCUMENT_ID);

        if (str_starts_with($gtinColumn, 'epc_ilmd')) {
            $gtinQuery->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'document_epcs.epc_id');
        } else {
            $gtinQuery->join('epcs', 'epcs.id', '=', 'document_epcs.epc_id');
        }

        $this->assertTrue(
            $gtinQuery->where($gtinColumn, '50301164005081')->exists(),
            'Document #383 should contain GTIN 50301164005081.',
        );
    }

    private function initializeDemo2Tenant(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
        }

        if (! self::$demo2TenantReady) {
            if (! $tenant->domains()->where('domain', self::DEMO2_DOMAIN)->exists()) {
                $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
            }
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
    }
}
