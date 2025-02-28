<?php

namespace App\Console\Commands;

use App\Exceptions\InitializationException;
use App\Models\Configuration;
use App\Models\Meta;
use App\Models\User;
use App\Notifications\SystemMessage;
use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

use Symfony\Component\Yaml\Yaml;

class CheckForUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-for-updates
                            { --ignore-dev    : Run regardless of developer mode }
                            { --ignore-config : Run regardless of the administrator configuration }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for updates to the application';

    private ?Logger $log;

    private function savePendingUpdate(array $update): Meta {
        $meta = new Meta();
        $meta->key   = 'pending_update';
        $meta->value = $update;
        $meta->save();

        return $meta;
    }

    public function getImages(): array {
        $yaml = Yaml::parseFile(
            base_path('docker-compose.yml')
        );

        $images = [];

        if (!isset($yaml['services']) || !is_array($yaml['services'])) {
            return $images;
        }

        foreach ($yaml['services'] as $service) {
            if (isset($service['image'])) {
                $images[] = $service['image'];
            }
        }

        return array_values(
            array_unique($images)
        );
    }

    public function getLocalDigest(string $image): ?string {
        $this->log->debug("Checking for local digest of image: {$image}");

        $namespace  = 'library';
        $tag        = 'latest';

        if (strpos($image, '/') !== false) {
            [$namespace, $image] = explode('/', $image, 2);
        }

        if (strpos($image, ':') !== false) {
            [$image, $tag] = explode(':', $image, 2);
        }

        $repository = $image;

        if ($namespace !== 'library') {
            $repository = "{$namespace}/{$image}";
        }

        $process = Process::run("docker image inspect {$repository}:{$tag}");

        $result = trim($process->output());

        if (empty($result)) {
            return null;
        }

        $data = json_decode($result);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $first = Arr::first($data);

        $digest = data_get($first, 'RepoDigests.0');

        if (empty($digest)) {
            return null;
        }

        return explode('@', $digest)[1];
    }

    public function getRemoteDigest(string $image): ?string {
        $this->log->debug("Checking for remote digest of image: {$image}");

        $namespace  = 'library';
        $tag        = 'latest';

        if (strpos($image, '/') !== false) {
            [$namespace, $image] = explode('/', $image, 2);
        }

        if (strpos($image, ':') !== false) {
            [$image, $tag] = explode(':', $image, 2);
        }

        $response = Http::docker()->get("/namespaces/{$namespace}/repositories/{$image}/tags/{$tag}");

        if ($response->failed()) {
            return null;
        }

        return $response->json('digest');
    }

    public function checkForUpdates(bool $ignoreDev = false, bool $ignoreConfig = false): array {
        $this->log = Log::channel('package-manager');

        $developerMode   = env('DEVELOPER_MODE', false);
        $checkForUpdates = Configuration::get('checkForUpdates', true);

        if (
            ($developerMode && !$ignoreDev)
            ||
            (!$checkForUpdates && !$ignoreConfig)
        ) {
            throw new InitializationException(
                $checkForUpdates
                    ? 'Automatic updates are disabled in developer mode'
                    : 'Automatic updates are disabled from the configuration'
            );
        }

        $images = $this->getImages();

        $updates = [];

        foreach ($images as $image) {
            $localDigest = $this->getLocalDigest($image);

            if ($localDigest === null) {
                continue;
            }

            $remoteDigest = $this->getRemoteDigest($image);

            if ($remoteDigest === null) {
                $this->log->warning("Failed to retrieve remote digest for image: {$image}");

                continue;
            }

            $this->log->debug("Local = {$localDigest}, Remote = {$remoteDigest}");

            if ($localDigest !== $remoteDigest) {
                $updates[] = [
                    'image'  => $image,
                    'local'  => $localDigest,
                    'remote' => $remoteDigest,
                ];
            } else {
                $this->log->debug("No updates available for image: {$image}");
            }
        }

        return $updates;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $ignoreDev    = $this->option('ignore-dev');
        $ignoreConfig = $this->option('ignore-config');

        $updates = $this->checkForUpdates($ignoreDev, $ignoreConfig);

        if (empty($updates)) {
            $this->info('No updates available');

            return Command::SUCCESS;
        }

        $this->info('Updates found!');

        $this->savePendingUpdate($updates);

        foreach (User::all() as $user) {
            $user->notify(new SystemMessage(
                title: 'Update available!',
                description: 'An update is ready, please open the **Settings** menu to apply it'
            ));
        }

        echo json_encode($updates);

        return Command::SUCCESS;
    }
}
