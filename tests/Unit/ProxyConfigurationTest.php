<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProxyConfigurationTest extends TestCase
{
    public function test_development_fastcgi_proxy_does_not_apply_nginx_default_upload_limit(): void
    {
        $configuration = file_get_contents(
            dirname(__DIR__, 2).'/proxy/backend-upstream-fastcgi-dev.conf'
        );

        $this->assertIsString($configuration);
        $this->assertMatchesRegularExpression(
            '/^\s*client_max_body_size\s+0;/m',
            $configuration
        );
    }
}
