<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold copy of aggregation_links for archive MOVE of aged establishing events.
 * Hot hierarchy rows stay; established_by_event_id becomes nullable so MOVE can
 * clear the FK after copying provenance into the archive table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aggregation_links')
            && Schema::hasColumn('aggregation_links', 'established_by_event_id')
        ) {
            Schema::table('aggregation_links', function (Blueprint $table): void {
                $table->unsignedBigInteger('established_by_event_id')->nullable()->change();
            });
        }

        if (! Schema::hasTable('aggregation_links_archive')) {
            Schema::create('aggregation_links_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('parent_epc_id')->index();
                $table->unsignedBigInteger('child_epc_id')->index();
                $table->unsignedBigInteger('established_by_event_id')->nullable()->index();
                $table->string('link_type', 16)->default('aggregation');
                $table->dateTime('valid_from', 6);
                $table->dateTime('valid_to', 6)->nullable();
                $table->timestamp('created_at', 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregation_links_archive');

        if (Schema::hasTable('aggregation_links')
            && Schema::hasColumn('aggregation_links', 'established_by_event_id')
        ) {
            Schema::table('aggregation_links', function (Blueprint $table): void {
                $table->unsignedBigInteger('established_by_event_id')->nullable(false)->change();
            });
        }
    }
};
