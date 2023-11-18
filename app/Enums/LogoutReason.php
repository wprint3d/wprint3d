<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static USER_REQUEST()
 * @method static static ACCOUNT_CHANGED()
 */
final class LogoutReason extends Enum
{
    const USER_REQUEST      = 0;
    const ACCOUNT_CHANGED   = 1;
}
