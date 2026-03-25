<?php

namespace Tests\Unit;

use App\Http\Controllers\ConfigurationController;
use Tests\TestCase;

class ConfigurationControllerTest extends TestCase
{
    public function test_ws_config_uses_application_config_values(): void
    {
        config([
            'services.wprint3d_websocket.app_key' => 'test-app-key',
            'services.wprint3d_websocket.port' => 6101,
        ]);

        $controller = new ConfigurationController();

        $this->assertSame(
            [
                'appKey' => 'test-app-key',
                'port' => 6101,
            ],
            $controller->wsConfig()
        );
    }
}
