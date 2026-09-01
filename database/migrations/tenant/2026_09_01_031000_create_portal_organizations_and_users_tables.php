<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_partner_id')->unique()->constrained('trading_partners')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portal_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_organization_id')->constrained('portal_organizations')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->constrained('portal_users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['portal_organization_id', 'portal_user_id'], 'portal_org_user_unique');
        });

        Schema::create('portal_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_otp_challenges');
        Schema::dropIfExists('portal_organization_user');
        Schema::dropIfExists('portal_users');
        Schema::dropIfExists('portal_organizations');
    }
};
