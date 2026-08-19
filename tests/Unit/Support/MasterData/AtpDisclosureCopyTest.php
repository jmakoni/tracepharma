<?php

namespace Tests\Unit\Support\MasterData;

use App\Support\MasterData\AtpDisclosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ATP readiness is a record check over self-reported FDA listing data, so the caveat has
 * to travel with every surface that shows it and no surface may upgrade it into an FDA
 * verification. Copy drifts silently, so pin it here.
 */
class AtpDisclosureCopyTest extends TestCase
{
    /** @var list<string> */
    private const DISCLOSED_SURFACES = [
        'resources/views/filament/app/infolists/site-atp-readiness.blade.php',
        'app/Filament/App/Resources/Sites/RelationManagers/AtpLicensesRelationManager.php',
        'app/Filament/App/Resources/Sites/Tables/SitesTable.php',
        'app/Actions/Shipping/ValidateOutboundShippingSend.php',
        'app/Actions/Epcis/RecordAtpSoftWarning.php',
    ];

    /**
     * Phrases that would claim the FDA blessed a partner, or that state a partner is
     * unlicensed rather than that we hold no licence for it.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PHRASES = [
        'FDA verified',
        'FDA-verified',
        'verified ATP',
        'unlicensed trading partner',
    ];

    #[Test]
    public function the_caveat_names_its_source_and_denies_fda_approval(): void
    {
        foreach ([AtpDisclosure::SOURCE, AtpDisclosure::SHORT] as $copy) {
            $this->assertStringContainsStringIgnoringCase('self-reported', $copy);
            $this->assertStringContainsStringIgnoringCase('not FDA', $copy);
        }

        $this->assertStringContainsStringIgnoringCase('state board', AtpDisclosure::SOURCE);
    }

    #[Test]
    #[DataProvider('disclosedSurfaces')]
    public function every_atp_surface_carries_the_caveat(string $path): void
    {
        $this->assertStringContainsString(
            'AtpDisclosure::',
            $this->read($path),
            $path.' shows ATP readiness without the provenance caveat.',
        );
    }

    #[Test]
    #[DataProvider('disclosedSurfaces')]
    public function no_atp_surface_claims_fda_verification(string $path): void
    {
        $contents = $this->read($path);

        foreach (self::FORBIDDEN_PHRASES as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase(
                $phrase,
                $contents,
                $path.' overclaims with "'.$phrase.'".',
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disclosedSurfaces(): iterable
    {
        foreach (self::DISCLOSED_SURFACES as $path) {
            yield $path => [$path];
        }
    }

    private function read(string $path): string
    {
        $absolute = base_path($path);

        $this->assertFileExists($absolute);

        return (string) file_get_contents($absolute);
    }
}
