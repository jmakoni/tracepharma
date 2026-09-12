<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buying_group_partner_facts', function (Blueprint $table): void {
            $table->id();
            $table->string('member_tenant_id', 36);
            $table->string('partner_key', 64);
            $table->string('partner_name');
            $table->string('partner_gln', 32)->nullable();
            $table->string('license_status', 40);
            $table->date('expires_at')->nullable();
            $table->date('as_of');
            $table->timestamps();

            $table->unique(
                ['member_tenant_id', 'partner_key', 'as_of'],
                'bg_partner_facts_tenant_key_as_of_uq',
            );
            $table->index(['as_of', 'license_status']);
            $table->index('member_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_group_partner_facts');
    }
};
