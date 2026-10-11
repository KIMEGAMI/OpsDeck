<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('cost_savings_renewal_rate_percent')->nullable();
            $table->unsignedInteger('cost_savings_unused_asset_monthly_jpy')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'cost_savings_renewal_rate_percent',
                'cost_savings_unused_asset_monthly_jpy',
            ]);
        });
    }
};
