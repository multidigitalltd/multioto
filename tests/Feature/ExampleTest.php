<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root redirects to the admin panel (team-only app).
     */
    public function test_the_root_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
