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
        if (Schema::hasColumn('product_events', 'user_id')) {
            Schema::table('product_events', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Product-event actor identifiers cannot be restored; use a forward migration.');
    }
};
