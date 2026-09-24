<?php

namespace Tests\Unit\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Models\TradingPartner;
use App\Support\Epcis\ResolveOutboundEpcisGuideline;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOutboundEpcisGuidelineTest extends TestCase
{
    #[Test]
    public function missing_partner_falls_back_to_r12(): void
    {
        $this->assertSame(EpcisGuideline::R12, ResolveOutboundEpcisGuideline::forPartner(null));
    }

    #[Test]
    public function partner_r12_state_is_honored(): void
    {
        $partner = new TradingPartner(['epcis_guideline' => EpcisGuideline::R12]);

        $this->assertSame(EpcisGuideline::R12, ResolveOutboundEpcisGuideline::forPartner($partner));
    }

    #[Test]
    public function factory_default_is_r12(): void
    {
        $this->assertSame(EpcisGuideline::R12, TradingPartner::factory()->make()->epcis_guideline);
    }
}
