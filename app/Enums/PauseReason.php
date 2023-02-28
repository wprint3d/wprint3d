<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static MANUL()
 * @method static static AUTOMATIC()
 */
final class PauseReason extends Enum
{
    const MANUAL    = 0;
    const AUTOMATIC = 1;
}
