<?php

declare(strict_types=1);

use App\Models\MailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('subject');
            $table->string('greeting')->nullable();
            $table->text('body');
            $table->string('salutation')->nullable();
            $table->string('action_label')->nullable();
            $table->string('action_url')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        MailTemplate::syncFromCatalog();
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
