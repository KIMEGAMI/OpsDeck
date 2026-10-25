<?php

use App\Http\Controllers\AdminAnnouncementController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminMailDeliveryReviewController;
use App\Http\Controllers\AdminProductMetricsController;
use App\Http\Controllers\AnnouncementReadController;
use App\Http\Controllers\AssetAssignmentController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CancellationFeedbackController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CostDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GoogleSheetConnectionController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\ImpactReportController;
use App\Http\Controllers\ImportBatchController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\QuestionnaireAnswerController;
use App\Http\Controllers\QuestionnaireController;
use App\Http\Controllers\QuestionnaireQuestionController;
use App\Http\Controllers\RealizedSavingController;
use App\Http\Controllers\RecoveryGuideController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RenewalReminderController;
use App\Http\Controllers\SaasAssignmentController;
use App\Http\Controllers\SaasServiceController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SecurityControlController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SupportRequestController;
use App\Http\Controllers\WeeklySummaryController;
use App\Http\Controllers\WorkTimeMeasurementController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return to_route('dashboard');
    }

    return view('welcome', ['plans' => config('opsdeck.plans')]);
})->name('home');

Route::controller(LegalController::class)->group(function (): void {
    Route::get('/terms', 'terms')->name('legal.terms');
    Route::get('/privacy', 'privacy')->name('legal.privacy');
    Route::get('/commercial-transactions', 'commercialTransactions')->name('legal.commercial-transactions');
});
Route::get('/help', [HelpController::class, 'index'])->name('help.index');
Route::get('/demo', DemoController::class)->name('demo.show');
Route::get('/support', [SupportRequestController::class, 'create'])->name('support.create');
Route::post('/support', [SupportRequestController::class, 'store'])->middleware('throttle:support')->name('support.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register')->name('register.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
});

