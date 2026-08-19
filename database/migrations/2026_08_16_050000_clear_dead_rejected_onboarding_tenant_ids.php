<?php

declare(strict_types=1);

use App\Models\CustomerOnboarding;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CustomerOnboarding::clearDeadRejectedClaims();
    }

    public function down(): void
    {
        // Dead rejected claims cannot be restored.
    }
};
