<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_partners', 'atp_verified_at')) {
                $table->timestamp('atp_verified_at')->nullable()->index();
            }

            if (! Schema::hasColumn('trading_partners', 'atp_verified_by')) {
                $table->foreignId('atp_verified_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('trading_partners', 'atp_verification_source')) {
                $table->string('atp_verification_source', 32)->nullable();
            }

            if (! Schema::hasColumn('trading_partners', 'atp_verification_url')) {
                $table->string('atp_verification_url', 500)->nullable();
            }

            if (! Schema::hasColumn('trading_partners', 'atp_verification_note')) {
                $table->text('atp_verification_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('trading_partners', 'atp_verified_by')) {
                $table->dropConstrainedForeignId('atp_verified_by');
            }

            $columns = array_values(array_filter(
                ['atp_verified_at', 'atp_verification_source', 'atp_verification_url', 'atp_verification_note'],
                static fn (string $column): bool => Schema::hasColumn('trading_partners', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
