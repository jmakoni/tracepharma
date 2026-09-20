<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inbound_shipments')) {
            Schema::table('inbound_shipments', function (Blueprint $table): void {
                // Status vocabulary: expected|open|complete|cancelled
                // Existing default 'open' = order-open; ingest with no confirms may set 'expected'.
                if (! Schema::hasColumn('inbound_shipments', 'expected_parent_count')) {
                    $table->unsignedInteger('expected_parent_count')->default(0)->after('document_count');
                }
                if (! Schema::hasColumn('inbound_shipments', 'confirmed_parent_count')) {
                    $table->unsignedInteger('confirmed_parent_count')->default(0)->after('expected_parent_count');
                }
                if (! Schema::hasColumn('inbound_shipments', 'expected_each_count')) {
                    $table->unsignedInteger('expected_each_count')->default(0)->after('confirmed_parent_count');
                }
                if (! Schema::hasColumn('inbound_shipments', 'confirmed_each_count')) {
                    $table->unsignedInteger('confirmed_each_count')->default(0)->after('expected_each_count');
                }
                if (! Schema::hasColumn('inbound_shipments', 'unexpected_count')) {
                    $table->unsignedInteger('unexpected_count')->default(0)->after('confirmed_each_count');
                }
            });
        }

        if (! Schema::hasTable('inbound_expected_lines')) {
            Schema::create('inbound_expected_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('inbound_shipment_id')
                    ->constrained('inbound_shipments')
                    ->cascadeOnDelete();
                $table->foreignId('epc_id')
                    ->constrained('epcs')
                    ->cascadeOnDelete();
                $table->foreignId('parent_epc_id')
                    ->nullable()
                    ->constrained('epcs')
                    ->nullOnDelete();
                // parent|child
                $table->string('line_role', 16);
                // expected|confirmed|unexpected|cancelled
                $table->string('status', 16)->default('expected');
                // epcis_ship|epcis_aggregation|scan_first|addendum
                $table->string('source', 32)->nullable();
                $table->unsignedInteger('expected_class_qty')->nullable();
                $table->dateTime('confirmed_at', 6)->nullable();
                $table->foreignId('confirmed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->foreignId('confirmed_receiving_session_id')
                    ->nullable()
                    ->constrained('receiving_sessions')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['inbound_shipment_id', 'epc_id']);
                $table->index('status');
                $table->index(['inbound_shipment_id', 'status']);
                $table->index('epc_id');
            });
        }

        if (Schema::hasTable('receiving_sessions')) {
            // Ensure a non-unique index exists before dropping UNIQUE so the FK can stay valid.
            if (Schema::hasColumn('receiving_sessions', 'epcis_document_id')
                && ! Schema::hasIndex('receiving_sessions', 'receiving_sessions_epcis_document_id_index')) {
                Schema::table('receiving_sessions', function (Blueprint $table): void {
                    $table->index('epcis_document_id', 'receiving_sessions_epcis_document_id_index');
                });
            }

            if (Schema::hasIndex('receiving_sessions', 'receiving_sessions_epcis_document_id_unique')) {
                try {
                    Schema::table('receiving_sessions', function (Blueprint $table): void {
                        $table->dropUnique('receiving_sessions_epcis_document_id_unique');
                    });
                } catch (Throwable) {
                    // Index may already be gone or named differently on some tenants.
                }
            }

            if (Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')
                && ! Schema::hasIndex('receiving_sessions', 'receiving_sessions_inbound_shipment_id_status_index')) {
                Schema::table('receiving_sessions', function (Blueprint $table): void {
                    $table->index(
                        ['inbound_shipment_id', 'status'],
                        'receiving_sessions_inbound_shipment_id_status_index'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('receiving_sessions')) {
            if (Schema::hasIndex('receiving_sessions', 'receiving_sessions_inbound_shipment_id_status_index')) {
                Schema::table('receiving_sessions', function (Blueprint $table): void {
                    $table->dropIndex('receiving_sessions_inbound_shipment_id_status_index');
                });
            }

            if (Schema::hasColumn('receiving_sessions', 'epcis_document_id')
                && ! Schema::hasIndex('receiving_sessions', 'receiving_sessions_epcis_document_id_unique')) {
                try {
                    Schema::table('receiving_sessions', function (Blueprint $table): void {
                        $table->unique('epcis_document_id', 'receiving_sessions_epcis_document_id_unique');
                    });
                } catch (Throwable) {
                    // Duplicate epcis_document_id rows would block restoring UNIQUE.
                }
            }

            if (Schema::hasIndex('receiving_sessions', 'receiving_sessions_epcis_document_id_index')
                && Schema::hasIndex('receiving_sessions', 'receiving_sessions_epcis_document_id_unique')) {
                Schema::table('receiving_sessions', function (Blueprint $table): void {
                    $table->dropIndex('receiving_sessions_epcis_document_id_index');
                });
            }
        }

        Schema::dropIfExists('inbound_expected_lines');

        if (Schema::hasTable('inbound_shipments')) {
            Schema::table('inbound_shipments', function (Blueprint $table): void {
                foreach ([
                    'unexpected_count',
                    'confirmed_each_count',
                    'expected_each_count',
                    'confirmed_parent_count',
                    'expected_parent_count',
                ] as $column) {
                    if (Schema::hasColumn('inbound_shipments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
