<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests never hit the network: any HTTP call without an Http::fake() match fails.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
