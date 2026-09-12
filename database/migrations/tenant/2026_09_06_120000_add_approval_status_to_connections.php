<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default 'approved' grandfathers existing connections; tenant UI create
        // paths set 'pending' explicitly so new connections require platform review.
        Schema::table('inbound_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('inbound_connections', 'approval_status')) {
                $table->string('approval_status', 16)->default('approved')->after('is_active');
                $table->index('approval_status');
            }

            if (! Schema::hasColumn('inbound_connections', 'approval_note')) {
                $table->text('approval_note')->nullable()->after('approval_status');
            }
        });

        Schema::table('outbound_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_connections', 'approval_status')) {
                $table->string('approval_status', 16)->default('approved')->after('is_active');
                $table->index('approval_status');
            }

            if (! Schema::hasColumn('outbound_connections', 'approval_note')) {
                $table->text('approval_note')->nullable()->after('approval_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inbound_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('inbound_connections', 'approval_note')) {
                $table->dropColumn('approval_note');
            }

            if (Schema::hasColumn('inbound_connections', 'approval_status')) {
                $table->dropIndex(['approval_status']);
                $table->dropColumn('approval_status');
            }
        });

        Schema::table('outbound_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('outbound_connections', 'approval_note')) {
                $table->dropColumn('approval_note');
            }

            if (Schema::hasColumn('outbound_connections', 'approval_status')) {
                $table->dropIndex(['approval_status']);
                $table->dropColumn('approval_status');
            }
        });
    }
};
