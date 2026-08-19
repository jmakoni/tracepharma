<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('profile')->default('pharmacy')->after('name');
            $table->string('status')->default('active')->after('profile');
            $table->string('gln')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['name', 'profile', 'status', 'gln']);
        });
    }
};
