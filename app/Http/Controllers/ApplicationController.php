<?php

namespace App\Http\Controllers;

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

}