<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('directory_object_id')->nullable()->after('oidc_subject');
            $table->string('user_principal_name')->nullable()->after('directory_object_id');
            $table->string('employee_id')->nullable()->after('user_principal_name');
            $table->string('given_name')->nullable()->after('employee_id');
            $table->string('surname')->nullable()->after('given_name');
            $table->string('job_title')->nullable()->after('surname');
            $table->string('department')->nullable()->after('job_title');
            $table->string('company_name')->nullable()->after('department');
            $table->string('office_location')->nullable()->after('company_name');
            $table->string('mobile_phone')->nullable()->after('office_location');
            $table->string('business_phone')->nullable()->after('mobile_phone');
            $table->json('directory_groups')->nullable()->after('business_phone');
            $table->timestamp('directory_synced_at')->nullable()->after('directory_groups');

            $table->unique('directory_object_id', 'admins_directory_object_id_unique');
            $table->index('user_principal_name', 'admins_user_principal_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropUnique('admins_directory_object_id_unique');
            $table->dropIndex('admins_user_principal_name_index');
            $table->dropColumn([
                'directory_object_id',
                'user_principal_name',
                'employee_id',
                'given_name',
                'surname',
                'job_title',
                'department',
                'company_name',
                'office_location',
                'mobile_phone',
                'business_phone',
                'directory_groups',
                'directory_synced_at',
            ]);
        });
    }
};
