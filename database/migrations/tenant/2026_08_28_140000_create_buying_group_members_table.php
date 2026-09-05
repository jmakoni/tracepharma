<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buying_group_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('external_ref')->nullable();
            $table->string('member_tenant_id', 36)->nullable();
            $table->string('status')->default('active');
            $table->string('contact_email')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('member_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_group_members');
    }
};
