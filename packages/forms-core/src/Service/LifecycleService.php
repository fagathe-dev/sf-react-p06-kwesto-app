<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use FormsCore\DTO\Mail\CreatorLowResponseMailDto;
use FormsCore\DTO\Mail\CreatorSurveyClosedMailDto;
use FormsCore\DTO\Mail\SurveyReminderMailDto;
use FormsCore\Entity\Survey;
use FormsCore\Enum\CloseReason;
use FormsCore\Enum\LogChannel;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Mailer\MailDispatcher;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Trait\ChannelLoggerTrait;
use FormsCore\Util\Statistics;
use Psr\Clock\ClockInterface;

/**
 * Cycle de vie d'une campagne ACTIVE : clôture (specs_fonc §7.1) et relance de faible participation
 * (specs_fonc §8 et §9.1).
 */
final class LifecycleService
{
    use ChannelLoggerTrait;

    /** Seuil de relance : taux de réponse inférieur ou égal à 20 %. */
    public const LOW_RESPONSE_RATE = 20.0;

    public function __construct(
        private readonly SurveyRepository $surveyRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly StatsService $statsService,
        private readonly MailDispatcher $mailDispatcher,
        private readonly LinkGenerator $links,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * ACTIVE → CLOSED. Les statistiques sont consolidées et le créateur prévenu, une seule fois même
     * si deux déclencheurs concurrents (dernière soumission, cron, clôture manuelle) se présentent.
     *
     * @return bool true si ce traitement a effectivement clôturé le sondage
     */
    public function close(Survey $survey, CloseReason $reason): bool
    {
        if (!$this->surveyRepository->closeIfActive($survey, $reason, $this->clock->now())) {
            return false;
        }

        // Les brouillons ne peuvent plus être envoyés : ils sont effacés (données personnelles inutiles)
        $this->recipientRepository->clearDrafts($survey);

        try {
            $this->statsService->snapshot($survey);
        } catch (\Throwable $e) {
            // Les statistiques seront figées à la première consultation
            $this->logTo(LogChannel::App, LoggerLevelEnum::Error, 'Consolidation des statistiques impossible à la clôture.', 'survey.close.stats_error', ['survey' => $survey->getId()->toRfc4122()], $e);
        }

        $counters = $this->recipientRepository->counters($survey);
        $owner = $survey->getOwner();

        $this->mailDispatcher->dispatchSilently(new CreatorSurveyClosedMailDto(
            creatorEmail: $owner->getEmail(),
            creatorName: $owner->getDisplayName(),
            surveyTitle: $survey->getTitle(),
            closeReason: $reason->label(),
            finalRate: Statistics::percent($counters['answered'], $counters['valid']),
            answeredCount: $counters['answered'],
            invitedCount: $counters['valid'],
            statsUrl: $this->links->statsUrl($survey),
        ));

        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Questionnaire clôturé.', 'survey.close', [
            'survey' => $survey->getId()->toRfc4122(),
            'reason' => $reason->value,
            'answered' => $counters['answered'],
            'valid' => $counters['valid'],
        ]);

        return true;
    }

    /**
     * Déclencheur ALL_ANSWERED : tous les destinataires valides (hors BOUNCED) ont répondu.
     * À appeler après une soumission ou après le rejet définitif d'une adresse.
     */
    public function closeIfAllAnswered(Survey $survey): bool
    {
        if (!$survey->isActive()) {
            return false;
        }

        $counters = $this->recipientRepository->counters($survey);

        if (0 === $counters['valid'] || $counters['answered'] !== $counters['valid']) {
            return false;
        }

        return $this->close($survey, CloseReason::AllAnswered);
    }

    /**
     * Déclencheur DEADLINE_REACHED : clôture les sondages ACTIVE dont la date limite est dépassée.
     *
     * @return int nombre de sondages clôturés
     */
    public function closeExpired(): int
    {
        $closed = 0;

        foreach ($this->surveyRepository->findExpiredActive($this->clock->now()) as $survey) {
            if ($this->close($survey, CloseReason::DeadlineReached)) {
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * Relance J-5 / J-4 : SURVEY_REMINDER aux destinataires SENT ou OPENED + CREATOR_LOW_RESPONSE,
     * une seule fois par campagne.
     *
     * @return array{surveys: int, reminders: int}
     */
    public function sendLowResponseReminders(): array
    {
        $now = $this->clock->now();
        $report = ['surveys' => 0, 'reminders' => 0];

        foreach ($this->surveyRepository->findReminderCandidates($now) as $survey) {
            $counters = $this->recipientRepository->counters($survey);
            $rate = Statistics::percent($counters['answered'], $counters['valid']);

            if (0 === $counters['valid'] || $rate > self::LOW_RESPONSE_RATE) {
                continue;
            }

            $sent = 0;
            foreach ($this->recipientRepository->findBySurveyAndStatuses($survey, RecipientStatus::remindable()) as $recipient) {
                $delivered = $this->mailDispatcher->dispatchSilently(new SurveyReminderMailDto(
                    recipientEmail: $recipient->getEmail(),
                    surveyTitle: $survey->getTitle(),
                    surveyUrl: $this->links->participantUrl($recipient),
                    expiresAt: $survey->getExpiresAt() ?? $now,
                ));

                if ($delivered) {
                    $recipient->markReminded($now);
                    ++$sent;
                }
            }

            // Marqué même si aucune relance n'est partie : la fenêtre ne doit produire qu'une tentative
            $survey->markReminded($now, $sent);
            $this->surveyRepository->save($survey);

            $owner = $survey->getOwner();
            $this->mailDispatcher->dispatchSilently(new CreatorLowResponseMailDto(
                creatorEmail: $owner->getEmail(),
                creatorName: $owner->getDisplayName(),
                surveyTitle: $survey->getTitle(),
                responseRate: $rate,
                answeredCount: $counters['answered'],
                invitedCount: $counters['valid'],
                remindersSent: $sent,
                recipientsUrl: $this->links->recipientsUrl($survey),
                expiresAt: $survey->getExpiresAt() ?? $now,
            ));

            ++$report['surveys'];
            $report['reminders'] += $sent;
        }

        return $report;
    }
}
