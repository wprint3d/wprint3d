<?php

namespace App\Console\Services\Concurrent\Dependencies;

interface ConcurrentServiceInterface {

    public function handle(): void;

}