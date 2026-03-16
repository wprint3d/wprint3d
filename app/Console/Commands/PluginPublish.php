<?php

namespace App\Console\Commands;

use App\Plugins\PluginArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PluginPublish extends Command
{
    protected $signature = 'plugin:publish
        {package : Path to the .w3dp package}
        {--repo= : GitHub repository in owner/name format}
        {--token= : GitHub token with contents write access}
        {--tag= : Release tag override}
        {--release-name= : Human readable release name}';

    protected $description = 'Publish a plugin package to the GitHub-backed official registry';

    public function handle(PluginArchiveService $archiveService): int
    {
        $packagePath = (string) $this->argument('package');
        $repo = $this->option('repo') ?: config('plugins.registry.github_repo');
        $token = $this->option('token') ?: env('GITHUB_TOKEN');

        if (!$token) {
            $this->error('A GitHub token is required. Pass --token or set GITHUB_TOKEN.');

            return self::FAILURE;
        }

        $package = $archiveService->inspect($packagePath, 'publish');
        $tag = $this->option('tag') ?: 'plugin-' . $package->manifest['id'] . '-v' . $package->manifest['version'];
        $releaseName = $this->option('release-name') ?: $package->manifest['name'] . ' ' . $package->manifest['version'];

        $release = Http::github()
            ->withToken($token)
            ->post("/repos/{$repo}/releases", [
                'tag_name' => $tag,
                'name' => $releaseName,
                'draft' => false,
                'prerelease' => false,
                'body' => "Automated plugin publish for {$package->manifest['id']}.",
            ])
            ->throw()
            ->json();

        $uploadUrl = preg_replace('/\{.*$/', '', $release['upload_url'] ?? '');

        Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'Content-Type' => 'application/zip',
            ])
            ->withBody(file_get_contents($packagePath), 'application/zip')
            ->post($uploadUrl . '?name=' . basename($packagePath))
            ->throw();

        $this->info("Published {$package->manifest['id']} to {$repo}.");

        return self::SUCCESS;
    }
}
