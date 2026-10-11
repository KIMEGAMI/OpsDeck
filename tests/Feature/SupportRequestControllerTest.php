<?php

namespace Tests\Feature;

use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportRequestControllerTest extends TestCase
{
    public function test_support_request_pages_use_a_stable_order_when_creation_times_match(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.pagination.per_page' => 1]);
        $admin = User::factory()->create(['email' => 'support-pagination-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $this->travelTo('2030-10-01 12:00:00');
        $firstRequest = SupportRequest::query()->create(['category' => 'usage', 'message' => '本文1', 'reference_code' => 'SUP-11111111-1111-4111-8111-111111111111']);
        $secondRequest = SupportRequest::query()->create(['category' => 'billing', 'message' => '本文2', 'reference_code' => 'SUP-22222222-2222-4222-8222-222222222222']);

        $this->actingAs($admin)->get(route('admin.support.index', ['page' => 1]))
            ->assertOk()
            ->assertSee($secondRequest->reference_code)
            ->assertDontSee($firstRequest->reference_code);

        $this->actingAs($admin)->get(route('admin.support.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($firstRequest->reference_code)
            ->assertDontSee($secondRequest->reference_code);
    }

    public function test_support_request_is_stored_without_exposing_message_in_admin_list(): void
    {
        $errorReference = (string) Str::uuid();
        $this->get(route('support.create', ['error_reference' => $errorReference]))
            ->assertOk()
            ->assertSee('support-safety-note')
            ->assertSee('aria-describedby')
            ->assertSee('disabled = true')
            ->assertSee('aria-disabled')
            ->assertSee('name="error_reference" value="'.$errorReference.'"', false)
            ->assertSee('mailto:'.config('opsdeck.support_email'));
        $response = $this->post(route('support.store'), [
            'category' => 'usage',
            'message' => '使い方を教えてください。',
            'reference_code' => 'SUP-USER-CONTROLLED',
            'error_reference' => $errorReference,
        ]);
        $supportRequest = SupportRequest::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('support.create'))->assertSessionHas('support_reference_code', $supportRequest->reference_code);
        $this->assertMatchesRegularExpression('/^SUP-[A-F0-9-]{36}$/', $supportRequest->reference_code);
        $this->assertNotSame('SUP-USER-CONTROLLED', $supportRequest->reference_code);
        $this->assertDatabaseHas('support_requests', [
            'category' => 'usage',
            'status' => 'open',
            'reference_code' => $supportRequest->reference_code,
            'error_reference' => $errorReference,
        ]);
        $this->get(route('support.create'))->assertOk()->assertSee($supportRequest->reference_code);

        $admin = User::factory()->create(['email' => 'support-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);
        $this->actingAs($admin)->get(route('admin.support.index'))
            ->assertOk()
            ->assertSee('usage')
            ->assertSee($supportRequest->reference_code)
            ->assertSee($errorReference)
            ->assertDontSee('使い方を教えてください');
        $this->actingAs($admin)->put(route('admin.support.update', $supportRequest), ['status' => 'resolved'])->assertRedirect(route('admin.support.index'));
        $this->assertDatabaseHas('support_requests', ['id' => $supportRequest->id, 'status' => 'resolved']);
    }

    public function test_platform_admin_can_find_a_support_request_by_its_reference_code(): void
    {
        $targetRequest = SupportRequest::query()->create(['category' => 'billing', 'message' => '対象の問い合わせ', 'reference_code' => 'SUP-00000000-0000-4000-8000-000000000001']);
        SupportRequest::query()->create(['category' => 'usage', 'message' => '別の問い合わせ', 'reference_code' => 'SUP-00000000-0000-4000-8000-000000000002']);
        $admin = User::factory()->create(['email' => 'support-search-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $this->actingAs($admin)->get(route('admin.support.index', ['reference_code' => strtolower($targetRequest->reference_code)]))
            ->assertOk()
            ->assertSee($targetRequest->reference_code)
            ->assertSee('billing')
            ->assertDontSee('SUP-00000000-0000-4000-8000-000000000002');
    }

    public function test_platform_admin_can_find_a_support_request_by_error_reference(): void
    {
        $errorReference = (string) Str::uuid();
        $targetRequest = SupportRequest::query()->create([
            'category' => 'other',
            'message' => 'エラー画面について',
            'reference_code' => 'SUP-00000000-0000-4000-8000-000000000003',
            'error_reference' => $errorReference,
        ]);
        SupportRequest::query()->create([
            'category' => 'usage',
            'message' => '別の問い合わせ',
            'reference_code' => 'SUP-00000000-0000-4000-8000-000000000004',
        ]);
        $admin = User::factory()->create(['email' => 'support-error-search-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        $this->actingAs($admin)->get(route('admin.support.index', ['error_reference' => strtoupper($errorReference)]))
            ->assertOk()
            ->assertSee($targetRequest->reference_code)
            ->assertSee($errorReference)
            ->assertDontSee('SUP-00000000-0000-4000-8000-000000000004');
    }

    public function test_support_request_rejects_a_malformed_error_reference(): void
    {
        $this->from(route('support.create'))
            ->post(route('support.store'), ['category' => 'other', 'message' => 'エラーについて', 'error_reference' => 'not-a-uuid'])
            ->assertRedirect(route('support.create'))
            ->assertSessionHasErrors('error_reference');
    }

    public function test_support_submissions_are_rate_limited_per_ip_without_blocking_the_form(): void
    {
        config(['opsdeck.support.submission_limit' => 1]);
        $existingRequestCount = SupportRequest::query()->count();
        $submission = ['category' => 'usage', 'message' => '利用方法について'];
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])
            ->post(route('support.store'), $submission)
            ->assertRedirect(route('support.create'));

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42'])
            ->post(route('support.store'), $submission)
            ->assertTooManyRequests();

        $this->assertSame($existingRequestCount + 1, SupportRequest::query()->count());
        $this->get(route('support.create'))->assertOk();
    }
}
