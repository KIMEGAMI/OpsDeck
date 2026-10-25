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
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('preview_new_rows')->default(0)->after('error_rows');
            $table->unsignedInteger('preview_updated_rows')->default(0)->after('preview_new_rows');
            $table->unsignedInteger('preview_confirmation_rows')->default(0)->after('preview_updated_rows');
            $table->unsignedInteger('preview_error_rows')->default(0)->after('preview_confirmation_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('This migration is forward-only; apply a new migration to change the schema.');
    }
};
