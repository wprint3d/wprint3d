<?php

use Symfony\Component\Finder\Finder;

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;

$dir = '/var/www';

$iterator =
    Finder::create()
          ->files()
          ->name('*.php')
          ->exclude('vendor')
          ->in( $dir );

$doctum = new Doctum($iterator, [
    'title'                 => 'WPrint 3D',
    'build_dir'             => "{$dir}/docs/public",
    'cache_dir'             => "{$dir}/docs/cache",
    'remote_repository'     => new GitHubRemoteRepository('wprint3d/wprint3d', '/var/www'),
    'default_opened_level'  => PHP_INT_MAX
]);

$doctum->setVersion(
    trim(
        /*
         * Get latest commit hash from the current branch (targetting origin).
         * 
         * For more information, see https://stackoverflow.com/a/55704573.
         */
        shell_exec('git rev-parse `git branch -r --sort=committerdate | tail -1`')
    )
);

return $doctum;