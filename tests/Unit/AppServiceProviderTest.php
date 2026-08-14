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

    public function test_it_recognizes_only_the_efficient_queue_artisan_command(): void
    {
        $this->assertTrue(AppServiceProvider::isEfficientQueueCommand(['artisan', 'queue:cow-work', 'redis']));
        $this->assertTrue(AppServiceProvider::isEfficientQueueCommand(['artisan', '--ansi', 'queue:cow-work']));
        $this->assertFalse(AppServiceProvider::isEfficientQueueCommand(['artisan', 'queue:work', 'redis']));
        $this->assertFalse(AppServiceProvider::isEfficientQueueCommand(['artisan', 'migrate', '--queue=queue:cow-work']));
    }

    public function test_it_defers_plugin_boot_only_for_the_efficient_queue_console_command(): void
    {
        $this->assertTrue(AppServiceProvider::shouldDeferPluginAppBoot(true, ['artisan', 'queue:cow-work', 'redis']));
        $this->assertFalse(AppServiceProvider::shouldDeferPluginAppBoot(true, ['artisan', 'migrate']));
        $this->assertFalse(AppServiceProvider::shouldDeferPluginAppBoot(false, ['artisan', 'queue:cow-work']));
    }
}
