<?php

namespace Tests\Unit\Filament;

use App\Filament\App\Resources\Fda3911Reports\Schemas\Fda3911ReportForm;
use App\Filament\App\Resources\TracingRequests\Schemas\TracingRequestForm;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Fda3911ReportFormTest extends TestCase
{
    #[Test]
    public function exception_picker_uses_the_same_site_access_constraint_as_tracing_requests(): void
    {
        $fdaForm = file_get_contents((new \ReflectionClass(Fda3911ReportForm::class))->getFileName());
        $tracingForm = file_get_contents((new \ReflectionClass(TracingRequestForm::class))->getFileName());

        $this->assertIsString($fdaForm);
        $this->assertIsString($tracingForm);
        $this->assertStringContainsString('SiteAccess::constrainExceptionCases', $fdaForm);
        $this->assertStringContainsString('modifyQueryUsing:', $fdaForm);
        $this->assertStringContainsString('SiteAccess::constrainExceptionCases', $tracingForm);
    }
}
