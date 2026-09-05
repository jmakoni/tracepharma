<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Epcis\Conformance\ScenarioEvidenceRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportEpcisScenarioEvidenceCommand extends Command
{
    private const HONESTY = 'NOT TraceReady / Gateway Checker / GS1 Trustmark certified';

    protected $signature = 'epcis:export-scenario-evidence
        {--tenant= : Tenant id (required)}
        {--output= : Output directory (default: storage/app/evidence/epcis-scenarios)}
        {--format=all : md|junit|all}';

    protected $description = 'Export internal EPCIS scenario evidence (Markdown + JUnit) for a tenant';

    public function handle(ScenarioEvidenceRunner $runner): int
    {
        $tenantId = $this->option('tenant');
        if (! filled($tenantId)) {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['md', 'junit', 'all'], true)) {
            $this->error('Invalid --format. Use md, junit, or all.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        $outputDir = (string) ($this->option('output') ?: storage_path('app/evidence/epcis-scenarios'));
        File::ensureDirectoryExists($outputDir);

        tenancy()->initialize($tenant);

        try {
            $rows = $runner->run();
            $stamp = now()->format('Ymd-His');
            $mismatches = collect($rows)->where('matched', false)->values();

            if (in_array($format, ['md', 'all'], true)) {
                $mdPath = $outputDir.'/epcis-scenario-evidence-'.$stamp.'.md';
                File::put($mdPath, $this->renderMarkdown($tenant, $rows, $mismatches->count()));
                $this->info("Wrote {$mdPath}");
            }

            if (in_array($format, ['junit', 'all'], true)) {
                $junitPath = $outputDir.'/epcis-scenario-evidence-'.$stamp.'.xml';
                File::put($junitPath, $this->renderJunit($rows));
                $this->info("Wrote {$junitPath}");
            }

            $this->table(
                ['id', 'expect', 'actual', 'matched', 'status'],
                collect($rows)->map(fn (array $r): array => [
                    $r['id'],
                    $r['expect'],
                    $r['actual'],
                    $r['matched'] ? 'yes' : 'NO',
                    $r['status'] ?? ($r['error'] ?? '—'),
                ])->all(),
            );

            if ($mismatches->isNotEmpty()) {
                $this->error(sprintf(
                    '%d scenario(s) did not match expected outcome. Evidence is internal only — %s.',
                    $mismatches->count(),
                    self::HONESTY,
                ));

                return self::FAILURE;
            }

            $this->comment('All scenarios matched expected outcomes. '.$this->honestyLine());

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderMarkdown(Tenant $tenant, array $rows, int $mismatchCount): string
    {
        $lines = [
            '# EPCIS scenario evidence',
            '',
            '> **Honesty:** '.$this->honestyLine(),
            '>',
            '> This pack is **internal** DSCSA / GS1 US IG scenario evidence from TracePharma fixtures and validation.',
            '> It is **not** a TraceReady, Gateway Checker, or GS1 Trustmark certification result.',
            '',
            '- Generated: '.now()->toIso8601String(),
            '- Tenant: `'.$tenant->getKey().'` ('.$tenant->name.')',
            '- Scenarios: '.count($rows),
            '- Expected-outcome mismatches: '.$mismatchCount,
            '',
            '| Scenario | Title | Expect | Actual | Matched | Status | Fixture |',
            '|---|---|---|---|---|---|---|',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | `%s` |',
                $row['id'],
                str_replace('|', '\\|', (string) $row['title']),
                $row['expect'],
                $row['actual'],
                $row['matched'] ? 'yes' : '**NO**',
                $row['status'] ?? ($row['error'] ?? '—'),
                $row['fixture'],
            );
        }

        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        foreach ($rows as $row) {
            $lines[] = sprintf('- **%s:** %s', $row['id'], $row['ig_note']);
            if (! empty($row['error'])) {
                $lines[] = sprintf('  - Error: %s', $row['error']);
            }
        }
        $lines[] = '';
        $lines[] = '_'.$this->honestyLine().'_';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderJunit(array $rows): string
    {
        $failures = collect($rows)->where('matched', false)->count();
        $tests = count($rows);
        $suiteName = 'epcis-scenario-evidence';

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<testsuite name="%s" tests="%d" failures="%d" errors="0" skipped="0">',
                htmlspecialchars($suiteName, ENT_XML1),
                $tests,
                $failures,
            ),
            '  <!-- '.htmlspecialchars($this->honestyLine(), ENT_XML1).' -->',
        ];

        foreach ($rows as $row) {
            $classname = 'App.Support.Epcis.Conformance.ScenarioMatrix';
            $name = (string) $row['id'];
            $xml[] = sprintf(
                '  <testcase classname="%s" name="%s">',
                htmlspecialchars($classname, ENT_XML1),
                htmlspecialchars($name, ENT_XML1),
            );

            if (! $row['matched']) {
                $message = sprintf(
                    'Expected %s, got %s (status=%s)',
                    $row['expect'],
                    $row['actual'],
                    $row['status'] ?? ($row['error'] ?? 'unknown'),
                );
                $xml[] = sprintf(
                    '    <failure message="%s">%s</failure>',
                    htmlspecialchars($message, ENT_XML1),
                    htmlspecialchars($message."\n".$this->honestyLine(), ENT_XML1),
                );
            }

            $xml[] = '  </testcase>';
        }

        $xml[] = '</testsuite>';
        $xml[] = '';

        return implode("\n", $xml);
    }

    private function honestyLine(): string
    {
        return self::HONESTY;
    }
}
