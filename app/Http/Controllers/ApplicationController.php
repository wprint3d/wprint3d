<?php

namespace App\Http\Controllers;

use App\Console\Commands\CheckForUpdates;
use App\Console\Commands\InstallUpdates;

use App\Models\Meta;

class ApplicationController extends Controller
{

    public function getName() {
        return config('app.name');
    }

    public function getRevision() {
        return getAppRevision();
    }

    public function getLicenses() {
        return file_get_contents( base_path() . '/THIRD_PARTY_LICENSES.txt' );
    }

    public function getUpdateStatus(): ?Meta {
        return Meta::pendingUpdate()->first();
    }

    public function checkForUpdates(): array {
        return (new CheckForUpdates())->checkForUpdates();
    }

    public function installUpdate(): void {
        (new InstallUpdates())->installUpdate();
    }

}