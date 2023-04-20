<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static EVERY_SECOND()
 * @method static static EVERY_5_MINUTES()
 * @method static static NEVER()
 */
final class BackupInterval extends Enum
{
    const EVERY_SECOND    = 0;
    const EVERY_5_MINUTES = 1;
    const NEVER           = 2;
}
