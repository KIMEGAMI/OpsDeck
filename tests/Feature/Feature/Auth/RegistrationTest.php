<?php

namespace Tests\Feature\Feature\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Mail\ResendIdempotentTransport;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\ResendIdempotencyKeyFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_keeps_the_form_in_a_readable_centered_width(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('class="card auth-card"', false)
            ->assertSee('OpsDeckを始める')
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.privacy'), false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee('aria-controls="password_confirmation"', false)
            ->assertSee('aria-label="確認用パスワードを表示"', false);
    }

    public function test_registration_creates_an_owner_membership_and_authenticated_session(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'organization_name' => 'Example Operations',
            'name' => 'Example Owner',
            'email' => 'owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ]);

        $response->assertRedirectToRoute('onboarding.index');
        $this->assertAuthenticated();

        $organization = Organization::query()->where('name', 'Example Operations')->firstOrFail();
        $this->assertSame('free', $organization->plan_code);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'role' => MembershipRole::Owner->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verification_required_at);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get(route('dashboard'))->assertRedirectToRoute('verification.notice');
        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('class="card auth-card"', false)
            ->assertSee('メールアドレスを確認してください')
            ->assertSee('確認メールを再送する');

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(route('onboarding.index'))
            ->assertOk()
            ->assertSee('導入チェックリスト')
            ->assertSee('社員名簿を確認して取り込む');
        $this->assertDatabaseHas('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'onboarding_started',
        ]);
    }

    public function test_new_company_owner_can_register_an_employee_and_assign_a_pc(): void
    {
        Notification::fake();

        $this->post('/register', [
            'organization_name' => '初回導入テスト会社',
            'name' => '初回導入管理者',
            'email' => 'first-owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ])->assertRedirectToRoute('onboarding.index');

        $user = User::query()->where('email', 'first-owner@example.test')->firstOrFail();
        $organization = Organization::query()->where('name', '初回導入テスト会社')->firstOrFail();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');

        $session = ['current_organization_id' => $organization->id];
        $this->withSession($session)->post(route('employees.store'), [
            'name' => '営業担当者',
            'employment_status' => config('opsdeck.employee_status.active'),
        ])->assertRedirect();
        $employee = Employee::query()->where('organization_id', $organization->id)->where('name', '営業担当者')->firstOrFail();

        $this->withSession($session)->post(route('assets.store'), [
            'asset_number' => 'FIRST-PC-001',
            'asset_type' => 'pc',
            'status' => config('opsdeck.asset_status.in_stock'),
        ])->assertRedirect();
        $asset = Asset::query()->where('organization_id', $organization->id)->where('asset_number', 'FIRST-PC-001')->firstOrFail();

        $this->withSession($session)->post(route('asset-assignments.store'), [
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'assigned_at' => today()->toDateString(),
        ])->assertRedirectToRoute('assets.show', $asset);

        $this->assertDatabaseHas('asset_assignments', [
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'employee_id' => $employee->id,
            'returned_at' => null,
        ]);
        $this->assertSame(config('opsdeck.asset_status.assigned'), $asset->fresh()->status);
    }

    public function test_new_company_owner_can_review_and_execute_a_free_employee_csv_import(): void
    {
        Notification::fake();
        Storage::fake(config('opsdeck.import.disk'));

        $this->post('/register', [
            'organization_name' => 'CSV導入テスト会社',
            'name' => 'CSV導入管理者',
            'email' => 'csv-owner@example.test',
            'password' => 'A-secure-password-2026',
            'password_confirmation' => 'A-secure-password-2026',
        ])->assertRedirectToRoute('onboarding.index');

        $user = User::query()->where('email', 'csv-owner@example.test')->firstOrFail();
        $organization = Organization::query()->where('name', 'CSV導入テスト会社')->firstOrFail();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($verificationUrl)->assertRedirectToRoute('dashboard');
        $session = ['current_organization_id' => $organization->id];

        $uploadResponse = $this->withSession($session)->post(route('imports.store'), [
            'target_type' => 'employees',
            'source_name' => '社員名簿',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "氏名,メール\nCSV取込社員,csv-employee@example.test\n"),
        ]);
        $batch = ImportBatch::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $uploadResponse->assertRedirect(route('imports.show', [$batch, 'target_type' => 'employees']));
        $this->assertSame('preview', $batch->refresh()->status);
        $this->assertDatabaseMissing('employees', ['organization_id' => $organization->id, 'email' => 'csv-employee@example.test']);

        $this->withSession($session)->get(route('imports.mapping', [$batch, 'target_type' => 'employees']))
            ->assertOk()
            ->assertSee('ファイルの列を登録項目に結びつける');
        $this->withSession($session)->post(route('imports.mapping.store', $batch), [
            'target_type' => 'employees',
            'column_map' => ['氏名' => 'name', 'メール' => 'email'],
        ])->assertRedirect(route('imports.show', $batch));

        $this->assertSame('ready', $batch->refresh()->status);
        $this->withSession($session)->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('取り込み前の確認')
            ->assertSee('まだ会社の情報は変わりません')
            ->assertSee('CSV取込社員');
        $this->withSession($session)->post(route('imports.execute', $batch), ['confirm' => '1'])
            ->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('employees', [
            'organization_id' => $organization->id,
            'name' => 'CSV取込社員',
            'email' => 'csv-employee@example.test',
        ]);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->inserted_rows);
    }

    public function test_unverified_new_user_can_resend_confirmation_with_a_rate_limit(): void
    {
        Notification::fake();
        config()->set('opsdeck.auth.email_verification_resend_attempts_per_minute', 1);
        $user = User::factory()->create(['email' => 'verify-'.Str::uuid().'@example.test']);
        $user->forceFill(['email_verification_required_at' => now(), 'email_verified_at' => null])->save();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', '確認メールを再送しました。受信トレイをご確認ください。');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        $this->actingAs($user)->post(route('verification.send'))->assertTooManyRequests();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_existing_accounts_without_a_verification_requirement_remain_usable(): void
    {
        $existingUser = User::factory()->create();

        $this->assertNull($existingUser->email_verification_required_at);
        $this->assertTrue($existingUser->hasVerifiedEmail());
    }

    public function test_modified_email_verification_link_does_not_verify_a_new_account(): void
    {
        $user = User::factory()->create(['email' => 'verify-'.Str::uuid().'@example.test']);
        $user->forceFill(['email_verification_required_at' => now(), 'email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $modifiedVerificationUrl = str_replace(sha1($user->getEmailForVerification()), sha1('attacker@example.test'), $verificationUrl);

        $this->actingAs($user)->get($modifiedVerificationUrl)->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'email_verified_at' => null,
        ]);
    }

    public function test_verification_mail_uses_a_stable_resend_idempotency_key_for_its_link(): void
    {
        $user = User::factory()->create();
        $mailMessage = (new VerifyEmail)->toMail($user);
        $email = new Email;

        foreach ($mailMessage->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame(
            app(ResendIdempotencyKeyFactory::class)->forEmailVerification($user->getKey(), $mailMessage->actionUrl),
            $email->getHeaders()->getHeaderBody(ResendIdempotentTransport::IDEMPOTENCY_HEADER),
        );
    }
}
