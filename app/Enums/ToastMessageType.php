<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static ERROR()
 * @method static static INFO()
 * @method static static SUCCESS()
 */
final class ToastMessageType extends Enum
{
    const ERROR   = 0;
    const INFO    = 1;
    const SUCCESS = 2;
}
