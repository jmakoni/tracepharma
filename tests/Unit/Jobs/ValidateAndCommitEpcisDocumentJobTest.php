<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Actions\Epcis\RecordEpcisValidationFailure;
use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Jobs\ValidateAndCommitEpcisDocumentJob;
use App\Services\Epcis\EpcisIngestionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ValidateAndCommitEpcisDocumentJobTest extends TestCase
{
    #[Test]
    public function handle_does_not_inject_second_validate_epcis12_document_pass(): void
    {
        $params = (new ReflectionMethod(ValidateAndCommitEpcisDocumentJob::class, 'handle'))->getParameters();
        $typeNames = array_map(
            static fn (\ReflectionParameter $p): ?string => $p->getType()?->__toString(),
            $params,
        );

        $this->assertContains(EpcisIngestionService::class, $typeNames);
        $this->assertContains(RunDomainEpcisHardGate::class, $typeNames);
        $this->assertContains(RecordEpcisValidationFailure::class, $typeNames);
        $this->assertNotContains(ValidateEpcis12Document::class, $typeNames);
    }

    #[Test]
    public function job_exposes_failed_hardening_hook(): void
    {
        $this->assertTrue(method_exists(ValidateAndCommitEpcisDocumentJob::class, 'failed'));
    }

    #[Test]
    public function unique_id_format_is_documented_on_method_body(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/Jobs/ValidateAndCommitEpcisDocumentJob.php',
        );

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            "return 'validate-commit:'.\$this->tenant->getKey().':'.\$this->documentId;",
            $source,
        );
    }

    #[Test]
    public function validated_documents_still_run_domain_gate_without_reprocess(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/Jobs/ValidateAndCommitEpcisDocumentJob.php',
        );

        $this->assertNotFalse($source);
        $this->assertStringContainsString('$alreadyValidated = $document->status === \'validated\';', $source);
        $this->assertStringContainsString('if (! $alreadyValidated) {', $source);
        $this->assertStringContainsString('$ingestion->process($document);', $source);
        $this->assertStringContainsString('$hardGate->handle($document);', $source);
        // Domain handle must appear after the alreadyValidated branch, not only inside !alreadyValidated.
        $processPos = strpos($source, '$ingestion->process($document);');
        $gatePos = strpos($source, '$hardGate->handle($document);');
        $this->assertNotFalse($processPos);
        $this->assertNotFalse($gatePos);
        $this->assertGreaterThan($processPos, $gatePos);
    }
}
