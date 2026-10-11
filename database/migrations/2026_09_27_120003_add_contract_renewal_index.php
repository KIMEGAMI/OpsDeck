<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->index(['organization_id', 'status', 'renewal_date'], 'contract_renewal_lookup_index');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Production schema changes are forward-only.
    }
};
