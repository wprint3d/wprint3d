<?php

namespace App\Console\Commands;

use App\Enums\UserRole;

use App\Models\User;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Hash;

class CreateSampleUser extends Command
{
    const SAMPLE_USER_NAME          = 'admin';
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
        $user = User::where( 'email', self::SAMPLE_USER_MAIL_ADDRESS )->first();

        if ($user) {
            if (!isset( $user->role ) || $user->role === null) {
                // As of the commit after a2e5ffd, a role is required.
                $user->role = UserRole::ADMINISTRATOR;
                $user->save();
            }

            return Command::SUCCESS;
        }

        User::create([
            'name'      => self::SAMPLE_USER_NAME,
            'email'     => self::SAMPLE_USER_MAIL_ADDRESS,
            'password'  => Hash::make( self::SAMPLE_USER_PASSWORD ),
            'role'      => UserRole::ADMINISTRATOR,
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
