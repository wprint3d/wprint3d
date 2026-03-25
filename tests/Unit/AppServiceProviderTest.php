<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_it_skips_plugin_app_boot_dispatch_for_regular_http_requests_by_default(): void
    {
        $this->assertFalse(
            AppServiceProvider::shouldDispatchPluginAppBoot(
                isTestingEnvironment: false,
                hasMongoExtension: true,
                runningInConsole: false,
                dispatchOnHttp: false,
            )
        );
    }

    public function test_it_dispatches_plugin_app_boot_when_running_in_console(): void
    {
        $this->assertTrue(
            AppServiceProvider::shouldDispatchPluginAppBoot(
                isTestingEnvironment: false,
                hasMongoExtension: true,
                runningInConsole: true,
                dispatchOnHttp: false,
            )
        );
    }

    public function test_it_allows_http_dispatch_when_explicitly_enabled(): void
    {
        $this->assertTrue(
            AppServiceProvider::shouldDispatchPluginAppBoot(
                isTestingEnvironment: false,
                hasMongoExtension: true,
                runningInConsole: false,
                dispatchOnHttp: true,
            )
        );
    }
}
