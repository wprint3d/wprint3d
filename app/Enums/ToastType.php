<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static OptionOne()
 * @method static static OptionTwo()
 * @method static static OptionThree()
 */
final class ToastType extends Enum
{
    const ERROR = 0;
    const IFNO = 1;
    const OptionThree = 2;
}
