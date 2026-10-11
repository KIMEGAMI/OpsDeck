<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_shows_a_public_homepage_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('社員・端末・SaaS・契約を、ひとつの場所で管理。')
            ->assertSee(route('register'));
    }
}
