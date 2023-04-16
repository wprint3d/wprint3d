<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static COUNT_LINES()
 * @method static static PARSE_FILE()
 */
final class RecoveryStage extends Enum
{
    const COUNT_LINES = 0;
    const PARSE_FILE  = 1;
}
