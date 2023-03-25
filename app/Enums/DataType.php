<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static BOOLEAN()
 * @method static static INTEGER()
 * @method static static FLOAT()
 * @method static static STRING()
 */
final class DataType extends Enum
{
    const BOOLEAN   = 0;
    const INTEGER   = 1;
    const FLOAT     = 2;
    const STRING    = 3;
}
