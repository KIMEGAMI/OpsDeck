<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->boolean('provider_request_attempted')->default(false);
            $table->timestamp('provider_requested_at')->nullable();
            $table->index(['organization_id', 'provider_request_attempted', 'provider_requested_at'], 'ai_usage_org_provider_request_time_idx');
        });

        Schema::table('ai_usage_reservations', function (Blueprint $table) {
            $table->timestamp('provider_requested_at')->nullable();
            $table->index(['organization_id', 'provider_requested_at'], 'ai_usage_reservation_org_request_time_idx');
        });

        DB::table('ai_usage_logs')
            ->where(function (Builder $query): void {
                $query->whereNull('error_code')
                    ->orWhereNotIn('error_code', [
                        'rate_limited',
                        'budget_configuration_unavailable',
                        'monthly_budget_exceeded',
                        'monthly_request_limit_exceeded',
                        'daily_request_limit_exceeded',
                        'provider_unavailable',
                    ]);
            })
            ->orWhere(function (Builder $query): void {
                $query->where('error_code', 'provider_unavailable')
                    ->where(function (Builder $query): void {
                        $query->where('estimated_cost_usd', '>', 0)
                            ->orWhere('input_tokens', '>', 0)
                            ->orWhere('output_tokens', '>', 0);
                    });
            })
            ->update(['provider_request_attempted' => true]);

        DB::table('ai_usage_logs')
            ->where('provider_request_attempted', true)
            ->update(['provider_requested_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration to change the schema.');
    }
};
