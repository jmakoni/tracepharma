<?php

use App\Enums\EpcisAuthoredKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('epcis_documents', 'authored_kind')) {
                $table->string('authored_kind', 32)->nullable()->after('direction');
            }
        });

        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->index(['direction', 'authored_kind']);
        });

        $this->backfillAuthoredKind();
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->dropIndex(['direction', 'authored_kind']);
        });

        Schema::table('epcis_documents', function (Blueprint $table) {
            if (Schema::hasColumn('epcis_documents', 'authored_kind')) {
                $table->dropColumn('authored_kind');
            }
        });
    }

    /**
     * Backfill authored_kind for existing outbound (authored) documents using the
     * same notes/filename heuristics as the historical directionDisplayLabel().
     */
    private function backfillAuthoredKind(): void
    {
        DB::table('epcis_documents')
            ->where('direction', 'outbound')
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
};
