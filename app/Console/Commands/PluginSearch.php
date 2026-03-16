<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PluginSearch extends Command
{
    protected $signature = 'plugin:search {query?}';
    protected $description = 'Search the official plugin registry';

    public function handle(PluginManager $pluginManager): int
    {
        $query = Str::lower((string) $this->argument('query'));
        $plugins = collect($pluginManager->listRegistry())
            ->filter(function ($plugin) use ($query) {
                if ($query === '') {
                    return true;
                }

                $haystack = Str::lower(implode(' ', [
                    $plugin['id'] ?? '',
                    $plugin['name'] ?? '',
                    $plugin['description'] ?? '',
                ]));

                return str_contains($haystack, $query);
            })
            ->values();

        $this->table(
            ['ID', 'Name', 'Version', 'Author'],
            $plugins->map(fn ($plugin) => [
                $plugin['id'] ?? '',
                $plugin['name'] ?? '',
                $plugin['version'] ?? ($plugin['latestVersion'] ?? ''),
                $plugin['author'] ?? '',
            ])->all()
        );

        return self::SUCCESS;
    }
}
