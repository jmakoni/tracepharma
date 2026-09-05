<?php

namespace Database\Seeders;

use App\Enums\ExceptionSeverity;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Exceptions\ExceptionSlaRule;
use Illuminate\Database\Seeder;

class ExceptionCaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ExceptionTypeSeeder::class);

        self::ensureResolutionCatalog();
        $this->seedSlaRules();
    }

    public static function ensureResolutionCatalog(): void
    {
        foreach ([
            ['code' => 'partner_data_error', 'name' => 'Partner data error'],
            ['code' => 'internal_mapping_error', 'name' => 'Internal mapping / master data error'],
            ['code' => 'file_format_issue', 'name' => 'File format / EPCIS schema issue'],
            ['code' => 'process_timing', 'name' => 'Process timing / sequence issue'],
            ['code' => 'duplicate_transmission', 'name' => 'Duplicate transmission'],
            ['code' => 'unknown', 'name' => 'Unknown / under investigation'],
        ] as $cause) {
            ExceptionRootCause::query()->updateOrCreate(
                ['code' => $cause['code']],
                ['name' => $cause['name'], 'is_active' => true],
            );
        }

        foreach ([
            ['code' => 'request_partner_correction', 'name' => 'Request partner correction'],
            ['code' => 'reprocess_document', 'name' => 'Reprocess document'],
            ['code' => 'update_master_data', 'name' => 'Update master data'],
            ['code' => 'accept_with_waiver', 'name' => 'Accept with documented waiver'],
            ['code' => 'quarantine_product', 'name' => 'Quarantine product'],
            ['code' => 'no_action_false_positive', 'name' => 'No action — false positive'],
        ] as $action) {
            ExceptionAction::query()->updateOrCreate(
                ['code' => $action['code']],
                ['name' => $action['name'], 'is_active' => true],
            );
        }
    }

    private function seedSlaRules(): void
    {
        $slaBySeverity = [
            ExceptionSeverity::Critical->value => [4, 24],
            ExceptionSeverity::High->value => [8, 48],
            ExceptionSeverity::Medium->value => [24, 120],
            ExceptionSeverity::Low->value => [48, 240],
        ];

        foreach ($slaBySeverity as $severity => [$response, $resolve]) {
            ExceptionSlaRule::query()->updateOrCreate(
                [
                    'exception_type_id' => null,
                    'severity' => $severity,
                ],
                [
                    'first_response_hours' => $response,
                    'resolve_hours' => $resolve,
                    'is_active' => true,
                ],
            );
        }
    }
}
