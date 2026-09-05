<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('l3_lot_feeds')) {
            Schema::create('l3_lot_feeds', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id')->unique();
                $table->string('file_sha256', 64)->index();
                $table->string('payload_disk');
                $table->string('payload_path');
                // received -> processing -> accepted / failed
                $table->string('status', 32)->default('received');
                $table->text('error_summary')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('serialization_lots')) {
            Schema::create('serialization_lots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('feed_id')->nullable()->constrained('l3_lot_feeds')->nullOnDelete();
                // No FK: epcis_documents id type is authoritative there, not duplicated here.
                $table->unsignedBigInteger('epcis_document_id')->nullable();
                $table->string('lot_number');
                $table->string('ndc')->nullable();
                $table->string('unit_gtin14', 14)->nullable();
                $table->string('case_gtin14', 14)->nullable();
                $table->string('product_name')->nullable();
                $table->date('expire_date')->nullable();
                $table->date('mfg_date')->nullable();
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->string('line_name')->nullable();
                $table->dateTime('lot_processed_at')->nullable();
                $table->string('timezone_offset', 16)->nullable();
                $table->dateTime('lot_info_saved_at')->nullable();
                $table->json('lot_control_data')->nullable();
                $table->unsignedInteger('pallet_count')->default(0);
                $table->unsignedInteger('case_count')->default(0);
                $table->unsignedInteger('unit_count')->default(0);
                $table->string('status')->default('accepted');
                $table->timestamps();

                // MVP: one row per lot number per unit GTIN. MySQL/MariaDB treat
                // NULL as distinct in a unique index, so two rows sharing a lot
                // number with both unit_gtin14 NULL would not collide here — the
                // upsert path (ReceiveGuardianLotFeed) always resolves unit_gtin14
                // from LotControlData before writing, so that gap is not expected
                // in practice.
                $table->unique(['lot_number', 'unit_gtin14'], 'serialization_lots_lot_gtin_unique');
                $table->index('epcis_document_id');
            });
        }

        if (! Schema::hasTable('serialization_lot_container_fields')) {
            Schema::create('serialization_lot_container_fields', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lot_id')->constrained('serialization_lots')->cascadeOnDelete();
                $table->string('epc_uri', 128);
                $table->string('container_type', 16); // Pallet|Case|Bottle
                $table->string('parent_epc_uri', 128)->nullable();
                // Fields tab enrichment: ContainerId[@Name] -> value (GS1_XML, RawSeq, URI, Helper2D, ...).
                // Never select on list pages.
                $table->json('fields')->nullable();
                $table->timestamps();

                $table->unique(['lot_id', 'epc_uri'], 'serialization_lot_container_fields_lot_epc_unique');
                $table->index('epc_uri');
                $table->index('lot_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serialization_lot_container_fields');
        Schema::dropIfExists('serialization_lots');
        Schema::dropIfExists('l3_lot_feeds');
    }
};
