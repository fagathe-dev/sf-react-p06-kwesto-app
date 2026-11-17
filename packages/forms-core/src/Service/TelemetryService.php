<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\BrowserEnum;
use Fagathe\CorePhp\Utils\DetectDevice;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\DeviceType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Télémétrie enregistrée à la soumission sur la fiche du destinataire (specs_fonc §6.3).
 *
 * - IP : Request::getClientIp(), correcte derrière un reverse-proxy si TRUSTED_PROXIES est configuré ;
 * - navigateur, version et appareil : DetectDevice de core-php ;
 * - système : déduit du User-Agent (core-php ne l'expose pas).
 */
final class TelemetryService
{
    private const OS_PATTERNS = [
        'iOS' => '/iPhone|iPad|iPod/i',
        'Android' => '/Android/i',
        'Windows' => '/Windows NT|Windows Phone/i',
        'ChromeOS' => '/CrOS/i',
        'macOS' => '/Macintosh|Mac OS X/i',
        'Linux' => '/Linux|X11/i',
    ];

    public function record(SurveyRecipient $recipient, Request $request): void
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', ''));

        $browserName = null;
        $browserVersion = null;
        $deviceType = null;

        if ('' !== $userAgent) {
            // DetectDevice lit la requête courante (globales PHP) : même User-Agent que $request
            $detector = (new DetectDevice())->setUserAgent($userAgent);
            $browser = $detector->getBrowser();

            $browserName = BrowserEnum::Unknown === $browser ? null : $browser->value;
            $browserVersion = self::majorVersion($detector->getBrowserVersion());
            $deviceType = DeviceType::fromCoreDevice($detector->getDeviceType());
        }

        $recipient->setTelemetry(
            ipAddress: $request->getClientIp(),
            userAgentRaw: '' === $userAgent ? null : $userAgent,
            browserName: $browserName,
            browserVersion: $browserVersion,
            osName: self::detectOs($userAgent),
            deviceType: $deviceType,
        );
    }

    public static function detectOs(string $userAgent): ?string
    {
        foreach (self::OS_PATTERNS as $name => $pattern) {
            if (1 === preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Anonymisation irréversible d'une adresse IP : dernier octet à zéro en IPv4 (/24),
     * 48 premiers bits conservés en IPv6 (/48).
     */
    public static function anonymizeIp(?string $ip): ?string
    {
        if (null === $ip || '' === $ip) {
            return null;
        }

        $packed = @inet_pton($ip);
        if (false === $packed) {
            return null;
        }

        $mask = 4 === \strlen($packed)
            ? "\xFF\xFF\xFF\x00"
            : str_repeat("\xFF", 6).str_repeat("\x00", 10);

        $anonymized = inet_ntop($packed & $mask);

        return false === $anonymized ? null : $anonymized;
    }

    private static function majorVersion(?string $version): ?string
    {
        if (null === $version || '' === $version) {
            return null;
        }

        return explode('.', $version)[0];
    }
}
