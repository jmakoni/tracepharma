<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_printers', function (Blueprint $table): void {
            $table->string('ip_address')->nullable()->change();
            $table->unsignedInteger('port')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('label_printers', function (Blueprint $table): void {
            $table->string('ip_address')->nullable(false)->change();
            $table->unsignedInteger('port')->nullable(false)->default(9100)->change();
        });
    }
};
