<?php

namespace App\Plugins\Contracts;

interface PluginRuntimeAdapter
{
    public function supports(string $runtimeType): bool;

    public function invokeHook(array $plugin, string $hook, array $context = []): array;

    public function invokeAction(array $plugin, array $action, array $payload = [], array $context = []): array;
}
