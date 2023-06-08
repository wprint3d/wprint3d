<?php

namespace App\Console\Commands;

use App\Models\User;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Hash;

class CreateSampleUser extends Command
{
    const SAMPLE_USER_MAIL_ADDRESS  = 'admin@admin.com';
    const SAMPLE_USER_PASSWORD      = 'admin';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create:sample-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a sample user for development and testing (admin:admin).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        User::create([
            'name'      => self::SAMPLE_USER_MAIL_ADDRESS,
            'email'     => self::SAMPLE_USER_MAIL_ADDRESS,
            'password'  => Hash::make( self::SAMPLE_USER_PASSWORD ),
            'settings'  => [
                'recording' => [
                    'enabled'           => true,
                    'resolution'        => '1280x720',
                    'framerate'         => 30,
                    'captureInterval'   => 0.25
                ]
            ]
        ]);

        return Command::SUCCESS;
    }
}
