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
        Schema::table('organization_invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_invitations', 'resend_idempotency_started_at')) {
                $table->timestamp('resend_idempotency_started_at')->nullable();
            }
            if (! Schema::hasColumn('organization_invitations', 'invitation_email_sent_at')) {
                $table->timestamp('invitation_email_sent_at')->nullable();
            }
            if (! Schema::hasColumn('organization_invitations', 'invitation_email_failed_at')) {
                $table->timestamp('invitation_email_failed_at')->nullable();
            }
            if (! Schema::hasColumn('organization_invitations', 'invitation_email_manual_review_at')) {
                $table->timestamp('invitation_email_manual_review_at')->nullable();
            }
            if (! Schema::hasColumn('organization_invitations', 'invitation_email_manual_review_resolved_by')) {
                $table->foreignId('invitation_email_manual_review_resolved_by')->nullable();
            }
        });

        Schema::table('organization_invitations', function (Blueprint $table): void {
            $foreignKeyName = 'org_invitation_mail_review_resolver_fk';
            if (! Schema::hasForeignKey('organization_invitations', $foreignKeyName)) {
                $table->foreign('invitation_email_manual_review_resolved_by', $foreignKeyName)
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('organization_invitations', function (Blueprint $table): void {
            $indexName = 'organization_invitation_mail_review_index';
            if (! Schema::hasIndex('organization_invitations', $indexName)) {
                $table->index(['invitation_email_manual_review_at', 'invitation_email_sent_at'], $indexName);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive. Production schema changes are forward-only.
    }
};
