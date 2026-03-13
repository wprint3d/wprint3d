<?php

namespace App\Plugins;

class PluginHookDispatcher
{
    public function __construct(
        private PluginHookCompiler $compiler,
    ) {}

    public function dispatch(string $hook, array $context = []): array
    {
        return ($this->compiler->compile($hook))($context);
    }
}
