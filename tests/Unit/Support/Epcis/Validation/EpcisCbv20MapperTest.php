<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Epcis\Validation;

use App\Support\Epcis\Validation\EpcisCbv20Mapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisCbv20MapperTest extends TestCase
{
    #[Test]
    public function maps_short_forms_and_https_vocabulary_to_canonical_urns(): void
    {
        $this->assertSame(
            'urn:epcglobal:cbv:bizstep:shipping',
            EpcisCbv20Mapper::toCanonicalBizStep('shipping'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:bizstep:shipping',
            EpcisCbv20Mapper::toCanonicalBizStep('https://ref.gs1.org/cbv/BizStep-shipping'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:disp:active',
            EpcisCbv20Mapper::toCanonicalDisposition('active'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:disp:active',
            EpcisCbv20Mapper::toCanonicalDisposition('https://ref.gs1.org/cbv/Disp-active'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:bizstep:commissioning',
            EpcisCbv20Mapper::toCanonicalBizStep('urn:epcglobal:cbv:bizstep:commissioning'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:bizstep:returning',
            EpcisCbv20Mapper::toCanonicalBizStep('https://ref.gs1.org/cbv/BizStep-returning'),
        );
        $this->assertSame(
            'urn:epcglobal:cbv:disp:dispensed',
            EpcisCbv20Mapper::toCanonicalDisposition('dispensed'),
        );
    }
}
