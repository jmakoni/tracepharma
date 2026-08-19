<?php

namespace Tests\Unit\Places;

use App\Support\Places\PlacesResultSelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PlacesResultSelectorTest extends TestCase
{
    private const HQ_PLACE_ID = 'ChIJr3puBZu4w4kRX69mJZhcXqI';

    private const HAUPPAUGE_PLACE_ID = 'ChIJewsrOzgw6IkRRfN41uSmW_8';

    /**
     * @return list<array<string, mixed>>
     */
    private function amnealResults(): array
    {
        $fixturePath = dirname(__DIR__, 2).'/fixtures/places/amneal.json';

        $payload = json_decode((string) file_get_contents($fixturePath), true);

        return $payload['data'];
    }

    #[Test]
    public function selects_bridgewater_corporate_office_as_hq(): void
    {
        $selection = (new PlacesResultSelector)->select('Amneal Pharmaceuticals LLC', $this->amnealResults());

        $this->assertNotNull($selection['hq']);
        $this->assertSame(self::HQ_PLACE_ID, $selection['hq']['place_id']);
        $this->assertSame('Bridgewater', $selection['hq']['city']);
    }

    #[Test]
    public function rejects_the_permanently_closed_result(): void
    {
        $selection = (new PlacesResultSelector)->select('Amneal Pharmaceuticals LLC', $this->amnealResults());

        $this->assertSame(1, $selection['rejected']);

        $placeIds = array_column($selection['sites'], 'place_id');
        $this->assertNotContains(self::HAUPPAUGE_PLACE_ID, $placeIds);
    }

    #[Test]
    public function keeps_at_least_two_other_sites_that_are_not_hq(): void
    {
        $selection = (new PlacesResultSelector)->select('Amneal Pharmaceuticals LLC', $this->amnealResults());

        $this->assertGreaterThanOrEqual(2, count($selection['sites']));

        foreach ($selection['sites'] as $site) {
            $this->assertNotSame(self::HQ_PLACE_ID, $site['place_id']);
        }
    }

    #[Test]
    public function transportation_and_pharmacy_results_are_not_selected_as_hq(): void
    {
        $selection = (new PlacesResultSelector)->select('Amneal Pharmaceuticals LLC', $this->amnealResults());

        $siteTypes = array_map(
            static fn (string $type): string => strtolower($type),
            array_column($selection['sites'], 'type')
        );

        $this->assertContains('transportation service', $siteTypes);
        $this->assertContains('pharmacy', $siteTypes);
        $this->assertNotSame('transportation service', strtolower((string) $selection['hq']['type']));
        $this->assertNotSame('pharmacy', strtolower((string) $selection['hq']['type']));
    }

    #[Test]
    public function unrelated_business_names_are_rejected(): void
    {
        $selection = (new PlacesResultSelector)->select('Amneal Pharmaceuticals LLC', [
            [
                'place_id' => 'unrelated-1',
                'name' => 'Totally Different Wholesale Co',
                'type' => 'corporate office',
                'street_address' => '1 Main St',
                'city' => 'Anywhere',
                'state' => 'TX',
                'zipcode' => '75001',
                'latitude' => 32.0,
                'longitude' => -96.0,
                'business_status' => 'OPEN',
                'verified' => true,
            ],
        ]);

        $this->assertNull($selection['hq']);
        $this->assertSame(1, $selection['rejected']);
    }

    #[Test]
    public function accepts_zenskin_when_api_name_is_spaced_zen_skin(): void
    {
        $selection = (new PlacesResultSelector)->select('Zenskin Co. Ltd.', [
            $this->openPlace('zenskin-1', 'Zen Skin'),
        ]);

        $this->assertNotNull($selection['hq']);
        $this->assertSame('zenskin-1', $selection['hq']['place_id']);
        $this->assertSame(0, $selection['rejected']);
    }

    #[Test]
    public function accepts_tekt_tone_when_api_name_collapses_hyphenation(): void
    {
        $selection = (new PlacesResultSelector)->select('TEKT TONE LABS LLC', [
            $this->openPlace('tektone-1', 'TekTone Sound & Signal Mfg., Inc.'),
        ]);

        $this->assertNotNull($selection['hq']);
        $this->assertSame('tektone-1', $selection['hq']['place_id']);
    }

    #[Test]
    public function accepts_when_industry_tokens_are_stripped_to_same_core(): void
    {
        $selection = (new PlacesResultSelector)->select('Brookfield Pharmaceuticals, LLC', [
            $this->openPlace('brookfield-1', 'Brookfield Pharma'),
        ]);

        $this->assertNotNull($selection['hq']);
        $this->assertSame('brookfield-1', $selection['hq']['place_id']);
    }

    #[Test]
    public function rejects_retail_noise_when_only_generic_medical_tokens_overlap(): void
    {
        $selection = (new PlacesResultSelector)->select('Wellbeing Medical Supply', [
            $this->openPlace('shoppe-1', 'The Medicine Shoppe Pharmacy'),
        ]);

        $this->assertNull($selection['hq']);
        $this->assertSame(1, $selection['rejected']);
    }

    #[Test]
    public function rejects_when_cores_are_similar_but_distinct_brands(): void
    {
        $selection = (new PlacesResultSelector)->select('Hikma Pharmaceuticals', [
            $this->openPlace('hickman-1', 'Hickman Medical'),
        ]);

        $this->assertNull($selection['hq']);
        $this->assertSame(1, $selection['rejected']);
    }

    #[Test]
    public function rejects_near_miss_brand_with_different_prefix_letters(): void
    {
        $selection = (new PlacesResultSelector)->select('Metcure Pharmaceuticals, Inc.', [
            $this->openPlace('mecure-1', 'MeCure Industries PLC'),
        ]);

        $this->assertNull($selection['hq']);
        $this->assertSame(1, $selection['rejected']);
    }

    /**
     * @return array<string, mixed>
     */
    private function openPlace(string $placeId, string $name): array
    {
        return [
            'place_id' => $placeId,
            'name' => $name,
            'type' => 'corporate office',
            'street_address' => '1 Main St',
            'city' => 'Anywhere',
            'state' => 'TX',
            'zipcode' => '75001',
            'latitude' => 32.0,
            'longitude' => -96.0,
            'business_status' => 'OPEN',
            'verified' => true,
        ];
    }
}
