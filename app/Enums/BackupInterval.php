<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static ON_LINE_CHANGE()
 * @method static static EVERY_5_MINUTES()
 * @method static static NEVER()
 */
final class BackupInterval extends Enum
{
    const ON_LINE_CHANGE  = 0;
    const EVERY_5_MINUTES = 1;
    const NEVER           = 2;
}
