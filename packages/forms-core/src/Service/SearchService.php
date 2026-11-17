<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Http\ResponseTrait;
use FormsCore\Entity\SurveyAnswer;
use FormsCore\Repository\QuestionRepository;
use FormsCore\Repository\SurveyAnswerRepository;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Recherche plein texte du dashboard (specs_fonc §7.2, specs_tech §3.5) : questionnaires, questions,
 * destinataires et verbatims, triés par pertinence, limités aux questionnaires du créateur connecté.
 */
final class SearchService
{
    use ResponseTrait;

    public const MIN_QUERY_LENGTH = 2;
    private const LIMIT_PER_GROUP = 10;

    public function __construct(
        private readonly SurveyAccess $access,
        private readonly SurveyRepository $surveyRepository,
        private readonly QuestionRepository $questionRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly SurveyAnswerRepository $answerRepository,
    ) {
    }

    /**
     * Paramètre : q (2 caractères minimum).
     */
    public function search(Request $request): object
    {
        $owner = $this->access->currentUser();
        $query = trim((string) $request->query->get('q', ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return $this->sendJson(['query' => $query, 'surveys' => [], 'questions' => [], 'recipients' => [], 'verbatims' => []]);
        }

        $verbatims = array_map(static fn (SurveyAnswer $answer): array => [
            'id' => $answer->getId()->toRfc4122(),
            'text' => mb_substr((string) $answer->getSearchText(), 0, 300),
            'surveyId' => $answer->getSurvey()->getId()->toRfc4122(),
            'surveyTitle' => $answer->getSurvey()->getTitle(),
            'questionId' => $answer->getQuestion()->getId()->toRfc4122(),
            'questionLabel' => $answer->getQuestion()->getPlainLabel(),
        ], $this->answerRepository->searchFullText($owner, $query, self::LIMIT_PER_GROUP));

        return $this->sendJson([
            'query' => $query,
            'surveys' => $this->surveyRepository->searchFullText($owner, $query, self::LIMIT_PER_GROUP),
            'questions' => $this->questionRepository->searchFullText($owner, $query, self::LIMIT_PER_GROUP),
            'recipients' => $this->recipientRepository->searchFullText($owner, $query, self::LIMIT_PER_GROUP),
            'verbatims' => $verbatims,
        ], context: ['groups' => ['survey.list', 'question.search', 'recipient.search']]);
    }
}
