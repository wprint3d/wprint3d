<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static ADMINISTRATOR()
 * @method static static USER()
 * @method static static SPECTATOR()
 */
final class UserRole extends Enum
{
    const ADMINISTRATOR = 0;
    const USER          = 1;
    const SPECTATOR     = 2;
}
