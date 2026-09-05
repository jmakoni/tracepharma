<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\ScheduledSessionChip;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledSessionChipTest extends TestCase
{
    #[Test]
    public function label_formats_schedule_and_optional_missing_dea_suffix(): void
    {
        $this->assertNull(ScheduledSessionChip::label(null, false, 'No DEA on seller'));
        $this->assertSame('CII', ScheduledSessionChip::label('CII', false, 'No DEA on seller'));
        $this->assertSame(
            'CII · No DEA on seller',
            ScheduledSessionChip::label('CII', true, 'No DEA on seller'),
        );
        $this->assertSame('CIII', ScheduledSessionChip::label('CIII', true, ''));
    }

    #[Test]
    public function badge_color_is_danger_for_cii_and_warning_for_lower_schedules(): void
    {
        $this->assertSame('danger', ScheduledSessionChip::badgeColor('CII'));
        $this->assertSame('warning', ScheduledSessionChip::badgeColor('CIII'));
        $this->assertSame('warning', ScheduledSessionChip::badgeColor('CIV'));
        $this->assertSame('warning', ScheduledSessionChip::badgeColor('CV'));
        $this->assertNull(ScheduledSessionChip::badgeColor(null));
    }
}
