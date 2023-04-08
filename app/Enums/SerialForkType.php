<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static READER()
 * @method static static WRITER()
 */
final class SerialForkType extends Enum
{
    const READER = 0;
    const WRITER = 1;
}
