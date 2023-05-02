<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static OptionOne()
 * @method static static OptionTwo()
 * @method static static OptionThree()
 */
final class SortingMode extends Enum
{
    const NAME_ASCENDING  = 0;
    const NAME_DESCENDING = 1;
    const DATE_ASCENDING  = 2;
    const DATE_DESCENDING = 3;
}