Route::post('/billing/stripe/webhook', StripeWebhookController::class)->withoutMiddleware([ValidateCsrfToken::class])->name('billing.stripe.webhook');
Route::get('/r/{code}', [ReferralController::class, 'redirect'])->middleware(['release.feature:referrals', 'throttle:referrals'])->name('referrals.redirect');

Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-verification')->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'verified', 'organization.member'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/feature-guide', 'feature-guide.index')->name('feature-guide.index');
    Route::get('/saved-views', [SavedViewController::class, 'index'])->name('saved-views.index');
    Route::get('/saved-views/create', [SavedViewController::class, 'create'])->name('saved-views.create');
    Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::get('/saved-views/{savedView}', [SavedViewController::class, 'show'])->name('saved-views.show');
    Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');
    Route::get('/weekly-summary', [WeeklySummaryController::class, 'show'])->name('weekly-summary.show');
    Route::put('/weekly-summary/preference', [WeeklySummaryController::class, 'updatePreference'])->name('weekly-summary.preference');
    Route::get('/help/recovery/{type}', [RecoveryGuideController::class, 'show'])->name('help.recovery');
    Route::middleware(['release.feature:impact_report', 'plan.feature:impact_report'])->group(function (): void {
        Route::get('/impact-report', [ImpactReportController::class, 'show'])->name('impact-report.show');
        Route::get('/impact-report.csv', [ImpactReportController::class, 'download'])->name('impact-report.download');
    });
    Route::get('/referrals', [ReferralController::class, 'show'])->middleware('release.feature:referrals')->name('referrals.show');
    Route::post('/announcements/{announcement}/read', [AnnouncementReadController::class, 'store'])->name('announcements.read');
    Route::resource('assets', AssetController::class)->except('destroy');
    Route::post('/asset-assignments', [AssetAssignmentController::class, 'store'])->name('asset-assignments.store');
    Route::put('/asset-assignments/{assetAssignment}', [AssetAssignmentController::class, 'update'])->name('asset-assignments.update');
    Route::resource('saas-services', SaasServiceController::class)->except('destroy');
    Route::resource('contracts', ContractController::class)->except('destroy');
    Route::get('/costs', CostDashboardController::class)->name('costs.dashboard');
    Route::post('/costs/realized-savings', [RealizedSavingController::class, 'store'])->name('realized-savings.store');
    Route::post('/costs/realized-savings/{realizedSaving}/void', [RealizedSavingController::class, 'void'])->name('realized-savings.void');
    Route::middleware(['release.feature:work_time_tracking', 'plan.feature:work_time_tracking'])->group(function (): void {
        Route::post('/costs/work-time-measurements', [WorkTimeMeasurementController::class, 'store'])->name('work-time-measurements.store');
        Route::post('/costs/work-time-measurements/{workTimeMeasurement}/void', [WorkTimeMeasurementController::class, 'void'])->name('work-time-measurements.void');
    });
    Route::middleware('plan.feature:renewal_alerts')->group(function (): void {
        Route::get('/reminders/renewals', RenewalReminderController::class)->name('reminders.renewals');
        Route::post('/reminders/renewals/onboarding-check', [OnboardingController::class, 'markRenewalsChecked'])->name('onboarding.steps.renewal-check');
    });
    Route::middleware('plan.feature:security_controls')->group(function (): void {
        Route::post('security-controls/{securityControl}/review', [SecurityControlController::class, 'markReviewed'])->name('security-controls.review');
        Route::resource('security-controls', SecurityControlController::class)->except('destroy');
    });
    Route::middleware('plan.feature:evidence')->group(function (): void {
        Route::resource('evidence', EvidenceController::class)->except(['destroy', 'store']);
        Route::post('/evidence', [EvidenceController::class, 'store'])->middleware('throttle:file-uploads')->name('evidence.store');
        Route::get('/evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
    });
    Route::get('/imports', [ImportBatchController::class, 'index'])->name('imports.index');
    Route::get('/imports/create', [ImportBatchController::class, 'create'])->name('imports.create');
    Route::get('/imports/samples/{targetType}/preview', [ImportBatchController::class, 'samplePreview'])->name('imports.samples.preview');
    Route::get('/imports/samples/{targetType}', [ImportBatchController::class, 'sample'])->name('imports.samples.download');
    Route::post('/imports', [ImportBatchController::class, 'store'])->middleware('throttle:file-uploads')->name('imports.store');
    Route::middleware('release.feature:google_sheets')->group(function (): void {
        Route::get('/imports/google-sheets', [ImportBatchController::class, 'googleSheets'])->name('imports.google-sheets');
        Route::post('/imports/google-sheets/spreadsheet', [ImportBatchController::class, 'selectGoogleSpreadsheet'])->name('imports.google-sheets.spreadsheet');
        Route::post('/imports/{importBatch}/google-sheet', [ImportBatchController::class, 'selectGoogleSheet'])->name('imports.google-sheet.store');
        Route::post('/imports/{importBatch}/google-sheet/sync', [ImportBatchController::class, 'syncGoogleSheet'])->name('imports.google-sheet.sync');
        Route::get('/integrations/google-sheets', [GoogleSheetConnectionController::class, 'show'])->name('google-sheets.connection');
        Route::get('/integrations/google-sheets/connect', [GoogleSheetConnectionController::class, 'connect'])->name('google-sheets.connect');
        Route::get('/integrations/google-sheets/callback', [GoogleSheetConnectionController::class, 'callback'])->name('google-sheets.callback');
    });
    Route::get('/imports/{importBatch}', [ImportBatchController::class, 'show'])->name('imports.show');
    Route::get('/imports/{importBatch}/errors.csv', [ImportBatchController::class, 'downloadErrors'])->name('imports.errors.download');
    Route::post('/imports/{importBatch}/sheet', [ImportBatchController::class, 'selectSheet'])->name('imports.sheet.store');
    Route::post('/imports/{importBatch}/sheets', [ImportBatchController::class, 'storeAdditionalSheet'])->name('imports.sheet.additional');
    Route::get('/imports/{importBatch}/mapping', [ImportBatchController::class, 'mapping'])->name('imports.mapping');
    Route::post('/imports/{importBatch}/mapping/ai-suggestions', [ImportBatchController::class, 'mapping'])->name('imports.mapping.ai');
    Route::post('/imports/{importBatch}/mapping', [ImportBatchController::class, 'saveMapping'])->name('imports.mapping.store');
    Route::post('/imports/{importBatch}/candidates/{candidate}/review', [ImportBatchController::class, 'reviewCandidate'])->name('imports.candidates.review');
    Route::post('/imports/{importBatch}/rows/selection', [ImportBatchController::class, 'updateRowSelection'])->name('imports.rows.selection');
    Route::post('/imports/{importBatch}/execute', [ImportBatchController::class, 'execute'])->name('imports.execute');
    Route::post('/imports/{importBatch}/rollback', [ImportBatchController::class, 'rollback'])->name('imports.rollback');
    Route::post('/imports/{importBatch}/retry', [ImportBatchController::class, 'retry'])->name('imports.retry');
    Route::post('/imports/{importBatch}/review-errors', [ImportBatchController::class, 'reviewErrors'])->name('imports.review-errors');
    Route::post('/integrations/google-sheets/disconnect', [GoogleSheetConnectionController::class, 'disconnect'])
        ->middleware('release.feature:google_sheets')
        ->name('google-sheets.disconnect');
    Route::middleware('plan.feature:questionnaires')->group(function (): void {
        Route::resource('questionnaires', QuestionnaireController::class)->except('destroy');
        Route::post('/questionnaire-questions', [QuestionnaireQuestionController::class, 'store'])->name('questionnaire-questions.store');
        Route::put('/questionnaire-questions/{questionnaireQuestion}', [QuestionnaireQuestionController::class, 'update'])->name('questionnaire-questions.update');
        Route::post('/questionnaire-answers', [QuestionnaireAnswerController::class, 'store'])->name('questionnaire-answers.store');
        Route::put('/questionnaire-answers/{questionnaireAnswer}', [QuestionnaireAnswerController::class, 'update'])->name('questionnaire-answers.update');
        Route::post('/questionnaire-answers/{questionnaireAnswer}/approve', [QuestionnaireAnswerController::class, 'approve'])->name('questionnaire-answers.approve');
        Route::post('/questionnaires/{questionnaire}/ai-draft', [QuestionnaireAnswerController::class, 'draft'])
            ->middleware('release.feature:questionnaire_ai_draft')
            ->name('questionnaires.ai-draft');
    });
    Route::get('/audit-logs', AuditLogController::class)->name('audit-logs.index');
    Route::middleware('plan.feature:exports')->group(function (): void {
        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('/exports/{resource}', [ExportController::class, 'download'])->name('exports.download');
    });
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:billing-checkout')->name('billing.checkout');
    Route::post('/billing/storage-addon', [BillingController::class, 'updateStorageAddon'])->middleware(['throttle:billing-checkout', 'release.feature:storage_addon'])->name('billing.storage-addon.update');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:billing-portal')->name('billing.portal');
    Route::post('/billing/cancellation-feedback', [CancellationFeedbackController::class, 'store'])->name('billing.cancellation-feedback.store');
    Route::get('/settings/organization', [OrganizationSettingsController::class, 'edit'])->name('settings.organization');
    Route::put('/settings/organization', [OrganizationSettingsController::class, 'update'])->name('settings.organization.update');
    Route::get('/settings/members', [OrganizationMemberController::class, 'index'])->name('settings.members');
    Route::post('/settings/members/invitations', [OrganizationInvitationController::class, 'store'])->middleware('throttle:invitation-create')->name('settings.members.invitations.store');
    Route::delete('/settings/members/invitations/{invitation}', [OrganizationInvitationController::class, 'revoke'])->name('settings.members.invitations.revoke');
    Route::put('/settings/members/{membership}', [OrganizationMemberController::class, 'update'])->name('settings.members.update');
    Route::post('/saas-assignments', [SaasAssignmentController::class, 'store'])->name('saas-assignments.store');
    Route::get('/saas-assignments/{saasAssignment}/edit', [SaasAssignmentController::class, 'edit'])->name('saas-assignments.edit');
    Route::put('/saas-assignments/{saasAssignment}', [SaasAssignmentController::class, 'update'])->name('saas-assignments.update');
    Route::resource('departments', DepartmentController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('employees', EmployeeController::class)->except('destroy');
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
});

