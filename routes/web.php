<?php

use App\Http\Controllers\AdminAnnouncementController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminProductMetricsController;
use App\Http\Controllers\AnnouncementReadController;
use App\Http\Controllers\AssetAssignmentController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
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
Route::get('/r/{code}', [ReferralController::class, 'redirect'])->name('referrals.redirect');

Route::middleware(['auth', 'organization.member'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/saved-views', [SavedViewController::class, 'index'])->name('saved-views.index');
    Route::get('/saved-views/create', [SavedViewController::class, 'create'])->name('saved-views.create');
    Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::get('/saved-views/{savedView}', [SavedViewController::class, 'show'])->name('saved-views.show');
    Route::get('/weekly-summary', [WeeklySummaryController::class, 'show'])->name('weekly-summary.show');
    Route::put('/weekly-summary/preference', [WeeklySummaryController::class, 'updatePreference'])->name('weekly-summary.preference');
    Route::get('/help/recovery/{type}', [RecoveryGuideController::class, 'show'])->name('help.recovery');
    Route::middleware('plan.feature:impact_report')->group(function (): void {
        Route::get('/impact-report', [ImpactReportController::class, 'show'])->name('impact-report.show');
        Route::get('/impact-report.csv', [ImpactReportController::class, 'download'])->name('impact-report.download');
    });
    Route::get('/referrals', [ReferralController::class, 'show'])->name('referrals.show');
    Route::post('/announcements/{announcement}/read', [AnnouncementReadController::class, 'store'])->name('announcements.read');
    Route::resource('assets', AssetController::class)->except('destroy');
    Route::post('/asset-assignments', [AssetAssignmentController::class, 'store'])->name('asset-assignments.store');
    Route::put('/asset-assignments/{assetAssignment}', [AssetAssignmentController::class, 'update'])->name('asset-assignments.update');
    Route::resource('saas-services', SaasServiceController::class)->except('destroy');
    Route::resource('contracts', ContractController::class)->except('destroy');
    Route::get('/costs', CostDashboardController::class)->name('costs.dashboard');
    Route::post('/costs/realized-savings', [RealizedSavingController::class, 'store'])->name('realized-savings.store');
    Route::post('/costs/realized-savings/{realizedSaving}/void', [RealizedSavingController::class, 'void'])->name('realized-savings.void');
    Route::middleware('plan.feature:renewal_alerts')->group(function (): void {
        Route::get('/reminders/renewals', RenewalReminderController::class)->name('reminders.renewals');
        Route::post('/reminders/renewals/onboarding-check', [OnboardingController::class, 'markRenewalsChecked'])->name('onboarding.steps.renewal-check');
    });
    Route::middleware('plan.feature:security_controls')->group(function (): void {
        Route::resource('security-controls', SecurityControlController::class)->except('destroy');
    });
    Route::middleware('plan.feature:evidence')->group(function (): void {
        Route::resource('evidence', EvidenceController::class)->except('destroy');
        Route::get('/evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
    });
    Route::get('/imports', [ImportBatchController::class, 'index'])->name('imports.index');
    Route::get('/imports/create', [ImportBatchController::class, 'create'])->name('imports.create');
    Route::get('/imports/samples/{targetType}/preview', [ImportBatchController::class, 'samplePreview'])->name('imports.samples.preview');
    Route::get('/imports/samples/{targetType}', [ImportBatchController::class, 'sample'])->name('imports.samples.download');
    Route::post('/imports', [ImportBatchController::class, 'store'])->name('imports.store');
    Route::get('/imports/google-sheets', [ImportBatchController::class, 'googleSheets'])->name('imports.google-sheets');
    Route::post('/imports/google-sheets/spreadsheet', [ImportBatchController::class, 'selectGoogleSpreadsheet'])->name('imports.google-sheets.spreadsheet');
    Route::get('/imports/{importBatch}', [ImportBatchController::class, 'show'])->name('imports.show');
    Route::post('/imports/{importBatch}/sheet', [ImportBatchController::class, 'selectSheet'])->name('imports.sheet.store');
    Route::post('/imports/{importBatch}/google-sheet', [ImportBatchController::class, 'selectGoogleSheet'])->name('imports.google-sheet.store');
    Route::post('/imports/{importBatch}/google-sheet/sync', [ImportBatchController::class, 'syncGoogleSheet'])->name('imports.google-sheet.sync');
    Route::get('/imports/{importBatch}/mapping', [ImportBatchController::class, 'mapping'])->name('imports.mapping');
    Route::post('/imports/{importBatch}/mapping', [ImportBatchController::class, 'saveMapping'])->name('imports.mapping.store');
    Route::post('/imports/{importBatch}/candidates/{candidate}/review', [ImportBatchController::class, 'reviewCandidate'])->name('imports.candidates.review');
    Route::post('/imports/{importBatch}/rows/selection', [ImportBatchController::class, 'updateRowSelection'])->name('imports.rows.selection');
    Route::post('/imports/{importBatch}/execute', [ImportBatchController::class, 'execute'])->name('imports.execute');
    Route::post('/imports/{importBatch}/rollback', [ImportBatchController::class, 'rollback'])->name('imports.rollback');
    Route::post('/imports/{importBatch}/retry', [ImportBatchController::class, 'retry'])->name('imports.retry');
    Route::post('/imports/{importBatch}/review-errors', [ImportBatchController::class, 'reviewErrors'])->name('imports.review-errors');
    Route::get('/integrations/google-sheets', [GoogleSheetConnectionController::class, 'show'])->name('google-sheets.connection');
    Route::get('/integrations/google-sheets/connect', [GoogleSheetConnectionController::class, 'connect'])->name('google-sheets.connect');
    Route::get('/integrations/google-sheets/callback', [GoogleSheetConnectionController::class, 'callback'])->name('google-sheets.callback');
    Route::post('/integrations/google-sheets/disconnect', [GoogleSheetConnectionController::class, 'disconnect'])->name('google-sheets.disconnect');
    Route::middleware('plan.feature:questionnaires')->group(function (): void {
        Route::resource('questionnaires', QuestionnaireController::class)->except('destroy');
        Route::post('/questionnaire-questions', [QuestionnaireQuestionController::class, 'store'])->name('questionnaire-questions.store');
        Route::put('/questionnaire-questions/{questionnaireQuestion}', [QuestionnaireQuestionController::class, 'update'])->name('questionnaire-questions.update');
        Route::post('/questionnaire-answers', [QuestionnaireAnswerController::class, 'store'])->name('questionnaire-answers.store');
        Route::put('/questionnaire-answers/{questionnaireAnswer}', [QuestionnaireAnswerController::class, 'update'])->name('questionnaire-answers.update');
        Route::post('/questionnaire-answers/{questionnaireAnswer}/approve', [QuestionnaireAnswerController::class, 'approve'])->name('questionnaire-answers.approve');
        Route::post('/questionnaires/{questionnaire}/ai-draft', [QuestionnaireAnswerController::class, 'draft'])->name('questionnaires.ai-draft');
    });
    Route::get('/audit-logs', AuditLogController::class)->name('audit-logs.index');
    Route::middleware('plan.feature:exports')->group(function (): void {
        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('/exports/{resource}', [ExportController::class, 'download'])->name('exports.download');
    });
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:billing-checkout')->name('billing.checkout');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::post('/billing/cancellation-feedback', [CancellationFeedbackController::class, 'store'])->name('billing.cancellation-feedback.store');
    Route::get('/settings/organization', [OrganizationSettingsController::class, 'edit'])->name('settings.organization');
    Route::put('/settings/organization', [OrganizationSettingsController::class, 'update'])->name('settings.organization.update');
    Route::get('/settings/members', [OrganizationMemberController::class, 'index'])->name('settings.members');
    Route::post('/settings/members/invitations', [OrganizationInvitationController::class, 'store'])->name('settings.members.invitations.store');
    Route::delete('/settings/members/invitations/{invitation}', [OrganizationInvitationController::class, 'revoke'])->name('settings.members.invitations.revoke');
    Route::put('/settings/members/{membership}', [OrganizationMemberController::class, 'update'])->name('settings.members.update');
    Route::post('/saas-assignments', [SaasAssignmentController::class, 'store'])->name('saas-assignments.store');
    Route::get('/saas-assignments/{saasAssignment}/edit', [SaasAssignmentController::class, 'edit'])->name('saas-assignments.edit');
    Route::put('/saas-assignments/{saasAssignment}', [SaasAssignmentController::class, 'update'])->name('saas-assignments.update');
    Route::resource('departments', DepartmentController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::resource('employees', EmployeeController::class)->except('destroy');
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::get('/invitations/{token}', [OrganizationInvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [OrganizationInvitationController::class, 'accept'])->name('invitations.accept');

Route::get('/admin', AdminDashboardController::class)->middleware(['auth', 'platform.admin'])->name('admin.dashboard');
Route::get('/admin/ai-cost', [AdminDashboardController::class, 'aiCost'])->middleware(['auth', 'platform.admin'])->name('admin.ai-cost');
Route::get('/admin/queue-errors', [AdminDashboardController::class, 'queueErrors'])->middleware(['auth', 'platform.admin'])->name('admin.queue-errors');
Route::get('/admin/health', [AdminDashboardController::class, 'health'])->middleware(['auth', 'platform.admin'])->name('admin.health');
Route::get('/admin/product-metrics', AdminProductMetricsController::class)->middleware(['auth', 'platform.admin'])->name('admin.product-metrics');
Route::get('/admin/product-metrics.csv', [AdminProductMetricsController::class, 'export'])->middleware(['auth', 'platform.admin'])->name('admin.product-metrics.export');
Route::get('/admin/support', [SupportRequestController::class, 'adminIndex'])->middleware(['auth', 'platform.admin'])->name('admin.support.index');
Route::put('/admin/support/{supportRequest}', [SupportRequestController::class, 'update'])->middleware(['auth', 'platform.admin'])->name('admin.support.update');
Route::middleware(['auth', 'platform.admin'])->prefix('admin/announcements')->name('admin.announcements.')->group(function (): void {
    Route::get('/', [AdminAnnouncementController::class, 'index'])->name('index');
    Route::get('/create', [AdminAnnouncementController::class, 'create'])->name('create');
    Route::get('/recipient-count', [AdminAnnouncementController::class, 'recipientCount'])->name('recipient-count');
    Route::post('/', [AdminAnnouncementController::class, 'store'])->name('store');
    Route::post('/{announcement}/retry', [AdminAnnouncementController::class, 'retry'])->name('retry');
});
