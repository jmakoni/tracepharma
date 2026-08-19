<?php

use App\Models\MailTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MailTemplate::syncFromCatalog();
    }

    public function down(): void
    {
        MailTemplate::query()->whereIn('key', [
            'tenant.provisioned.owner',
            'tenant.provisioned.received',
        ])->delete();
    }
};
