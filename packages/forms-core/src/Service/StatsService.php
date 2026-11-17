<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Http\ResponseTrait;
use FormsCore\Entity\Survey;
use FormsCore\Enum\QuestionType;
use FormsCore\QuestionType\QuestionTypeRegistry;
use FormsCore\Repository\QuestionRepository;
use FormsCore\Repository\SurveyAnswerRepository;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Repository\UploadedAssetRepository;
use FormsCore\Util\Statistics;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 — tableau de bord statistique (specs_fonc §7.2).
 *
 * - ACTIVE : calcul en temps réel (partiel), sauf sondage volumineux servi depuis l'instantané nocturne ;
 * - CLOSED : statistiques consolidées, calculées une fois à la clôture puis figées.
 */
final class StatsService
{
    use ResponseTrait;

    /** À partir de ce nombre de réponses, un sondage actif est servi depuis son instantané pré-calculé. */
    public const LARGE_SURVEY_THRESHOLD = 1000;
    private const SNAPSHOT_MAX_AGE = '-26 hours';
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly SurveyAccess $access,
        private readonly SurveyRepository $surveyRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly SurveyAnswerRepository $answerRepository,
        private readonly QuestionRepository $questionRepository,
        private readonly UploadedAssetRepository $assetRepository,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Statistiques d'un questionnaire. `?refresh=1` force le recalcul d'un sondage actif.
     */
    public function show(string $id, Request $request): object
    {
        $survey = $this->access->get($id);

        if ($survey->isDraft() || $survey->isValidated()) {
            return $this->sendJson(['message' => 'Les statistiques sont disponibles une fois la campagne lancée.'], Response::HTTP_CONFLICT);
        }

        return $this->sendJson([
            'survey' => $survey,
            'stats' => $this->resolve($survey, $request->query->getBoolean('refresh')),
        ], context: ['groups' => ['survey.list']]);
    }

    /**
     * Verbatims d'une question TEXT : liste paginée avec recherche par mot-clé. Paramètres : page, q.
     */
    public function verbatims(string $id, string $questionId, Request $request): object
    {
        $survey = $this->access->get($id);
        $question = $this->questionRepository->findOneInSurvey($survey, $questionId);

        if (null === $question || QuestionType::Text !== $question->getType()) {
            return $this->notFoundResponse('Question introuvable.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->answerRepository->paginateVerbatims($question, (string) $request->query->get('q', ''), $page, self::PAGE_SIZE);

        return $this->sendJson([
            'items' => $result['items'],
            'meta' => self::meta($page, $result['total']),
        ]);
    }

    /**
     * Galerie des fichiers d'une question FILE, paginée.
     */
    public function files(string $id, string $questionId, Request $request): object
    {
        $survey = $this->access->get($id);
        $question = $this->questionRepository->findOneInSurvey($survey, $questionId);

        if (null === $question || QuestionType::File !== $question->getType()) {
            return $this->notFoundResponse('Question introuvable.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->assetRepository->paginateAttached($question, $page, self::PAGE_SIZE);

        return $this->sendJson([
            'items' => $result['items'],
            'meta' => self::meta($page, $result['total']),
        ], context: ['groups' => ['asset.read']]);
    }

    /**
     * Calcule et fige l'instantané (appelé à la clôture, et la nuit pour les sondages actifs volumineux).
     */
    public function snapshot(Survey $survey): void
    {
        $now = $this->clock->now();
        $survey->setStatsSnapshot($this->compute($survey), $now);
        $this->surveyRepository->save($survey);
    }

    /**
     * Un sondage actif est « volumineux » quand ses indicateurs lourds méritent un pré-calcul.
     */
    public function isLarge(Survey $survey): bool
    {
        return $this->recipientRepository->counters($survey)['answered'] >= self::LARGE_SURVEY_THRESHOLD;
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(Survey $survey): array
    {
        $counters = $this->recipientRepository->counters($survey);
        $payloadsByQuestion = $this->answerRepository->payloadsByQuestion($survey);
        $averageCompletion = $this->recipientRepository->averageCompletionSeconds($survey);

        $questions = [];
        foreach ($survey->getOrderedQuestions() as $question) {
            $payloads = $payloadsByQuestion[$question->getId()->toRfc4122()] ?? [];

            $questions[] = [
                'id' => $question->getId()->toRfc4122(),
                'position' => $question->getPosition(),
                'label' => $question->getLabel(),
                'type' => $question->getType()->value,
                'required' => $question->isRequired(),
                'config' => $question->getConfig(),
                'answersCount' => \count($payloads),
                'answerRate' => Statistics::percent(\count($payloads), $counters['answered']),
                'stats' => $this->questionTypes->get($question->getType())->computeStats($question, $payloads),
            ];
        }

        return [
            'global' => [
                'invited' => $counters['total'],
                'valid' => $counters['valid'],
                'bounced' => $counters['bounced'],
                'pending' => $counters['pending'],
                'sent' => $counters['sent'],
                'opened' => $counters['opened'],
                'answered' => $counters['answered'],
                // Taux de participation : répondants / invités valides (hors BOUNCED)
                'participationRate' => Statistics::percent($counters['answered'], $counters['valid']),
                // Ouverture sans soumission : OPENED non ANSWERED
                'openedNotAnsweredRate' => Statistics::percent($counters['opened'], $counters['valid']),
                'averageCompletionSeconds' => null === $averageCompletion ? null : (int) round($averageCompletion),
                'browsers' => $this->recipientRepository->countAnsweredBy($survey, 'browserName'),
                'operatingSystems' => $this->recipientRepository->countAnsweredBy($survey, 'osName'),
                'devices' => $this->recipientRepository->countAnsweredBy($survey, 'deviceType'),
            ],
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string, mixed> statistiques + métadonnées de calcul (computedAt, frozen)
     */
    private function resolve(Survey $survey, bool $refresh): array
    {
        $snapshot = $survey->getStatsSnapshot();
        $computedAt = $survey->getStatsComputedAt();

        if ($survey->isClosed()) {
            // Consolidées et figées : calculées une seule fois, même si la clôture n'a pas pu le faire
            if (null === $snapshot || null === $computedAt) {
                $this->snapshot($survey);
                $snapshot = (array) $survey->getStatsSnapshot();
                $computedAt = $survey->getStatsComputedAt();
            }

            return $snapshot + ['computedAt' => $computedAt?->format(\DATE_ATOM), 'frozen' => true];
        }

        $isFresh = null !== $computedAt && $computedAt >= $this->clock->now()->modify(self::SNAPSHOT_MAX_AGE);
        if (!$refresh && null !== $snapshot && $isFresh && $this->isLarge($survey)) {
            return $snapshot + ['computedAt' => $computedAt->format(\DATE_ATOM), 'frozen' => false];
        }

        return $this->compute($survey) + ['computedAt' => $this->clock->now()->format(\DATE_ATOM), 'frozen' => false];
    }

    /**
     * @return array{page: int, limit: int, total: int, pages: int}
     */
    private static function meta(int $page, int $total): array
    {
        return ['page' => $page, 'limit' => self::PAGE_SIZE, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE))];
    }
}
