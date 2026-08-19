<?php

use App\Enums\EpcisAuthoredKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill authored_kind=shipping for outbound docs that the initial
 * authored_kind migration left null (shipping had no distinct heuristic).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('epcis_documents')
            ->where('direction', 'outbound')
            ->whereNull('authored_kind')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $kind = EpcisAuthoredKind::inferAuthoredKindFromNotesAndFilename(
                        (string) ($row->notes ?? ''),
                        (string) ($row->original_filename ?? ''),
                    );

                    if ($kind === null) {
                        continue;
                    }

                    DB::table('epcis_documents')
                        ->where('id', $row->id)
                        ->update(['authored_kind' => $kind->value]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
