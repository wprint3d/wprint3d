<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static USE_SYSTEM_PREFERENCE()
 * @method static static LIGHT_MODE()
 * @method static static DARK_MODE()
 */
final class ThemeOption extends Enum
{
    const USE_SYSTEM_PREFERENCE = null;
    const LIGHT                 = 0;
    const DARK                  = 1;
}
