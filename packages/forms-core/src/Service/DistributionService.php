<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\ResponseTrait;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\CloseReason;
use FormsCore\Enum\LogChannel;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Enum\SurveyStatus;
use FormsCore\Message\SendInvitationMessage;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Security\Voter\SurveyVoter;
use FormsCore\Trait\ChannelLoggerTrait;
use FormsCore\Util\EmailListParser;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Module 2 — liste de diffusion et planification (specs_fonc §5), accessible à l'état VALIDATED.
 */
final class DistributionService
{
    use ChannelLoggerTrait;
    use ResponseTrait;

    public const MAX_RECIPIENTS = 5000;
    private const CSV_MAX_BYTES = 2 * 1024 * 1024;
    private const PAGE_SIZE = 50;
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly SurveyAccess $access,
        private readonly SurveyRepository $surveyRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly LifecycleService $lifecycle,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Suivi des destinataires, paginé. Paramètres : page, status, q.
     */
    public function listRecipients(string $id, Request $request): object
    {
        $survey = $this->access->get($id);
        $page = max(1, $request->query->getInt('page', 1));
        $status = RecipientStatus::tryFrom(strtoupper((string) $request->query->get('status', '')));

        $result = $this->recipientRepository->paginate($survey, $status, (string) $request->query->get('q', ''), $page, self::PAGE_SIZE);

        return $this->sendJson([
            'items' => $result['items'],
            'meta' => [
                'page' => $page,
                'limit' => self::PAGE_SIZE,
                'total' => $result['total'],
                'pages' => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            ],
            'counters' => $this->recipientRepository->counters($survey),
        ], context: ['groups' => ['recipient.read']]);
    }

    /**
     * Ajout d'adresses : saisie libre (`emails` : texte multi-lignes ou liste), ou fichier CSV (`file`).
     * Nettoyage : minuscules, espaces retirés, validation RFC 5322, dédoublonnage (specs_fonc §5.1).
     */
    public function addRecipients(string $id, Request $request): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if (!$survey->isValidated()) {
            return $this->conflict('La liste de diffusion n\'est modifiable qu\'à l\'état validé, avant le lancement.');
        }

        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            if (!$file->isValid() || $file->getSize() > self::CSV_MAX_BYTES) {
                return $this->sendViolations(['file' => 'Le fichier CSV est invalide ou dépasse 2 Mo.']);
            }

            $input = (string) file_get_contents($file->getPathname());
        } else {
            $data = 'json' === $request->getContentTypeFormat() ? $request->toArray() : $request->request->all();
            $input = $data['emails'] ?? '';
            $input = \is_array($input) ? array_values($input) : (string) $input;
        }

        $parsed = EmailListParser::parse($input);
        $existing = array_flip($this->recipientRepository->findEmails($survey));
        $available = self::MAX_RECIPIENTS - \count($existing);

        $added = 0;
        $alreadyPresent = 0;
        $overLimit = 0;

        foreach ($parsed['valid'] as $email) {
            if (isset($existing[$email])) {
                ++$alreadyPresent;
                continue;
            }

            if ($added >= $available) {
                ++$overLimit;
                continue;
            }

            $this->recipientRepository->save(new SurveyRecipient($survey, $email), flush: false);
            $existing[$email] = true;
            ++$added;
        }

        $this->surveyRepository->save($survey->touch($this->clock->now()));

        return $this->sendJson([
            'added' => $added,
            'alreadyPresent' => $alreadyPresent,
            'duplicates' => $parsed['duplicates'],
            'invalid' => $parsed['invalid'],
            'overLimit' => $overLimit,
            'counters' => $this->recipientRepository->counters($survey),
        ], Response::HTTP_CREATED);
    }

    /**
     * Retrait d'adresses avant le lancement. Corps : { ids: [uuid, …] } ; `all: true` vide la liste.
     */
    public function removeRecipients(string $id, Request $request): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if (!$survey->isValidated()) {
            return $this->conflict('La liste de diffusion n\'est modifiable qu\'à l\'état validé, avant le lancement.');
        }

        $data = $request->toArray();

        if (true === ($data['all'] ?? false)) {
            $ids = array_map(static fn (SurveyRecipient $recipient): string => $recipient->getId()->toRfc4122(), $this->recipientRepository->findBySurvey($survey));
        } else {
            $ids = array_values(array_filter(\is_array($data['ids'] ?? null) ? $data['ids'] : [], 'is_string'));
        }

        $removed = $this->recipientRepository->deleteByIds($survey, $ids);

        return $this->sendJson([
            'removed' => $removed,
            'counters' => $this->recipientRepository->counters($survey),
        ]);
    }

    /**
     * Lancement de la campagne (specs_fonc §5.3). Corps : { expiresAt: ISO 8601 }.
     *
     * 1. un jeton unique par destinataire ; 2. passage à ACTIVE ; 3. invitations envoyées en asynchrone.
     */
    public function launch(string $id, Request $request): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if (!$survey->isValidated()) {
            return $this->conflict('Seul un questionnaire validé peut être diffusé.');
        }

        $now = $this->clock->now();
        $expiresAt = self::parseDate((string) ($request->toArray()['expiresAt'] ?? ''));

        if (null === $expiresAt) {
            return $this->sendViolations(['expiresAt' => 'La date limite de réponse est obligatoire (date et heure).']);
        }

        if ($expiresAt <= $now) {
            return $this->sendViolations(['expiresAt' => 'La date limite doit être postérieure à l\'instant du lancement.']);
        }

        $recipients = $this->recipientRepository->findBySurvey($survey);
        if ([] === $recipients) {
            return $this->sendViolations(['recipients' => 'Ajoutez au moins un destinataire avant de lancer la campagne.']);
        }

        $this->surveyRepository->transactional(function () use ($survey, $recipients, $expiresAt, $now): void {
            foreach ($recipients as $recipient) {
                // 64 caractères hexadécimaux issus de random_bytes (unicité garantie par l'index unique)
                $recipient->setToken(bin2hex(random_bytes(self::TOKEN_BYTES)));
            }

            $survey->setStatus(SurveyStatus::Active)
                ->setExpiresAt($expiresAt)
                ->setActivatedAt($now)
                ->touch($now);

            $this->surveyRepository->flush();
        });

        foreach ($recipients as $recipient) {
            $this->bus->dispatch(new SendInvitationMessage($recipient->getId()->toRfc4122()));
        }

        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Campagne lancée.', 'survey.launch', [
            'survey' => $survey->getId()->toRfc4122(),
            'recipients' => \count($recipients),
            'expiresAt' => $expiresAt->format(\DATE_ATOM),
        ]);

        return $this->sendJson([
            'survey' => $survey,
            'recipients' => $this->recipientRepository->counters($survey),
        ], context: ['groups' => ['survey.read']]);
    }

    /**
     * Clôture manuelle par le créateur.
     */
    public function closeManually(string $id): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if (!$survey->isActive()) {
            return $this->conflict('Seule une campagne en cours peut être clôturée.');
        }

        $this->lifecycle->close($survey, CloseReason::Manual);

        return $this->sendJson([
            'survey' => $survey,
            'recipients' => $this->recipientRepository->counters($survey),
        ], context: ['groups' => ['survey.read']]);
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function conflict(string $message): object
    {
        return $this->sendJson(['message' => $message], Response::HTTP_CONFLICT);
    }
}
