<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_shows_a_public_homepage_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('見つける。見直す。')
            ->assertSee(route('register'));
    }
}
