<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Epcis;

use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RecordOperationalEpcisCatalogSignalTest extends TestCase
{
    #[Test]
    public function l2_l3_reconciliation_failure_hook_is_removed(): void
    {
        $this->assertFalse(
            method_exists(RecordOperationalEpcisCatalogSignal::class, 'l2L3ReconciliationFailure'),
        );
    }

    #[Test]
    public function auto_decommission_failed_hook_is_removed(): void
    {
        $this->assertFalse(
            method_exists(RecordOperationalEpcisCatalogSignal::class, 'autoDecommissionFailed'),
        );
    }
}
