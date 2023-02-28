<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static IGNORE_POSITION_CHANGE()
 * @method static static GO_BACK()
 */
final class FormatterCommands extends Enum
{
    const IGNORE_POSITION_CHANGE = 'WP3DNOPOSCHG';
    const GO_BACK                = 'WP3DGOBACK';
}
