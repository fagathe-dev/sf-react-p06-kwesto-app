<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\ResponseTrait;
use FormsCore\DTO\AnswerPayload\FileAnswer;
use FormsCore\DTO\Mail\SurveyReceiptMailDto;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyAnswer;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\LogChannel;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Mailer\MailDispatcher;
use FormsCore\QuestionType\QuestionTypeRegistry;
use FormsCore\Repository\SurveyAnswerRepository;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\UploadedAssetRepository;
use FormsCore\Trait\ChannelLoggerTrait;
use FormsCore\Util\DateFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Module 3 — interface participant (specs_fonc §6) : contrôle d'accès du lien /s/{token},
 * soumission atomique et anti-doublon, télémétrie.
 */
final class ParticipantService
{
    use ChannelLoggerTrait;
    use ResponseTrait;

    /** États renvoyés au formulaire participant (specs_fonc §6.1). */
    public const STATE_INVALID = 'INVALID';
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_ANSWERED = 'ANSWERED';
    public const STATE_OPEN = 'OPEN';

    public function __construct(
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly SurveyAnswerRepository $answerRepository,
        private readonly UploadedAssetRepository $assetRepository,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ConditionEvaluatorService $conditions,
        private readonly TelemetryService $telemetry,
        private readonly LifecycleService $lifecycle,
        private readonly MailDispatcher $mailDispatcher,
        private readonly PlatformConfig $platformConfig,
        private readonly NormalizerInterface $normalizer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ouverture du lien personnel : les cas sont évalués dans l'ordre de specs_fonc §6.1.
     */
    public function open(string $token): object
    {
        $recipient = $this->recipientRepository->findOneByToken($token);

        // 1. Jeton inexistant
        if (null === $recipient) {
            return $this->state(self::STATE_INVALID, 'Lien invalide.', Response::HTTP_NOT_FOUND);
        }

        $survey = $recipient->getSurvey();
        $now = $this->clock->now();

        // Un jeton n'existe qu'à partir du lancement : un sondage DRAFT / VALIDATED n'est jamais publié
        if ($survey->isDraft() || $survey->isValidated()) {
            return $this->state(self::STATE_INVALID, 'Lien invalide.', Response::HTTP_NOT_FOUND);
        }

        // 2. Clôturé ou date limite atteinte
        if ($survey->isOverAt($now)) {
            return $this->state(self::STATE_CLOSED, 'Ce questionnaire est désormais clôturé. Les réponses ne sont plus acceptées.', Response::HTTP_GONE, [
                'survey' => ['title' => $survey->getTitle()],
            ]);
        }

        // 3. Déjà répondu : le formulaire est totalement masqué
        if (RecipientStatus::Answered === $recipient->getStatus()) {
            return $this->state(self::STATE_ANSWERED, \sprintf(
                'Vous avez déjà répondu à ce questionnaire le %s. Une seule participation est autorisée.',
                DateFormatter::long($recipient->getSubmittedAt() ?? $now),
            ), Response::HTTP_CONFLICT, [
                'survey' => ['title' => $survey->getTitle()],
                'submittedAt' => $recipient->getSubmittedAt()?->format(\DATE_ATOM),
            ]);
        }

        // Invitation encore en file (PENDING) ou adresse rejetée : le lien ne vaut pas accès
        if (!$recipient->getStatus()->canAnswer() && RecipientStatus::Pending !== $recipient->getStatus()) {
            return $this->state(self::STATE_INVALID, 'Lien invalide.', Response::HTTP_NOT_FOUND);
        }

        // 4. Accès valide : première ouverture horodatée (PENDING = invitation partie mais pas encore confirmée)
        if (RecipientStatus::Pending === $recipient->getStatus()) {
            $recipient->markSent($now);
        }

        if ($recipient->markOpened($now)) {
            $this->recipientRepository->save($recipient);
        } else {
            $this->recipientRepository->flush();
        }

        return $this->sendJson([
            'state' => self::STATE_OPEN,
            'survey' => $this->normalizer->normalize($survey, 'json', ['groups' => ['survey.public']]),
            'pendingAssets' => $this->pendingAssets($recipient),
            // Réponses en cours d'une visite précédente : le participant choisit de reprendre ou de recommencer
            'draft' => $recipient->getDraft(),
            'config' => $this->platformConfig->toArray(),
        ]);
    }

    /** Limites d'un brouillon : il reste un simple aide-mémoire, jamais une source de vérité. */
    private const DRAFT_MAX_VALUE_LENGTH = 10000;
    private const DRAFT_MAX_LIST_ITEMS = 100;

    /**
     * Enregistrement du brouillon (specs_fonc §6.5) : réponses telles que saisies, sans validation métier.
     * Seules les questions du questionnaire sont gardées ; chaînes et listes de chaînes uniquement.
     *
     * Corps : { answers: { "<questionId>": "valeur" | ["id", …] }, questionId?: "<question de l'écran courant>" }
     */
    public function saveDraft(SurveyRecipient $recipient, Request $request): object
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->sendViolations(['answers' => 'Format de brouillon invalide.']);
        }

