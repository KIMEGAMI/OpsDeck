<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_assignments', function (Blueprint $table): void {
            $table->index(['organization_id', 'saas_service_id', 'ended_at'], 'saas_assignment_active_lookup_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration to change the schema.');
    }
};
