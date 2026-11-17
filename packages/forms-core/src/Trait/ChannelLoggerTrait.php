<?php

declare(strict_types=1);

namespace FormsCore\Trait;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Logger\Logger;
use FormsCore\Enum\LogChannel;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Journalisation par canal (mailer, cron, app, security) avec le Logger JSON de core-php.
 *
 * Fichier : logs/<canal>/<classe-en-kebab-case>-jj-mm-aaaa.json, consultable dans l'espace Administration.
 * L'IP publique n'est pas résolue (logIP: false) : core-php interroge sinon un service externe à chaque requête.
 */
trait ChannelLoggerTrait
{
    /**
     * @param array<string, mixed> $data données utiles au diagnostic (jamais de jeton ni de mot de passe)
     */
    protected function logTo(
        LogChannel $channel,
        LoggerLevelEnum $level,
        string $message,
        string $action,
        array $data = [],
        ?\Throwable $exception = null,
    ): void {
        $content = ['message' => $message];

        if ([] !== $data) {
            $content['data'] = $data;
        }

        if (null !== $exception) {
            // Normalisée par le Logger (classe, message, fichier, stack trace)
            $content['exception'] = $exception;
        }

        $shortName = (new \ReflectionClass($this))->getShortName();
        $filename = $channel->value.'/'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));

        $security = property_exists($this, 'security') && $this->security instanceof Security ? $this->security : null;

        try {
            (new Logger($filename, $security, logIP: false))->log($level, $content, ['action' => $action]);
        } catch (\Throwable) {
            // Un journal indisponible (droits, disque) ne doit jamais interrompre le traitement métier
        }
    }
}