        $rawAnswers = $data['answers'] ?? null;
        if (!\is_array($rawAnswers)) {
            return $this->sendViolations(['answers' => 'Format de brouillon invalide.']);
        }

        $questionIds = [];
        foreach ($recipient->getSurvey()->getQuestions() as $question) {
            $questionIds[$question->getId()->toRfc4122()] = true;
        }

        $answers = [];
        foreach ($rawAnswers as $questionId => $value) {
            if (!isset($questionIds[(string) $questionId])) {
                continue;
            }

            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $answers[(string) $questionId] = mb_substr((string) $value, 0, self::DRAFT_MAX_VALUE_LENGTH);
            } elseif (\is_array($value)) {
                $items = array_values(array_filter($value, static fn (mixed $item): bool => \is_string($item)));
                $answers[(string) $questionId] = array_map(static fn (string $item): string => mb_substr($item, 0, 200), \array_slice($items, 0, self::DRAFT_MAX_LIST_ITEMS));
            }
        }

        $position = \is_string($data['questionId'] ?? null) && isset($questionIds[$data['questionId']]) ? $data['questionId'] : null;
        $now = $this->clock->now();

        // Brouillon vide (tout effacé) : rien à reprendre
        if ([] === $answers) {
            $recipient->clearDraft();
        } else {
            $recipient->saveDraft($answers, $position, $now);
        }
        $this->recipientRepository->flush();

        return $this->sendJson(['savedAt' => [] === $answers ? null : $now->format(\DATE_ATOM)]);
    }

    /**
     * « Recommencer » : le brouillon est effacé (les fichiers déposés sont supprimés par l'appelant).
     */
    public function discardDraft(SurveyRecipient $recipient): object
    {
        $recipient->clearDraft();
        $this->recipientRepository->flush();

        return $this->sendNoContent();
    }

    /**
     * Soumission : toutes les réponses sont validées puis enregistrées en une seule transaction,
     * et le destinataire passe à ANSWERED (specs_fonc §6.2).
     *
     * Corps : { answers: { "<questionId>": valeur } } — texte, nombre, identifiants d'options,
     * date YYYY-MM-DD ou identifiants des fichiers déposés.
     */
    public function submit(string $token, Request $request): object
    {
        $guard = $this->open($token);
        if (self::STATE_OPEN !== ($guard->data['state'] ?? null)) {
            return $guard;
        }

        $recipient = $this->recipientRepository->findOneByToken($token);
        \assert(null !== $recipient);
        $survey = $recipient->getSurvey();

        $rawAnswers = $request->toArray()['answers'] ?? [];
        if (!\is_array($rawAnswers)) {
            return $this->sendViolations(['answers' => 'Format de réponses invalide.']);
        }

        // Validation complète avant toute écriture, dans l'ordre des positions : la visibilité d'une question
        // conditionnelle dépend des réponses (normalisées) aux questions précédentes (specs_tech §9.4)
        $validated = [];
        $errors = [];
        $orderedQuestions = $survey->getOrderedQuestions();
        $questionsById = [];
        foreach ($orderedQuestions as $question) {
            $questionsById[$question->getId()->toRfc4122()] = $question;
        }
        $visible = [];
        $payloads = [];

        foreach ($orderedQuestions as $question) {
            $questionId = $question->getId()->toRfc4122();

            // Question masquée : ni obligatoire, ni validée, ni enregistrée (la valeur reçue est ignorée)
            if (!$this->conditions->isVisible($question, $questionsById, $visible, $payloads)) {
                continue;
            }
            $visible[$questionId] = true;

            $handler = $this->questionTypes->get($question->getType());
            $raw = $rawAnswers[$questionId] ?? null;

            if ($handler->isEmptyAnswer($raw)) {
                if ($question->isRequired()) {
                    $errors[$questionId] = 'Cette question est obligatoire.';
                }
                continue;
            }

            try {
                $payload = $handler->validateAnswer($question, $raw, $recipient);
                $validated[] = ['question' => $question, 'payload' => $payload];
                $payloads[$questionId] = $payload->toArray();
            } catch (InvalidAnswerException $e) {
                $errors[$questionId] = $e->getMessage();
            }
        }

        if ([] !== $errors) {
            return $this->sendViolations($errors);
        }

        $now = $this->clock->now();

        $accepted = $this->recipientRepository->transactional(function () use ($recipient, $validated, $request, $now): bool {
            // Verrou en écriture : un second envoi concurrent du même jeton attend, puis voit ANSWERED
            $this->recipientRepository->lockForSubmission($recipient);
            if (!$recipient->getStatus()->canAnswer()) {
                return false;
            }

            foreach ($validated as ['question' => $question, 'payload' => $payload]) {
                $this->storeAnswer($recipient, $question, $payload->toArray(), $now);
            }

            $this->telemetry->record($recipient, $request);
            $recipient->markAnswered($now);
            $this->recipientRepository->flush();

            return true;
        });

        if (!$accepted) {
            // Rafraîchissement, onglet parallèle ou rejeu HTTP : la première soumission fait foi
            return $this->open($token);
        }

        $this->mailDispatcher->dispatchSilently(new SurveyReceiptMailDto(
            recipientEmail: $recipient->getEmail(),
            surveyTitle: $survey->getTitle(),
            submittedAt: $now,
        ));

        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Réponses enregistrées.', 'participant.submit', [
            'survey' => $survey->getId()->toRfc4122(),
            'recipient' => $recipient->getId()->toRfc4122(),
            'answers' => \count($validated),
        ]);

        $this->lifecycle->closeIfAllAnswered($survey);

        return $this->sendJson([
            'state' => self::STATE_ANSWERED,
            'message' => 'Merci ! Vos réponses ont bien été enregistrées.',
            'submittedAt' => $now->format(\DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * Destinataire autorisé à agir sur le formulaire (téléversement de fichiers), null sinon.
     */
    public function resolveAnswering(string $token): ?SurveyRecipient
    {
        $recipient = $this->recipientRepository->findOneByToken($token);

        if (null === $recipient || !$recipient->getStatus()->canAnswer() || $recipient->getSurvey()->isOverAt($this->clock->now())) {
            return null;
        }

        return $recipient;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeAnswer(SurveyRecipient $recipient, Question $question, array $payload, \DateTimeImmutable $now): void
    {
        $handler = $this->questionTypes->get($question->getType());
        $answer = new SurveyAnswer($recipient, $question, $payload, $handler->extractSearchText($payload), $now);
        $this->answerRepository->save($answer, flush: false);

        if (isset($payload['assetIds'])) {
            $assets = $this->assetRepository->findPendingForAnswer($recipient, $question, (new FileAnswer($payload['assetIds']))->assetIds);
            foreach ($assets as $asset) {
                $asset->attachTo($answer);
            }
        }
    }

    /**
     * Fichiers déjà déposés et non envoyés (reprise du formulaire après rechargement), par question.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function pendingAssets(SurveyRecipient $recipient): array
    {
        $pending = [];

        foreach ($recipient->getSurvey()->getOrderedQuestions() as $question) {
            $assets = $this->assetRepository->findPendingForAnswer($recipient, $question);
            if ([] !== $assets) {
                $pending[$question->getId()->toRfc4122()] = (array) $this->normalizer->normalize($assets, 'json', ['groups' => ['asset.read']]);
            }
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function state(string $state, string $message, int $status, array $extra = []): object
    {
        return $this->sendJson(['state' => $state, 'message' => $message] + $extra, $status);
    }
}
