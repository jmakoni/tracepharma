<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('epcis_document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->constrained('trading_partners')->cascadeOnDelete();
            $table->timestamp('published_at');
            $table->foreignId('published_by_connection_id')->nullable()->constrained('outbound_connections')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // One row per document+partner; republish clears revoked_at in app
            // (MariaDB lacks reliable partial unique indexes for where revoked_at is null).
            $table->unique(['epcis_document_id', 'trading_partner_id'], 'portal_pub_doc_partner_unique');
            $table->index(['trading_partner_id', 'published_at'], 'portal_pub_partner_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_publications');
    }
};
