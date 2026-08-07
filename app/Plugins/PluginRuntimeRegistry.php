<?php

namespace App\Plugins;

use App\Plugins\Contracts\PluginRuntimeAdapter;
use App\Plugins\Exceptions\PluginRuntimeException;

class PluginRuntimeRegistry
{
    /**
     * @param  array<int, PluginRuntimeAdapter>  $adapters
     */
    public function __construct(
        private array $adapters,
    ) {}

    public function resolve(string $runtimeType): PluginRuntimeAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($runtimeType)) {
                return $adapter;
            }
        }

        throw new PluginRuntimeException("No plugin runtime adapter is registered for {$runtimeType}.");
    }
}
