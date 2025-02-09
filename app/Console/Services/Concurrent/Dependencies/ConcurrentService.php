<?php

namespace App\Console\Services\Concurrent\Dependencies;

abstract class ConcurrentService implements ConcurrentServiceInterface {

    protected $description;

    public function getDescription(): string {
        if (!trim($this->description ?? '')) {
            return 'No description provided';
        }

        return $this->description;
    }

}