Route::get('/invitations/{token}', [OrganizationInvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [OrganizationInvitationController::class, 'accept'])->middleware('throttle:invitation-accept')->name('invitations.accept');

Route::get('/admin', AdminDashboardController::class)->middleware(['auth', 'verified', 'platform.admin'])->name('admin.dashboard');
Route::get('/admin/ai-cost', [AdminDashboardController::class, 'aiCost'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.ai-cost');
Route::get('/admin/queue-errors', [AdminDashboardController::class, 'queueErrors'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.queue-errors');
Route::get('/admin/health', [AdminDashboardController::class, 'health'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.health');
Route::get('/admin/mail-delivery-reviews', [AdminMailDeliveryReviewController::class, 'index'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.mail-delivery-reviews.index');
Route::post('/admin/mail-delivery-reviews/announcements/{delivery}/resolve', [AdminMailDeliveryReviewController::class, 'resolveAnnouncement'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.mail-delivery-reviews.announcements.resolve');
Route::post('/admin/mail-delivery-reviews/weekly-summaries/{delivery}/resolve', [AdminMailDeliveryReviewController::class, 'resolveWeeklySummary'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.mail-delivery-reviews.weekly-summaries.resolve');
Route::post('/admin/mail-delivery-reviews/invitations/{invitation}/resolve', [AdminMailDeliveryReviewController::class, 'resolveInvitation'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.mail-delivery-reviews.invitations.resolve');
Route::get('/admin/product-metrics', AdminProductMetricsController::class)->middleware(['auth', 'verified', 'platform.admin'])->name('admin.product-metrics');
Route::get('/admin/product-metrics.csv', [AdminProductMetricsController::class, 'export'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.product-metrics.export');
Route::get('/admin/support', [SupportRequestController::class, 'adminIndex'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.support.index');
Route::put('/admin/support/{supportRequest}', [SupportRequestController::class, 'update'])->middleware(['auth', 'verified', 'platform.admin'])->name('admin.support.update');
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin/announcements')->name('admin.announcements.')->group(function (): void {
    Route::get('/', [AdminAnnouncementController::class, 'index'])->name('index');
    Route::get('/create', [AdminAnnouncementController::class, 'create'])->name('create');
    Route::get('/recipient-count', [AdminAnnouncementController::class, 'recipientCount'])->name('recipient-count');
    Route::post('/', [AdminAnnouncementController::class, 'store'])->name('store');
    Route::post('/{announcement}/retry', [AdminAnnouncementController::class, 'retry'])->name('retry');
});
