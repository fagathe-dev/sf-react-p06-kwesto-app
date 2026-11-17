<?php

declare(strict_types=1);

namespace FormsCore\Enum;

use Fagathe\CorePhp\Enum\DeviceEnum;

/**
 * Type d'appareil enregistré dans la télémétrie (specs_fonc §6.3).
 */
enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';

    public static function fromCoreDevice(DeviceEnum $device): ?self
    {
        return match ($device) {
            DeviceEnum::Desktop => self::Desktop,
            DeviceEnum::Mobile => self::Mobile,
            DeviceEnum::Tablet => self::Tablet,
            default => null,
        };
    }
}
