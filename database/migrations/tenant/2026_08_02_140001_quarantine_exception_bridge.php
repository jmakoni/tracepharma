<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quarantine_holds')) {
            Schema::table('quarantine_holds', function (Blueprint $table) {
                if (! Schema::hasColumn('quarantine_holds', 'exception_id')) {
                    $table->foreignId('exception_id')
                        ->nullable()
                        ->after('document_id')
                        ->constrained('exceptions')
                        ->nullOnDelete();
                    $table->index(['exception_id', 'status']);
                }

                if (! Schema::hasColumn('quarantine_holds', 'closed_reason')) {
                    $table->string('closed_reason', 255)->nullable()->after('closed_at');
                }
            });
        }

        if (Schema::hasTable('exceptions')) {
            Schema::table('exceptions', function (Blueprint $table) {
                if (! Schema::hasColumn('exceptions', 'share_uuid')) {
                    $table->uuid('share_uuid')->nullable()->unique()->after('serials_affected');
                }

                if (! Schema::hasColumn('exceptions', 'share_expires_at')) {
                    $table->timestamp('share_expires_at')->nullable()->after('share_uuid');
                }

                if (! Schema::hasColumn('exceptions', 'disposition')) {
                    $table->string('disposition', 32)->nullable()->after('share_expires_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quarantine_holds')) {
            Schema::table('quarantine_holds', function (Blueprint $table) {
                if (Schema::hasColumn('quarantine_holds', 'exception_id')) {
                    $table->dropForeign(['exception_id']);
                    $table->dropIndex(['exception_id', 'status']);
                    $table->dropColumn('exception_id');
                }

                if (Schema::hasColumn('quarantine_holds', 'closed_reason')) {
                    $table->dropColumn('closed_reason');
                }
            });
        }

        if (Schema::hasTable('exceptions')) {
            Schema::table('exceptions', function (Blueprint $table) {
                if (Schema::hasColumn('exceptions', 'share_uuid')) {
                    $table->dropUnique(['share_uuid']);
                    $table->dropColumn('share_uuid');
                }
                if (Schema::hasColumn('exceptions', 'share_expires_at')) {
                    $table->dropColumn('share_expires_at');
                }
                if (Schema::hasColumn('exceptions', 'disposition')) {
                    $table->dropColumn('disposition');
                }
            });
        }
    }
};
