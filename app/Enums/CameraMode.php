<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static LIVE()
 * @method static static SNAPSHOT()
 */
final class CameraMode extends Enum
{
    const LIVE      = 0;
    const SNAPSHOT  = 1;
}
