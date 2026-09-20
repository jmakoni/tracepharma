<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IdentifierEntryStandardsTest extends TestCase
{
    #[Test]
    public function vrs_and_dispense_paths_use_gtin_parser_not_blind_pad(): void
    {
        foreach ([
            'app/Filament/App/Pages/VerifyProduct.php',
            'app/Http/Controllers/Api/V1/DispenseCheckController.php',
            'app/Actions/Vrs/RespondToInboundVerification.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));
            $this->assertStringContainsString('Gtin::fromUpc', $source, $relative);
            $this->assertStringNotContainsString("str_pad(preg_replace('/\\D+/'", $source, $relative);
            $this->assertStringNotContainsString('trim((string) $serial)', $source, $relative);
            $this->assertStringNotContainsString('trim($serial)', $source, $relative);
        }
    }

    #[Test]
    public function exception_and_quarantine_tables_do_not_rebuild_indicator_zero_gtin(): void
    {
        foreach ([
            'app/Services/Quarantine/SupplierQuarantineTableBuilder.php',
            'app/Filament/App/Resources/Exceptions/RelationManagers/EpcsRelationManager.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));
            $this->assertStringNotContainsString("'0'.\$epc->company_prefix", $source, $relative);
            $this->assertStringNotContainsString("'0'.\$record->company_prefix", $source, $relative);
            $this->assertStringNotContainsString("'0'.substr(", $source, $relative);
        }
    }

    #[Test]
    public function leftover_authored_events_do_not_hardcode_utc_offset(): void
    {
        foreach ([
            'app/Actions/Outbound/GenerateSsccCommissioningEvent.php',
            'app/Actions/Outbound/GenerateSsccDisaggregationEvent.php',
            'app/Support/Epcis/BuildFullHistoryShippingEpcisXml.php',
            'app/Actions/Packing/AuthorTransformationRepack.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));
            $this->assertStringNotContainsString('<eventTimeZoneOffset>+00:00</eventTimeZoneOffset>', $source, $relative);
            $this->assertStringContainsString('AuthoredEventTimezone', $source, $relative);
        }
    }

    #[Test]
    public function gtin_from_upc_rejects_blind_padding(): void
    {
        $this->assertNull(Gtin::fromUpc('123'));
        $this->assertNull(Gtin::fromUpc('34374222669'));
    }
}
