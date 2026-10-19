<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_portal_and_admin_hosts_are_distinct(): void
    {
        $this->assertNotSame(config('bitflag.hosts.app'), config('bitflag.hosts.admin'));
    }

    public function test_the_end_to_end_routes_are_off_by_default(): void
    {
        $this->assertFalse(config('testing.e2e'));
    }
}
