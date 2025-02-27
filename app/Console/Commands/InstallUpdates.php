<?php

namespace App\Console\Commands;

use App\Events\UpdateLogChanged;

use App\Exceptions\InitializationException;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

use Symfony\Component\Yaml\Yaml;

class InstallUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:install-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download and install updates from the server';

    private ?string $composeDir;

    const REPOSITORY = 'wprint3d/wprint3d-core';

    private function getServices(): array
    {
        $yaml = Yaml::parseFile(base_path('docker-compose.yml'));

        return array_keys($yaml['services']);
    }

    private function getLatestDockerCompose(): ?string
    {    
        Log::debug(__METHOD__ . ': Getting the latest docker-compose.yml...');

        $response = Http::github()->get('repos/' . self::REPOSITORY . '/contents/docker-compose.yml');

        if ($response->failed()) {
            throw new InitializationException('Failed to get the latest docker-compose.yml: ' . $response->body());
        }

        return base64_decode($response->json('content'));
    }

    private function updateDockerCompose(): void
    {
        Log::info(__METHOD__ . ": reading docker-compose.yml from {$this->composeDir}...");

        $previousMD5 = null;

        $composePath = "{$this->composeDir}/docker-compose.yml";

        if (file_exists($composePath)) {
            $previousMD5 = md5_file($composePath);
        } else {
            Log::warning(__METHOD__ . ': the docker-compose.yml does not exist, creating it...');
        }

        $latestDockerCompose = $this->getLatestDockerCompose();

        if ($latestDockerCompose === null) {
            throw new InitializationException('An invalid docker-compose.yml was returned, please try again later.');
        }

        $nextMD5 = md5($latestDockerCompose);

        if ($previousMD5 === $nextMD5) {
            Log::info(__METHOD__ . ': the docker-compose.yml is already up to date.');
        } else {
            file_put_contents($composePath, $latestDockerCompose);

            Log::info(__METHOD__ . ': the docker-compose.yml has been updated.');
        }
    }

    private function pullImages(): void
    {
        Log::info(__METHOD__ . ': pulling the latest images...');

        $process = Process::path($this->composeDir)->start('docker-compose pull');

        $previousOutput = '';

        while ($process->running()) {
            $output = $process->output() . $process->errorOutput();

            if ($output !== $previousOutput) {
                $previousOutput = $output;

                Log::debug(__METHOD__ . ': ' . $output);

                UpdateLogChanged::dispatch($output);
            }
        }

        $process->wait();


        $output = $process->output() . $process->errorOutput();

        Log::debug(__METHOD__ . ': ' . $output);

        UpdateLogChanged::dispatch($output);
    }

    private function applyUpdates(): void
    {
        Log::info(__METHOD__ . ': applying the updates...');

        $process = Process::path($this->composeDir)->start("cd {$this->composeDir} && docker-compose down && docker-compose up -d");

        $previousOutput = '';

        while ($process->running()) {
            $output = $process->output() . $process->errorOutput();

            if ($output !== $previousOutput) {
                $previousOutput = $output;

                Log::debug(__METHOD__ . ': ' . $output);

                UpdateLogChanged::dispatch($output);
            }
        }

        $process->wait();


        $output = $process->output() . $process->errorOutput();

        Log::debug(__METHOD__ . ': ' . $output);

        UpdateLogChanged::dispatch($output);
    }

    public function installUpdate(): void {
        $this->composeDir = config('docker.compose_dir');

        if ($this->composeDir === null || empty(trim($this->composeDir))) {
            throw new InitializationException('The COMPOSE_DIR environment variable is not set.');
        }

        $this->updateDockerCompose();
        $this->pullImages();

        // $this->applyUpdates();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::debug(__METHOD__ . ': downloading updates...');

        $this->installUpdate();

        Log::info(__METHOD__ . ': updates have been downloaded successfully.');
    }
}
