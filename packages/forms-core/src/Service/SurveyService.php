<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\ResponseTrait;
use FormsCore\DTO\DisplayCondition;
use FormsCore\Entity\Question;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveySection;
use FormsCore\Enum\LogChannel;
use FormsCore\Enum\QuestionType;
use FormsCore\Enum\SurveyDisplayMode;
use FormsCore\Enum\SurveyStatus;
use FormsCore\QuestionType\QuestionTypeRegistry;
use FormsCore\Repository\QuestionRepository;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use FormsCore\Repository\SurveySectionRepository;
use FormsCore\Security\Voter\SurveyVoter;
use FormsCore\Trait\ChannelLoggerTrait;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Module 1 — conception du questionnaire (specs_fonc §3) : métadonnées, structure (sections et
 * questions ordonnées), validation et verrouillage.
 */
final class SurveyService
{
    use ChannelLoggerTrait;
    use ResponseTrait;

    private const MAX_QUESTIONS = 300;
    private const MAX_SECTIONS = 50;
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly SurveyAccess $access,
        private readonly SurveyRepository $surveyRepository,
        private readonly SurveySectionRepository $sectionRepository,
        private readonly QuestionRepository $questionRepository,
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ConditionEvaluatorService $conditions,
        private readonly AssetStorage $assetStorage,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
        private readonly Security $security,
    ) {
    }

    /**
     * Questionnaires du créateur connecté. Paramètres : page, limit, status, q.
     */
    public function list(Request $request): object
    {
        $owner = $this->access->currentUser();
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $status = SurveyStatus::tryFrom(strtoupper((string) $request->query->get('status', '')));

        $result = $this->surveyRepository->paginateForOwner($owner, $status, (string) $request->query->get('q', ''), $page, $limit);
        $counters = $this->recipientRepository->countersForSurveys($result['items']);

        $items = [];
        foreach ($result['items'] as $survey) {
            $items[] = ['survey' => $survey, 'recipients' => $counters[$survey->getId()->toRfc4122()]];
        }

        return $this->sendJson([
            'items' => $items,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'pages' => max(1, (int) ceil($result['total'] / $limit)),
            ],
            'counts' => $this->surveyRepository->countByStatus($owner),
        ], context: ['groups' => ['survey.list']]);
    }

    public function show(string $id): object
    {
        return $this->present($this->access->get($id));
    }

    /**
     * Création d'un brouillon. Corps : { title, description? }.
     */
    public function create(Request $request): object
    {
        $data = $request->toArray();

        $survey = (new Survey($this->access->currentUser()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription(isset($data['description']) ? (string) $data['description'] : null);

        $violations = $this->validator->validate($survey);
        if (\count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $this->surveyRepository->save($survey);
        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Questionnaire créé.', 'survey.create', ['survey' => $survey->getId()->toRfc4122()]);

        return $this->present($survey, Response::HTTP_CREATED);
    }

    /**
     * Métadonnées (titre, description, mode d'affichage). Modifiables tant que la campagne n'est pas lancée :
     * elles ne font pas partie de la structure verrouillée, mais le titre figure dans les invitations envoyées.
     */
    public function updateMetadata(string $id, Request $request): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if ($survey->isActive() || $survey->isClosed()) {
            return $this->conflict('Le titre, la description et l\'affichage ne sont plus modifiables une fois la campagne lancée.');
        }

        $data = $request->toArray();

        if (\array_key_exists('displayMode', $data)) {
            $displayMode = SurveyDisplayMode::tryFrom((string) $data['displayMode']);
            if (null === $displayMode) {
                return $this->sendViolations(['displayMode' => 'Mode d\'affichage inconnu : LIST ou ONE_BY_ONE attendu.']);
            }
            $survey->setDisplayMode($displayMode);
        }

        if (\array_key_exists('showDuration', $data)) {
            if (!\is_bool($data['showDuration'])) {
                return $this->sendViolations(['showDuration' => 'Valeur booléenne attendue.']);
            }
            $survey->setShowDuration($data['showDuration']);
        }

        if (\array_key_exists('title', $data)) {
            $survey->setTitle((string) $data['title']);
        }

        if (\array_key_exists('description', $data)) {
            $survey->setDescription(null === $data['description'] ? null : (string) $data['description']);
        }

        $violations = $this->validator->validate($survey);
        if (\count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $this->surveyRepository->save($survey->touch($this->clock->now()));

        return $this->present($survey);
    }

    /**
     * Enregistre toute la structure d'un brouillon : l'ordre des tableaux reçus fait foi.
     *
     * Corps :
     *   { "sections":  [ { "id", "title", "description" } ],
     *     "questions": [ { "id", "sectionId", "type", "label", "helpTitle", "helpText", "required", "config" } ] }
     *
     * Un brouillon peut être incomplet (intitulé vide, option manquante…) : les règles bloquantes
     * sont contrôlées par validate(). La numérotation est recalculée ici, en continu sur tout le
     * questionnaire, y compris quand une question change de section.
     */
    public function saveStructure(string $id, Request $request): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if ($survey->getStatus()->isStructureLocked()) {
            return $this->conflict('La structure du questionnaire est verrouillée : elle n\'est modifiable qu\'à l\'état brouillon.');
        }

        $data = $request->toArray();
        $rawSections = array_values(array_filter(\is_array($data['sections'] ?? null) ? $data['sections'] : [], 'is_array'));
        $rawQuestions = array_values(array_filter(\is_array($data['questions'] ?? null) ? $data['questions'] : [], 'is_array'));

        if (\count($rawSections) > self::MAX_SECTIONS || \count($rawQuestions) > self::MAX_QUESTIONS) {
            return $this->sendViolations(['structure' => \sprintf('Un questionnaire est limité à %d sections et %d questions.', self::MAX_SECTIONS, self::MAX_QUESTIONS)]);
        }

        foreach ($rawQuestions as $index => $rawQuestion) {
            if (null === QuestionType::tryFrom((string) ($rawQuestion['type'] ?? ''))) {
                return $this->sendViolations([\sprintf('questions[%d].type', $index) => 'Type de question inconnu.']);
            }
        }

        // Conditions d'affichage contrôlées avant toute modification : rien n'est enregistré en cas d'erreur
        $conditionErrors = $this->conditionStructureErrors($rawSections, $rawQuestions);
        if ([] !== $conditionErrors) {
            return $this->sendJson([
                'message' => 'Certaines conditions d\'affichage sont invalides : une question ne peut dépendre que d\'une question placée avant elle.',
                'errors' => $conditionErrors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sectionsByClientId = $this->syncSections($survey, $rawSections);
        $this->syncQuestions($survey, $rawQuestions, $sectionsByClientId);

        $this->surveyRepository->save($survey->touch($this->clock->now()));

        return $this->present($survey);
    }

    /**
     * Valide le questionnaire : DRAFT → VALIDATED, la structure est alors figée (specs_fonc §3.3).
     */
    public function validate(string $id): object
    {
        $survey = $this->access->get($id, SurveyVoter::EDIT);

        if (!$survey->isDraft()) {
            return $this->conflict('Seul un questionnaire à l\'état brouillon peut être validé.');
        }

        $errors = $this->collectValidationErrors($survey);
        if ([] !== $errors) {
            return $this->sendJson([
                'message' => 'Le questionnaire ne peut pas encore être validé.',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now = $this->clock->now();
        $survey->setStatus(SurveyStatus::Validated)->setValidatedAt($now)->touch($now);
        $this->surveyRepository->save($survey);

        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Questionnaire validé : structure verrouillée.', 'survey.validate', [
            'survey' => $survey->getId()->toRfc4122(),
            'questions' => $survey->getQuestionsCount(),
        ]);

        return $this->present($survey);
    }

    /**
     * Copie la structure dans un nouveau brouillon : seul moyen de repartir d'un questionnaire verrouillé.
     */
    public function duplicate(string $id): object
    {
        $source = $this->access->get($id);

        $copy = (new Survey($this->access->currentUser()))
            ->setTitle(mb_substr($source->getTitle(), 0, Survey::TITLE_MAX_LENGTH - 8).' (copie)')
            ->setDescription($source->getDescription())
            ->setDisplayMode($source->getDisplayMode())
            ->setShowDuration($source->isShowDuration());

        $sections = [];
        $copies = [];
        foreach ($source->getOrderedSections() as $section) {
            $sections[$section->getId()->toRfc4122()] = (new SurveySection())
                ->setTitle($section->getTitle())
                ->setDescription($section->getDescription())
                ->setPosition($section->getPosition());
            $copy->addSection($sections[$section->getId()->toRfc4122()]);
        }

        foreach ($source->getOrderedQuestions() as $question) {
            $sectionId = $question->getSectionId()?->toRfc4122();

            $copies[$question->getId()->toRfc4122()] = (new Question())
                ->setType($question->getType())
                ->setLabel($question->getLabel())
                ->setHelpTitle($question->getHelpTitle())
                ->setHelpText($question->getHelpText())
                ->setRequired($question->isRequired())
                ->setPosition($question->getPosition())
                ->setConfig($question->getConfig())
                ->setSection(null !== $sectionId ? ($sections[$sectionId] ?? null) : null);
            $copy->addQuestion($copies[$question->getId()->toRfc4122()]);
        }

        // Conditions recopiées vers les nouvelles questions sources
        foreach ($source->getOrderedQuestions() as $question) {
            $condition = $question->getCondition();
            $newSource = null !== $condition ? ($copies[$condition->sourceQuestionId] ?? null) : null;
            if (null !== $newSource) {
                $copies[$question->getId()->toRfc4122()]->setCondition($condition->withSource($newSource->getId()->toRfc4122()));
            }
        }

        $this->surveyRepository->save($copy);

        return $this->present($copy, Response::HTTP_CREATED);
    }

    public function delete(string $id): object
    {
        $survey = $this->access->get($id, SurveyVoter::DELETE);

        if ($survey->isActive()) {
            return $this->conflict('Une campagne en cours ne peut pas être supprimée : clôturez-la d\'abord.');
        }

        $surveyId = $survey->getId()->toRfc4122();
        $this->surveyRepository->remove($survey);
        // Fichiers des participants et archive ZIP : les lignes en base partent en cascade
        $this->assetStorage->deleteDirectory('surveys/'.$surveyId);
        $this->assetStorage->deleteDirectory('archives/'.$surveyId);

        $this->logTo(LogChannel::App, LoggerLevelEnum::Info, 'Questionnaire supprimé.', 'survey.delete', ['survey' => $surveyId]);

        return $this->sendNoContent();
    }

    /**
     * Règles bloquant la validation (specs_fonc §3.3 et §3.4).
     *
     * @return list<array{scope: string, id: string|null, position: int|null, message: string}>
     */
    public function collectValidationErrors(Survey $survey): array
    {
        $errors = [];
        $titleLength = mb_strlen($survey->getTitle());

        if ($titleLength < Survey::TITLE_MIN_LENGTH || $titleLength > Survey::TITLE_MAX_LENGTH) {
            $errors[] = self::error('survey', null, null, \sprintf('Le titre doit contenir entre %d et %d caractères.', Survey::TITLE_MIN_LENGTH, Survey::TITLE_MAX_LENGTH));
        }

        $questions = $survey->getOrderedQuestions();
        if ([] === $questions) {
            $errors[] = self::error('survey', null, null, 'Le questionnaire doit contenir au moins une question.');
        }

        $sections = $survey->getOrderedSections();
        $questionsPerSection = [];
        $questionsById = [];
        foreach ($questions as $question) {
            $questionsById[$question->getId()->toRfc4122()] = $question;
        }

        foreach ($questions as $question) {
            $questionId = $question->getId()->toRfc4122();
            $position = $question->getPosition();

            if ('' === $question->getLabel()) {
                $errors[] = self::error('question', $questionId, $position, 'L\'intitulé de la question est obligatoire.');
            }

            foreach ($this->questionTypes->get($question->getType())->validateConfig($question->getConfig()) as $message) {
                $errors[] = self::error('question', $questionId, $position, $message);
            }

            foreach ($this->conditions->designErrors($question, $questionsById) as $message) {
                $errors[] = self::error('question', $questionId, $position, $message);
            }

            $sectionId = $question->getSectionId()?->toRfc4122();
            if ([] !== $sections && null === $sectionId) {
                $errors[] = self::error('question', $questionId, $position, 'La question doit appartenir à une section.');
            }

            if (null !== $sectionId) {
                $questionsPerSection[$sectionId] = ($questionsPerSection[$sectionId] ?? 0) + 1;
            }
        }

        foreach ($sections as $section) {
            $sectionId = $section->getId()->toRfc4122();
            $sectionTitleLength = mb_strlen($section->getTitle());

            if ($sectionTitleLength < SurveySection::TITLE_MIN_LENGTH || $sectionTitleLength > SurveySection::TITLE_MAX_LENGTH) {
                $errors[] = self::error('section', $sectionId, $section->getPosition(), \sprintf('Le titre de la section doit contenir entre %d et %d caractères.', SurveySection::TITLE_MIN_LENGTH, SurveySection::TITLE_MAX_LENGTH));
            }

            if (0 === ($questionsPerSection[$sectionId] ?? 0)) {
                $errors[] = self::error('section', $sectionId, $section->getPosition(), 'Chaque section doit contenir au moins une question.');
            }
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $rawSections
     *
     * @return array<string, SurveySection> sections indexées par l'identifiant reçu du builder
     */
    private function syncSections(Survey $survey, array $rawSections): array
    {
        $existing = [];
        foreach ($survey->getSections() as $section) {
            $existing[$section->getId()->toRfc4122()] = $section;
        }

        $kept = [];
        $byClientId = [];

        foreach ($rawSections as $index => $raw) {
            $clientId = \is_string($raw['id'] ?? null) ? $raw['id'] : '';
            $section = $existing[$clientId] ?? null;

            if (null === $section) {
                $section = new SurveySection($this->availableId($clientId, fn (Uuid $uuid): bool => null !== $this->sectionRepository->find($uuid)));
                $survey->addSection($section);
            }

            $section
                ->setTitle(mb_substr((string) ($raw['title'] ?? ''), 0, SurveySection::TITLE_MAX_LENGTH))
                ->setDescription(isset($raw['description']) ? mb_substr((string) $raw['description'], 0, 5000) : null)
                ->setPosition($index + 1);

            $kept[$section->getId()->toRfc4122()] = true;
            if ('' !== $clientId) {
                $byClientId[$clientId] = $section;
            }
        }

        foreach ($existing as $sectionId => $section) {
            if (!isset($kept[$sectionId])) {
                $survey->removeSection($section);
            }
        }

        return $byClientId;
    }

    /**
     * @param list<array<string, mixed>>   $rawQuestions
     * @param array<string, SurveySection> $sectionsByClientId
     */
    private function syncQuestions(Survey $survey, array $rawQuestions, array $sectionsByClientId): void
    {
        $existing = [];
        foreach ($survey->getQuestions() as $question) {
            $existing[$question->getId()->toRfc4122()] = $question;
        }

        $firstSection = [] === $sectionsByClientId ? null : array_values($sectionsByClientId)[0];
        $kept = [];
        $ordered = [];
        $byClientId = [];
        $rawConditions = [];

        foreach ($rawQuestions as $index => $raw) {
            $clientId = \is_string($raw['id'] ?? null) ? $raw['id'] : '';
            $question = $existing[$clientId] ?? null;

            if (null === $question) {
                $question = new Question($this->availableId($clientId, fn (Uuid $uuid): bool => null !== $this->questionRepository->find($uuid)));
                $survey->addQuestion($question);
            }

            $type = QuestionType::from((string) $raw['type']);
            $sectionClientId = \is_string($raw['sectionId'] ?? null) ? $raw['sectionId'] : '';
            // Dès qu'il existe des sections, chaque question appartient à exactement une section
            $section = $sectionsByClientId[$sectionClientId] ?? $firstSection;

            $question
                ->setType($type)
                ->setLabel(mb_substr((string) ($raw['label'] ?? ''), 0, Question::LABEL_MAX_LENGTH))
                ->setHelpTitle(isset($raw['helpTitle']) ? mb_substr((string) $raw['helpTitle'], 0, Question::HELP_TITLE_MAX_LENGTH) : null)
                ->setHelpText(isset($raw['helpText']) ? mb_substr((string) $raw['helpText'], 0, 5000) : null)
                ->setRequired(filter_var($raw['required'] ?? true, \FILTER_VALIDATE_BOOL))
                ->setConfig($this->questionTypes->get($type)->normalizeConfig(\is_array($raw['config'] ?? null) ? $raw['config'] : []))
                ->setSection($section);

            $kept[$question->getId()->toRfc4122()] = true;
            $ordered[] = ['question' => $question, 'section' => $section?->getPosition() ?? 0, 'index' => $index];
            $byClientId['' !== $clientId ? $clientId : $question->getId()->toRfc4122()] = $question;
            $rawConditions[] = [$question, DisplayCondition::fromArray($raw['displayCondition'] ?? null)];
        }

        foreach ($existing as $questionId => $question) {
            if (!isset($kept[$questionId])) {
                $survey->removeQuestion($question);
            }
        }

        // Numérotation continue : ordre des sections, puis ordre des questions dans chaque section
        usort($ordered, static fn (array $a, array $b): int => [$a['section'], $a['index']] <=> [$b['section'], $b['index']]);
        foreach ($ordered as $position => $entry) {
            $entry['question']->setPosition($position + 1);
        }

        // Conditions rattachées à l'identifiant réel de la source ; source supprimée → condition retirée
        foreach ($rawConditions as [$question, $condition]) {
            $source = null !== $condition ? ($byClientId[$condition->sourceQuestionId] ?? null) : null;
            $question->setCondition(null !== $source && $source !== $question ? $condition->withSource($source->getId()->toRfc4122()) : null);
        }
    }

    /**
     * Contrôle des conditions sur la structure reçue, dans l'ordre final (sections puis questions) :
     * une source absente sera retirée ; une source placée après la question, une source FILE, un opérateur
     * incompatible ou une valeur mal formée sont refusés (specs_tech §9.5).
     *
     * @param list<array<string, mixed>> $rawSections
     * @param list<array<string, mixed>> $rawQuestions
     *
     * @return list<array{scope: string, id: string|null, position: int|null, message: string}>
     */
    private function conditionStructureErrors(array $rawSections, array $rawQuestions): array
    {
        $sectionIndex = [];
        foreach ($rawSections as $index => $raw) {
            if (\is_string($raw['id'] ?? null)) {
                $sectionIndex[$raw['id']] ??= $index;
            }
        }

        $ordered = [];
        foreach ($rawQuestions as $index => $raw) {
            $ordered[] = ['raw' => $raw, 'section' => $sectionIndex[(string) ($raw['sectionId'] ?? '')] ?? 0, 'index' => $index];
        }
        usort($ordered, static fn (array $a, array $b): int => [$a['section'], $a['index']] <=> [$b['section'], $b['index']]);

        $positions = [];
        $types = [];
        $configs = [];
        foreach ($ordered as $position => ['raw' => $raw]) {
            $id = (string) ($raw['id'] ?? '');
            $positions[$id] = $position + 1;
            $types[$id] = QuestionType::from((string) $raw['type']);
            // Configuration normalisée : le choix unique / multiple de la source en dépend
            $configs[$id] = $this->questionTypes->get($types[$id])->normalizeConfig(\is_array($raw['config'] ?? null) ? $raw['config'] : []);
        }

        $errors = [];
        foreach ($ordered as $position => ['raw' => $raw]) {
            $condition = DisplayCondition::fromArray($raw['displayCondition'] ?? null);
            if (null === $condition || !isset($positions[$condition->sourceQuestionId])) {
                continue;
            }

            $source = $condition->sourceQuestionId;
            foreach ($this->conditions->structureErrors($condition, $types[$source], $configs[$source], $positions[$source], $position + 1) as $message) {
                $errors[] = self::error('question', \is_string($raw['id'] ?? null) ? $raw['id'] : null, $position + 1, $message);
            }
        }

        return $errors;
    }

    /**
     * Identifiant du builder s'il est valide et libre, sinon null (un UUID v7 sera généré).
     *
     * @param callable(Uuid): bool $exists
     */
    private function availableId(string $clientId, callable $exists): ?Uuid
    {
        if (!Uuid::isValid($clientId)) {
            return null;
        }

        $uuid = Uuid::fromString($clientId);

        return $exists($uuid) ? null : $uuid;
    }

    private function present(Survey $survey, int $status = Response::HTTP_OK): object
    {
        return $this->sendJson([
            'survey' => $survey,
            'recipients' => $this->recipientRepository->counters($survey),
        ], $status, context: ['groups' => ['survey.read']]);
    }

    private function conflict(string $message): object
    {
        return $this->sendJson(['message' => $message], Response::HTTP_CONFLICT);
    }

    /**
     * @return array{scope: string, id: string|null, position: int|null, message: string}
     */
    private static function error(string $scope, ?string $id, ?int $position, string $message): array
    {
        return ['scope' => $scope, 'id' => $id, 'position' => $position, 'message' => $message];
    }
}
