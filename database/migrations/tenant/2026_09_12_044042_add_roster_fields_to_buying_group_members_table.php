<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buying_group_members', function (Blueprint $table): void {
            $table->string('dea_number')->nullable()->after('contact_email');
            $table->string('npi')->nullable()->after('dea_number');
            $table->string('state_license_ref')->nullable()->after('npi');
            $table->string('primary_gln', 13)->nullable()->after('state_license_ref');
            $table->string('affiliation_code')->nullable()->after('primary_gln');
            $table->string('program_sku')->nullable()->after('affiliation_code');
            $table->text('notes')->nullable()->after('program_sku');
            $table->unsignedInteger('sites_count')->nullable()->after('notes');

            $table->index('dea_number');
            $table->index('primary_gln');
            $table->index('affiliation_code');
        });
    }

    public function down(): void
    {
        Schema::table('buying_group_members', function (Blueprint $table): void {
            $table->dropIndex(['dea_number']);
            $table->dropIndex(['primary_gln']);
            $table->dropIndex(['affiliation_code']);
            $table->dropColumn([
                'dea_number',
                'npi',
                'state_license_ref',
                'primary_gln',
                'affiliation_code',
                'program_sku',
                'notes',
                'sites_count',
            ]);
        });
    }
};
