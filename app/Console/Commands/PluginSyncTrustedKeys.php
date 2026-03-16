<?php

namespace App\Console\Commands;

use App\Plugins\PluginTrustedKeySynchronizer;
use Illuminate\Console\Command;

class PluginSyncTrustedKeys extends Command
{
    protected $signature = 'plugin:sync-trusted-keys';

    protected $description = 'Download trusted plugin signer keys from configured registry sources';

    public function handle(PluginTrustedKeySynchronizer $synchronizer): int
    {
        $summary = $synchronizer->sync();

        $this->info(sprintf(
            'Synced trusted keys from %d sources (%d keys downloaded, %d failed sources).',
            $summary['sourcesSynced'] ?? 0,
            $summary['keysSynced'] ?? 0,
            $summary['sourcesFailed'] ?? 0,
        ));

        foreach (($summary['failures'] ?? []) as $failure) {
            $this->warn(sprintf(
                '[%s] %s',
                $failure['sourceName'] ?? $failure['sourceId'] ?? 'registry',
                $failure['message'] ?? 'Unknown error while syncing trusted keys.',
            ));
        }

        return self::SUCCESS;
    }
}
