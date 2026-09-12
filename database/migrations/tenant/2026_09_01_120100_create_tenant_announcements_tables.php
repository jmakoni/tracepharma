<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('announcement_id')->unique();
            $table->string('title');
            $table->text('body');
            $table->string('severity');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_announcement_id')->constrained('tenant_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['tenant_announcement_id', 'user_id'], 'tenant_ann_dismissals_ann_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_announcement_dismissals');
        Schema::dropIfExists('tenant_announcements');
    }
};
