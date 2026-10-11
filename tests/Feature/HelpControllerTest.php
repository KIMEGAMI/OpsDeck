<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelpControllerTest extends TestCase
{
    public function test_help_page_is_public_and_contains_scope_guidance(): void
    {
        $response = $this->get(route('help.index'));

        $response->assertOk()->assertSee('Help / FAQ')->assertSee('MDMやEDR')->assertSee('support@kimegami.jp')->assertSee('mailto:support@kimegami.jp')->assertSee('info@kimegami.jp')->assertSee('配信停止');
    }
}
