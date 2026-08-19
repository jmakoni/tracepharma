<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exception_types', function (Blueprint $table) {
            $table->string('category', 32)->default('system')->after('name');
            $table->string('hda_class', 64)->nullable()->after('category');
            $table->index('category');
            $table->index('hda_class');
        });
    }

    public function down(): void
    {
        Schema::table('exception_types', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['hda_class']);
            $table->dropColumn(['category', 'hda_class']);
        });
    }
};
