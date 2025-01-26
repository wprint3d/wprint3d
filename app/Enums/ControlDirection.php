<?php declare(strict_types=1);

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * @method static static UP()
 * @method static static LEFT()
 * @method static static RIGHT()
 * @method static static DOWN()
 * @method static static Y_FORWARDS()
 * @method static static Y_BACKWARDS()
 * @method static static HOME()
 */
final class ControlDirection extends Enum
{
    const UP            = "G0  Z{distance} F{feedrate}";
    const LEFT          = "G0 X-{distance} F{feedrate}";
    const RIGHT         = "G0  X{distance} F{feedrate}";
    const DOWN          = "G0 Z-{distance} F{feedrate}";
    const Y_FORWARDS    = "G0  Y{distance} F{feedrate}";
    const Y_BACKWARDS   = "G0 Y-{distance} F{feedrate}";
    const HOME          = 'G28';
}
