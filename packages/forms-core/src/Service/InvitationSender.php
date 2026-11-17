<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Mailer\Exception\EmailSendingException;
use Fagathe\CorePhp\Mailer\Exception\InvalidEmailDataException;
use Fagathe\CorePhp\Mailer\Exception\RecipientUnreachableException;
use FormsCore\DTO\Mail\CreatorBounceAlertMailDto;
use FormsCore\DTO\Mail\SurveyInvitationMailDto;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\LogChannel;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Mailer\MailDispatcher;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Trait\ChannelLoggerTrait;
use Psr\Clock\ClockInterface;

/**
 * Envoi de SURVEY_INVITATION et suivi du statut du destinataire : PENDING → SENT, ou BOUNCED si
 * l'adresse est rejetée définitivement (specs_fonc §5.4 et §9.1 « Rattrapage SMTP »).
 *
 * Les rejets signalés plus tard par le serveur destinataire (DSN reçu dans la boîte d'expédition)
 * ne sont pas visibles d'ici : ils demandent un webhook du fournisseur d'e-mails.
 */
final class InvitationSender
{
    use ChannelLoggerTrait;

    /** Au-delà, un échec temporaire répété est traité comme un rejet définitif. */
    public const MAX_ATTEMPTS = 5;
    private const RETRY_AFTER = '-10 minutes';

    public function __construct(
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly MailDispatcher $mailDispatcher,
        private readonly LifecycleService $lifecycle,
        private readonly LinkGenerator $links,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool true si l'invitation est partie
     */
    public function send(SurveyRecipient $recipient): bool
    {
        $survey = $recipient->getSurvey();

        if (RecipientStatus::Pending !== $recipient->getStatus() || !$survey->isActive() || null === $recipient->getToken()) {
            return false;
        }

        try {
            $this->mailDispatcher->dispatch(new SurveyInvitationMailDto(
                recipientEmail: $recipient->getEmail(),
                surveyTitle: $survey->getTitle(),
                surveyDescription: $survey->getDescription(),
                questionsCount: $survey->getQuestionsCount(),
                surveyUrl: $this->links->participantUrl($recipient),
                expiresAt: $survey->getExpiresAt() ?? $this->clock->now(),
            ));
        } catch (RecipientUnreachableException|InvalidEmailDataException $e) {
            // Domaine sans serveur de messagerie, ou adresse refusée par la validation
            $this->bounce($recipient, $e->getMessage());

            return false;
        } catch (EmailSendingException $e) {
            if (!$e->isRetryable()) {
                // Rejet SMTP définitif (55x)
                $this->bounce($recipient, $e->getMessage());

                return false;
            }

            $recipient->markSendFailed($e->getMessage());
            if ($recipient->getSendAttempts() >= self::MAX_ATTEMPTS) {
                $this->bounce($recipient, 'Échecs d\'envoi répétés : '.$e->getMessage());

                return false;
            }

            $this->recipientRepository->save($recipient);

            return false;
        } catch (\Throwable $e) {
            // Erreur de configuration ou de rendu : le destinataire n'y est pour rien, il reste en file
            $recipient->markSendFailed($e->getMessage());
            $this->recipientRepository->save($recipient);
            $this->logTo(LogChannel::Mailer, LoggerLevelEnum::Error, 'Invitation non envoyée (erreur inattendue).', 'invitation.send_error', ['recipient' => $recipient->getId()->toRfc4122()], $e);

            return false;
        }

        $recipient->markSent($this->clock->now());
        $this->recipientRepository->save($recipient);

        return true;
    }

    /**
     * Rattrapage SMTP : réessaie les invitations restées en file (échec temporaire, worker arrêté…).
     *
     * @return array{attempted: int, sent: int}
     */
    public function retryPending(): array
    {
        $recipients = $this->recipientRepository->findPendingToRetry($this->clock->now()->modify(self::RETRY_AFTER));
        $sent = 0;

        foreach ($recipients as $recipient) {
            if ($this->send($recipient)) {
                ++$sent;
            }
        }

        return ['attempted' => \count($recipients), 'sent' => $sent];
    }

    private function bounce(SurveyRecipient $recipient, string $reason): void
    {
        $survey = $recipient->getSurvey();
        $owner = $survey->getOwner();

        $recipient->markBounced($this->clock->now(), $reason);
        $this->recipientRepository->save($recipient);

        $this->logTo(LogChannel::Mailer, LoggerLevelEnum::Warning, 'Adresse rejetée définitivement.', 'invitation.bounced', [
            'survey' => $survey->getId()->toRfc4122(),
            'recipient' => $recipient->getEmail(),
            'reason' => $reason,
        ]);

        $this->mailDispatcher->dispatchSilently(new CreatorBounceAlertMailDto(
            creatorEmail: $owner->getEmail(),
            creatorName: $owner->getDisplayName(),
            surveyTitle: $survey->getTitle(),
            bouncedEmail: $recipient->getEmail(),
            recipientsUrl: $this->links->recipientsUrl($survey),
        ));

        // Une adresse rejetée sort du calcul des 100 % : la campagne peut être terminée
        $this->lifecycle->closeIfAllAnswered($survey);
    }
}
