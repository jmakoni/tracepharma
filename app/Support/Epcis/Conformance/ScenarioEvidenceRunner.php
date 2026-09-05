<?php

declare(strict_types=1);

namespace App\Support\Epcis\Conformance;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Support\Epcis\EpcisTempFile;
use Throwable;

/**
 * Runs ScenarioMatrix fixtures through the live ingest/validate path.
 *
 * Honesty: internal evidence only — NOT TraceReady / Gateway Checker / GS1 Trustmark certified.
 */
final class ScenarioEvidenceRunner
{
    public function __construct(
        private readonly IngestEpcisXmlDocument $ingest,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     actual: 'pass'|'fail',
     *     matched: bool,
     *     status: string|null,
     *     document_id: int|null,
     *     ig_note: string,
     *     error: string|null
     * }>
     */
    public function run(): array
    {
        $rows = [];

        foreach (ScenarioMatrix::scenarios() as $scenario) {
            $rows[] = $this->runScenario($scenario);
        }

        return $rows;
    }

    /**
     * @param  array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     uuid_placeholder: string,
     *     ig_note: string
     * }  $scenario
     * @return array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     actual: 'pass'|'fail',
     *     matched: bool,
     *     status: string|null,
     *     document_id: int|null,
     *     ig_note: string,
     *     error: string|null
     * }
     */
    private function runScenario(array $scenario): array
    {
        $absoluteFixture = base_path($scenario['fixture']);
        $tmp = null;

        try {
            if (! is_file($absoluteFixture)) {
                return $this->row($scenario, 'fail', null, null, "Missing fixture: {$scenario['fixture']}");
            }

            $xml = file_get_contents($absoluteFixture);
            if ($xml === false) {
                return $this->row($scenario, 'fail', null, null, "Unable to read fixture: {$scenario['fixture']}");
            }

            $uuid = (string) str()->uuid();
            $xml = str_replace($scenario['uuid_placeholder'], $uuid, $xml);
            $tmp = EpcisTempFile::write($xml, basename($scenario['fixture']), 'epcis_scenario_');

            $document = $this->ingest->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($scenario['fixture']),
            ]);

            $status = (string) $document->status;
            // Pass = validated (or any non-error status); fail = error.
            $actual = $status === 'error' ? 'fail' : 'pass';

            return $this->row(
                $scenario,
                $actual,
                $status,
                (int) $document->getKey(),
                null,
            );
        } catch (Throwable $e) {
            return $this->row($scenario, 'fail', null, null, $e->getMessage());
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param  array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     uuid_placeholder: string,
     *     ig_note: string
     * }  $scenario
     * @return array{
     *     id: string,
     *     title: string,
     *     fixture: string,
     *     expect: 'pass'|'fail',
     *     actual: 'pass'|'fail',
     *     matched: bool,
     *     status: string|null,
     *     document_id: int|null,
     *     ig_note: string,
     *     error: string|null
     * }
     */
    private function row(
        array $scenario,
        string $actual,
        ?string $status,
        ?int $documentId,
        ?string $error,
    ): array {
        return [
            'id' => $scenario['id'],
            'title' => $scenario['title'],
            'fixture' => $scenario['fixture'],
            'expect' => $scenario['expect'],
            'actual' => $actual,
            'matched' => $scenario['expect'] === $actual,
            'status' => $status,
            'document_id' => $documentId,
            'ig_note' => $scenario['ig_note'],
            'error' => $error,
        ];
    }
}
