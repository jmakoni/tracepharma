<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admins', 'preferences')) {
            return;
        }

        $afterAvatar = Schema::hasColumn('admins', 'avatar_url');

        Schema::table('admins', function (Blueprint $table) use ($afterAvatar) {
            $column = $table->json('preferences')->nullable();

            if ($afterAvatar) {
                $column->after('avatar_url');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'preferences')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropColumn('preferences');
            });
        }
    }
};
