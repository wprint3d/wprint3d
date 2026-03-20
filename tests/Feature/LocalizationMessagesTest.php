<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalizationMessagesTest extends TestCase
{
    public function test_laravel_auth_failed_message_is_translated_to_spanish(): void
    {
        app()->setLocale('es');

        $this->assertSame(
            'Estas credenciales no coinciden con nuestros registros.',
            __('auth.failed')
        );
    }

    public function test_laravel_validation_required_message_is_translated_to_argentinian_spanish(): void
    {
        app()->setLocale('es_AR');

        $validator = validator(
            data: ['email' => null],
            rules: ['email' => 'required']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'El campo email es obligatorio.',
            $validator->errors()->first('email')
        );
    }

    public function test_custom_login_failure_message_is_translated_to_spanish(): void
    {
        app()->setLocale('es');

        $this->assertSame(
            'Esa combinación de nombre de usuario o correo electrónico y contraseña no coincide con nuestros registros.',
            __('server.auth.invalid_credentials')
        );
    }
}
