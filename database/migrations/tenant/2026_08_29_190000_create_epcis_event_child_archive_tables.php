<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold copies of EPCIS event children so archive MOVE does not CASCADE-destroy TI/TS.
 * No FKs to hot epcis_events (same pattern as event_epcs_archive).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_parties_archive')) {
            Schema::create('event_parties_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('event_id')->index();
                $table->string('party_role', 16);
                $table->char('gln', 13)->nullable()->index();
                $table->string('gln_uri', 80)->nullable();
                $table->unsignedBigInteger('trading_partner_id')->nullable();
                $table->unsignedBigInteger('site_id')->nullable();
                $table->json('extra_json')->nullable();
            });
        }

        if (! Schema::hasTable('event_locations_archive')) {
            Schema::create('event_locations_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('event_id');
                $table->string('location_type', 32);
                $table->char('gln', 13)->nullable();
                $table->string('gln_uri', 80)->nullable();
                $table->string('name')->nullable();
                $table->string('street_address')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('country_code')->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->unsignedBigInteger('site_id')->nullable();
                $table->unsignedBigInteger('location_device_id')->nullable();
                $table->unsignedBigInteger('read_point_id')->nullable();
                $table->json('extra_json')->nullable();

                $table->index(['event_id', 'location_type']);
                $table->index('gln');
            });
        }

        if (! Schema::hasTable('event_biz_transactions_archive')) {
            Schema::create('event_biz_transactions_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('event_id')->index();
                $table->string('type_uri', 128);
                $table->text('value');
            });
        }

        if (! Schema::hasTable('event_quantities_archive')) {
            Schema::create('event_quantities_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('event_id');
                $table->string('role', 32);
                $table->string('epc_class', 191);
                $table->decimal('quantity', 14, 4)->nullable();
                $table->string('uom', 32)->nullable();

                $table->index(['event_id', 'role']);
            });
        }

        if (! Schema::hasTable('event_epc_ilmd_archive')) {
            Schema::create('event_epc_ilmd_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('epc_id')->index();
                $table->string('lot_number', 20)->nullable();
                $table->date('expiry_date')->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('best_before_date')->nullable();
                $table->string('additional_id', 64)->nullable();
                $table->json('extra_json')->nullable();

                $table->unique(['event_id', 'epc_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_epc_ilmd_archive');
        Schema::dropIfExists('event_quantities_archive');
        Schema::dropIfExists('event_biz_transactions_archive');
        Schema::dropIfExists('event_locations_archive');
        Schema::dropIfExists('event_parties_archive');
    }
};
