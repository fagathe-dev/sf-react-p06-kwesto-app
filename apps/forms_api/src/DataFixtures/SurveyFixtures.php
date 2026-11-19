<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use FormsCore\DTO\DisplayCondition;
use FormsCore\DTO\QuestionConfig\ChoiceConfig;
use FormsCore\DTO\QuestionConfig\DateConfig;
use FormsCore\DTO\QuestionConfig\FileConfig;
use FormsCore\DTO\QuestionConfig\NumberConfig;
use FormsCore\DTO\QuestionConfig\RangeConfig;
use FormsCore\DTO\QuestionConfig\TextConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyAnswer;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Entity\SurveySection;
use FormsCore\Entity\UploadedAsset;
use FormsCore\Entity\User;
use FormsCore\Enum\CloseReason;
use FormsCore\Enum\ConditionOperator;
use FormsCore\Enum\DeviceType;
use FormsCore\Enum\QuestionType;
use FormsCore\Enum\SurveyDisplayMode;
use FormsCore\Enum\SurveyStatus;
use FormsCore\QuestionType\QuestionTypeRegistry;
use FormsCore\Service\AssetStorage;
use FormsCore\Service\ConditionEvaluatorService;
use FormsCore\Service\StatsService;
use FormsCore\Service\TelemetryService;
use ZipStream\ZipStream;

/**
 * Jeu de données de démonstration : questionnaires dans tous les états du cycle de vie, destinataires
 * dans tous les statuts, réponses aux 7 types de questions, fichiers déposés et télémétrie.
 *
 * Liens participants fixes (forms_bo : {APP_BO_URL}/s/{jeton}) :
 *
 * | Jeton        | Questionnaire                         | Écran attendu                      |
 * |--------------|---------------------------------------|------------------------------------|
 * | aaaa…aaaa    | Baromètre (ACTIVE, 3 sections)        | Formulaire en étapes (stepper)     |
 * | bbbb…bbbb    | Dossier de candidature (ACTIVE, FILE) | Question par question, fichiers    |
 * | cccc…cccc    | Télétravail (ACTIVE, page unique)     | Formulaire sur une page            |
 * | dddd…dddd    | Baromètre (ACTIVE)                    | « Vous avez déjà répondu… »        |
 * | eeee…eeee    | Formation (CLOSED)                    | « Ce questionnaire est clôturé… »  |
 */
final class SurveyFixtures extends Fixture implements DependentFixtureInterface
{
    public const TOKEN_STEPPER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const TOKEN_FILES = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public const TOKEN_SINGLE_PAGE = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    public const TOKEN_ANSWERED = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    public const TOKEN_CLOSED = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** User-Agents réalistes avec leur télémétrie attendue : [UA, navigateur, version, appareil]. */
    private const USER_AGENTS = [
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome', '141', DeviceType::Desktop, 30],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge', '141', DeviceType::Desktop, 15],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', 'Safari', '18', DeviceType::Desktop, 12],
        ['Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0', 'Firefox', '144', DeviceType::Desktop, 8],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1', 'Safari', '18', DeviceType::Mobile, 18],
        ['Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome', '141', DeviceType::Mobile, 12],
        ['Mozilla/5.0 (iPad; CPU OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1', 'Safari', '18', DeviceType::Tablet, 5],
    ];

    private const VERBATIMS = [
        'Plus de visibilité sur les priorités de l\'équipe et moins de réunions de suivi.',
        'Un outil unique pour les demandes internes, aujourd\'hui tout passe par e-mail.',
        'Des écrans plus grands et un siège ergonomique, je passe ma journée devant l\'ordinateur.',
        'Des points d\'équipe plus courts, avec un ordre du jour envoyé à l\'avance.',
        'Pouvoir télétravailler un jour de plus quand la charge le permet.',
        'Une meilleure documentation des procédures : on perd du temps à chercher la bonne personne.',
        'Rien de particulier, l\'organisation actuelle me convient très bien.',
        'Davantage de formations sur les nouveaux outils avant leur déploiement.',
        'Des retours plus réguliers de mon manager, pas seulement lors de l\'entretien annuel.',
        'Un espace calme pour les appels, l\'open space est bruyant l\'après-midi.',
        'Le partage des bonnes pratiques entre les équipes de Lyon et de Nantes.',
        'Clarifier qui valide quoi : les circuits de validation sont trop longs.',
        'La formatrice était très pédagogue et les exercices pratiques étaient pertinents.',
        'Un peu trop de théorie le premier jour, mais la mise en situation était excellente.',
        'Le rythme était soutenu ; une demi-journée de plus aurait été utile.',
        'Une connexion internet plus stable à domicile, prise en charge par l\'entreprise.',
    ];

    private Generator $faker;
    private \DateTimeImmutable $now;
    /** @var array<string, true> */
    private array $usedEmails = [];

    public function __construct(
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ConditionEvaluatorService $conditions,
        private readonly AssetStorage $storage,
        private readonly StatsService $statsService,
    ) {
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $this->faker = Factory::create('fr_FR');
        $this->faker->seed(2026);
        mt_srand(2026);
        $this->now = new \DateTimeImmutable();

        $camille = $this->getReference('user_camille@kwesto.test', User::class);
        $alex = $this->getReference('user_alex@kwesto.test', User::class);
        $sacha = $this->getReference('user_sacha@kwesto.test', User::class);
        $admin = $this->getReference('user_admin@kwesto.test', User::class);

        $closed = [];

        // --- Camille : un questionnaire dans chaque état
        $this->draft($manager, $camille, 'barometre', '-2 hours', complete: false);
        $this->draft($manager, $camille, 'evenement', '-75 days', complete: true); // supprimé par le nettoyage hebdomadaire
        $this->validated($manager, $camille, 'formation', recipients: 24);
        $this->active($manager, $camille, 'barometre', recipients: 60, answeredRate: 0.55, launched: '-3 days', expires: '+12 days', fixedTokens: [self::TOKEN_STEPPER => 'sent', self::TOKEN_ANSWERED => 'answered']);
        // Fenêtre de relance J-5 / J-4, lancé depuis plus de 48 h, ≤ 20 % de réponses : cible de la relance
        $this->active($manager, $camille, 'teletravail', recipients: 40, answeredRate: 0.12, launched: '-6 days', expires: '+4 days 12 hours', fixedTokens: [self::TOKEN_SINGLE_PAGE => 'sent']);
        // Date limite dépassée : clôturé au prochain passage de la tâche close-expired
        $this->active($manager, $camille, 'evenement', recipients: 30, answeredRate: 0.7, launched: '-10 days', expires: '-1 hour');
        $closed[] = $this->closed($manager, $camille, 'formation', recipients: 18, answeredRate: 1.0, launched: '-50 days', closedAt: '-40 days', reason: CloseReason::AllAnswered, fixedTokens: [self::TOKEN_CLOSED => 'answered']);
        $closed[] = $this->closed($manager, $camille, 'dossier', recipients: 25, answeredRate: 0.68, launched: '-20 days', closedAt: '-2 days', reason: CloseReason::DeadlineReached);

        // --- Alex : dossier de candidature ouvert (dépôt de fichiers) et baromètre clôturé manuellement
        $this->active($manager, $alex, 'dossier', recipients: 20, answeredRate: 0.35, launched: '-5 days', expires: '+20 days', fixedTokens: [self::TOKEN_FILES => 'sent']);
        $closed[] = $this->closed($manager, $alex, 'barometre', recipients: 45, answeredRate: 0.8, launched: '-30 days', closedAt: '-7 days', reason: CloseReason::Manual);
        $this->draft($manager, $alex, 'teletravail', '-1 day', complete: true);

        // --- Sacha : premiers pas
        $this->draft($manager, $sacha, 'formation', '-10 minutes', complete: false);
        $this->validated($manager, $sacha, 'evenement', recipients: 12);

        // --- Administrateur : il a aussi les droits du créateur
        $this->active($manager, $admin, 'formation', recipients: 15, answeredRate: 0.4, launched: '-1 day', expires: '+6 days');

        $manager->flush();

        // Statistiques consolidées et figées des questionnaires clôturés
        foreach ($closed as $survey) {
            $this->statsService->snapshot($survey);
        }
    }

    private function draft(ObjectManager $manager, User $owner, string $blueprint, string $updated, bool $complete): Survey
    {
        $survey = $this->buildSurvey($owner, $blueprint, $complete);
        $survey->touch($this->at($updated));
        $manager->persist($survey);

        return $survey;
    }

    private function validated(ObjectManager $manager, User $owner, string $blueprint, int $recipients): Survey
    {
        $survey = $this->buildSurvey($owner, $blueprint)
            ->setStatus(SurveyStatus::Validated)
            ->setValidatedAt($this->at('-1 day'));
        $survey->touch($this->at('-1 day'));
        $manager->persist($survey);

        for ($i = 0; $i < $recipients; ++$i) {
            $manager->persist(new SurveyRecipient($survey, $this->email()));
        }

        return $survey;
    }

    /**
     * @param array<string, 'sent'|'answered'> $fixedTokens
     */
    private function active(ObjectManager $manager, User $owner, string $blueprint, int $recipients, float $answeredRate, string $launched, string $expires, array $fixedTokens = []): Survey
    {
        $activatedAt = $this->at($launched);
        $survey = $this->buildSurvey($owner, $blueprint)
            ->setStatus(SurveyStatus::Active)
            ->setValidatedAt($activatedAt->modify('-1 hour'))
            ->setActivatedAt($activatedAt)
            ->setExpiresAt($this->at($expires));
        $survey->touch($activatedAt);
        $manager->persist($survey);

        $this->campaign($manager, $survey, $recipients, $answeredRate, $activatedAt, min($this->now, $this->at($expires)), $fixedTokens);

        return $survey;
    }

    /**
     * @param array<string, 'sent'|'answered'> $fixedTokens
     */
    private function closed(ObjectManager $manager, User $owner, string $blueprint, int $recipients, float $answeredRate, string $launched, string $closedAt, CloseReason $reason, array $fixedTokens = []): Survey
    {
        $activatedAt = $this->at($launched);
        $closedOn = $this->at($closedAt);

        $survey = $this->buildSurvey($owner, $blueprint)
            ->setStatus(SurveyStatus::Closed)
            ->setValidatedAt($activatedAt->modify('-2 hours'))
            ->setActivatedAt($activatedAt)
            ->setExpiresAt(CloseReason::DeadlineReached === $reason ? $closedOn : $closedOn->modify('+5 days'))
            ->setClosedAt($closedOn)
            ->setCloseReason($reason);
        $survey->touch($closedOn);
        $manager->persist($survey);

        $this->campaign($manager, $survey, $recipients, $answeredRate, $activatedAt, $closedOn, $fixedTokens);

        return $survey;
    }

    /**
     * Destinataires d'une campagne lancée, dans un mélange réaliste de statuts.
     *
     * @param array<string, 'sent'|'answered'> $fixedTokens
     */
    private function campaign(ObjectManager $manager, Survey $survey, int $count, float $answeredRate, \DateTimeImmutable $start, \DateTimeImmutable $end, array $fixedTokens): void
    {
        $bounced = $answeredRate >= 1.0 ? 0 : max(1, (int) round($count * 0.04));
        $answered = (int) round(($count - $bounced) * $answeredRate);
        $opened = $answeredRate >= 1.0 ? 0 : (int) round(($count - $bounced - $answered) * 0.4);

        $statuses = array_merge(
            array_fill(0, $answered, 'answered'),
            array_fill(0, $opened, 'opened'),
            array_fill(0, $bounced, 'bounced'),
            array_fill(0, max(0, $count - $answered - $opened - $bounced), 'sent'),
        );
        shuffle($statuses);

        foreach ($fixedTokens as $token => $status) {
            $email = 'answered' === $status ? 'participant.repondu@kwesto.test' : 'participant@kwesto.test';
            $this->recipient($manager, $survey, $email, $token, $status, $start, $end);
        }

        foreach ($statuses as $status) {
            $this->recipient($manager, $survey, $this->email(), bin2hex(random_bytes(32)), $status, $start, $end);
        }
    }

    private function recipient(ObjectManager $manager, Survey $survey, string $email, string $token, string $status, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $recipient = (new SurveyRecipient($survey, $email))->setToken($token);
        $manager->persist($recipient);

        $sentAt = $start->modify(\sprintf('+%d seconds', mt_rand(5, 300)));

        if ('bounced' === $status) {
            $recipient->markBounced($sentAt, '550 5.1.1 Adresse de destination inconnue.');

            return;
        }

        $recipient->markSent($sentAt);
        if ('sent' === $status) {
            return;
        }

        $openedAt = $this->between($sentAt, $end);
        $recipient->markOpened($openedAt);
        if ('opened' === $status) {
            return;
        }

        $submittedAt = min($end, $openedAt->modify(\sprintf('+%d seconds', mt_rand(70, 900))));
        $this->answer($manager, $recipient, $submittedAt);

        [$userAgent, $browser, $version, $device] = $this->userAgent();
        $recipient->setTelemetry(
            ipAddress: $this->faker->ipv4(),
            userAgentRaw: $userAgent,
            browserName: $browser,
            browserVersion: $version,
            osName: TelemetryService::detectOs($userAgent),
            deviceType: $device,
        );
        $recipient->markAnswered($submittedAt);
    }

    private function answer(ObjectManager $manager, SurveyRecipient $recipient, \DateTimeImmutable $at): void
    {
        $questions = $recipient->getSurvey()->getOrderedQuestions();
        $questionsById = [];
        foreach ($questions as $question) {
            $questionsById[$question->getId()->toRfc4122()] = $question;
        }
        $visible = [];
        $payloads = [];

        foreach ($questions as $question) {
            $questionId = $question->getId()->toRfc4122();

            // Question conditionnelle masquée pour ce participant : aucune réponse (comme dans le vrai formulaire)
            if (!$this->conditions->isVisible($question, $questionsById, $visible, $payloads)) {
                continue;
            }
            $visible[$questionId] = true;

            if (!$question->isRequired() && mt_rand(1, 100) > 70) {
                continue;
            }

            $handler = $this->questionTypes->get($question->getType());

            if (QuestionType::File === $question->getType()) {
                $this->fileAnswer($manager, $recipient, $question, $at);
                continue;
            }

            $payload = $this->payload($question);
            $payloads[$questionId] = $payload;
            $manager->persist(new SurveyAnswer($recipient, $question, $payload, $handler->extractSearchText($payload), $at));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Question $question): array
    {
        $config = $question->getConfig();

        return match ($question->getType()) {
            QuestionType::Text => ['value' => $this->text(TextConfig::fromArray($config))],
            QuestionType::Number => ['value' => $this->number(NumberConfig::fromArray($config))],
            QuestionType::Choice => ['optionIds' => $this->choices(ChoiceConfig::fromArray($config))],
            QuestionType::Date => ['value' => $this->date(DateConfig::fromArray($config))],
            QuestionType::File => ['assetIds' => []],
            QuestionType::Boolean => ['value' => mt_rand(1, 100) <= 65],
            QuestionType::Range => ['value' => $this->range(RangeConfig::fromArray($config))],
        };
    }

    /** Valeur de l'échelle, tirée vers le haut (avis plutôt positifs, comme dans la réalité). */
    private function range(RangeConfig $config): int|float
    {
        $values = $config->values();
        $index = (int) round((\count($values) - 1) * (1 - (mt_rand(0, 100) / 100) ** 2));

        return $values[max(0, min(\count($values) - 1, $index))];
    }

    private function text(TextConfig $config): string
    {
        $text = $config->multiline
            ? $this->faker->randomElement(self::VERBATIMS).(mt_rand(0, 1) ? ' '.$this->faker->sentence(mt_rand(6, 14)) : '')
            : $this->faker->randomElement(['Les études de cas', 'La qualité des échanges', 'Les supports remis', 'L\'animation', 'Projet Hélios', 'Atelier Boussole', 'Plateforme Cap Avenir']);

        // Respect des longueurs configurées
        while (null !== $config->minLength && mb_strlen($text) < $config->minLength) {
            $text .= ' '.$this->faker->sentence(12);
        }

        return mb_substr($text, 0, $config->effectiveMaxLength());
    }

    private function number(NumberConfig $config): int|float
    {
        $min = $config->min ?? 0.0;
        $max = $config->max ?? $min + 100;
        $step = $config->step ?? ($config->allowDecimal ? 0.5 : 1.0);

        // Distribution en cloche vers le haut de l'échelle (notes, satisfaction)
        $ratio = min(1.0, max(0.0, (mt_rand(0, 1000) + mt_rand(0, 1000) + mt_rand(300, 1000)) / 3000));
        $value = $min + round(($max - $min) * $ratio / $step) * $step;
        $value = min($max, max($min, $value));

        return $config->allowDecimal ? round($value, 2) : (int) round($value);
    }

    /**
     * @return list<string>
     */
    private function choices(ChoiceConfig $config): array
    {
        $ids = $config->optionIds();

        if (!$config->isMultiple()) {
            // Pondération décroissante : les premières options sont plus souvent choisies
            $weights = array_map(static fn (int $i): int => max(1, \count($ids) * 2 - $i * 2), array_keys($ids));

            return [$ids[self::weighted($weights)]];
        }

        $picked = $this->faker->randomElements($ids, mt_rand(1, min(3, \count($ids))));

        return array_values(array_intersect($ids, $picked));
    }

    private function date(DateConfig $config): string
    {
        $min = new \DateTimeImmutable($config->minDate ?? '2022-01-01');
        $max = new \DateTimeImmutable($config->maxDate ?? $this->now->format('Y-m-d'));

        return $this->between($min, $max)->format('Y-m-d');
    }

    private function fileAnswer(ObjectManager $manager, SurveyRecipient $recipient, Question $question, \DateTimeImmutable $at): void
    {
        $config = FileConfig::fromArray($question->getConfig());
        $count = mt_rand(1, $config->maxFiles);
        $assets = [];

        for ($i = 0; $i < $count; ++$i) {
            $mimeType = $config->allowedMimeTypes[array_rand($config->allowedMimeTypes)];
            [$extension, $content] = self::sampleFile($mimeType);
            $base = $this->faker->randomElement(['business-plan', 'previsionnel-2027', 'kbis', 'presentation-equipe', 'budget-detaille', 'lettre-de-motivation', 'photo-prototype']);

            $storedPath = \sprintf('surveys/%s/%s/%s.%s', $recipient->getSurvey()->getId()->toRfc4122(), $question->getId()->toRfc4122(), bin2hex(random_bytes(16)), $extension);
            file_put_contents($this->storage->writablePath($storedPath), $content);

            $assets[] = new UploadedAsset($recipient, $question, $base.'.'.$extension, $storedPath, $mimeType, $extension, \strlen($content), $at->modify('-2 minutes'));
        }

        $payload = ['assetIds' => array_map(static fn (UploadedAsset $asset): string => $asset->getId()->toRfc4122(), $assets)];
        $answer = new SurveyAnswer($recipient, $question, $payload, null, $at);
        $manager->persist($answer);

        foreach ($assets as $asset) {
            $manager->persist($asset->attachTo($answer));
        }
    }

    /**
     * Fichier d'exemple valide pour son type MIME (détection réelle du contenu par finfo).
     *
     * @return array{string, string} extension, contenu
     */
    private static function sampleFile(string $mimeType): array
    {
        return match ($mimeType) {
            'image/png' => ['png', self::image('png')],
            'image/jpeg' => ['jpg', self::image('jpeg')],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', self::xlsx()],
            default => ['pdf', self::pdf()],
        };
    }

    private static function image(string $format): string
    {
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, mt_rand(60, 200), mt_rand(60, 200), mt_rand(120, 230)));
        imagestring($image, 5, 110, 90, 'kwesto', imagecolorallocate($image, 255, 255, 255));

        ob_start();
        'png' === $format ? imagepng($image) : imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    private static function pdf(): string
    {
        $stream = "BT /F1 18 Tf 72 720 Td (Document de demonstration kwesto) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }

        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }

    /**
     * Classeur XLSX minimal (archive OOXML) écrit avec ZipStream : l'extension PHP zip n'est pas requise.
     */
    private static function xlsx(): string
    {
        $output = fopen('php://memory', 'w+b');
        $zip = new class($output) {
            private ZipStream $zip;

            /** @param resource $output */
            public function __construct($output)
            {
                $this->zip = new ZipStream(outputStream: $output, sendHttpHeaders: false, defaultEnableZeroHeader: false);
            }

            public function addFromString(string $name, string $content): void
            {
                $this->zip->addFile(fileName: $name, data: $content);
            }

            public function close(): void
            {
                $this->zip->finish();
            }
        };
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Poste</t></is></c><c r="B1" t="inlineStr"><is><t>Montant</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Prototype</t></is></c><c r="B2"><v>12000</v></c></row></sheetData></worksheet>');
        $zip->close();

        rewind($output);
        $content = (string) stream_get_contents($output);
        fclose($output);

        return $content;
    }

    private function buildSurvey(User $owner, string $blueprint, bool $complete = true): Survey
    {
        [$title, $description, $sections] = SurveyBlueprints::all()[$blueprint];
        $survey = (new Survey($owner))->setTitle($title)->setDescription($description);
        // Démonstration du mode « question par question » : dossier de candidature et événement
        if (\in_array($blueprint, ['dossier', 'evenement'], true)) {
            $survey->setDisplayMode(SurveyDisplayMode::OneByOne);
        }

        // Brouillon en cours de conception : dernière section pas encore rédigée
        if (!$complete && \count($sections) > 1) {
            array_pop($sections);
        }

        $useSections = \count($sections) >= 2;
        $position = 0;

        foreach ($sections as $sectionIndex => $blueprintSection) {
            [$sectionTitle, $sectionDescription, $questions] = $blueprintSection;
            $section = null;
            if ($useSections) {
                $section = (new SurveySection())->setTitle($sectionTitle)->setDescription($sectionDescription)->setPosition($sectionIndex + 1);
                $survey->addSection($section);
                // Section conditionnelle : la source appartient à une section précédente, déjà créée
                if (isset($blueprintSection[3])) {
                    $section->setCondition($this->condition($survey, $blueprintSection[3]));
                }
            }

            foreach ($questions as $blueprintQuestion) {
                [$type, $label, $required, $config, $help] = $blueprintQuestion;
                $questionType = QuestionType::from($type);
                $question = (new Question())
                    ->setType($questionType)
                    ->setLabel($label)
                    ->setRequired($required)
                    ->setHelpTitle($help[0] ?? null)
                    ->setHelpText($help[1] ?? null)
                    ->setPosition(++$position)
                    ->setConfig($this->questionTypes->get($questionType)->normalizeConfig($config))
                    ->setSection($section);
                $survey->addQuestion($question);

                if (isset($blueprintQuestion[5])) {
                    $question->setCondition($this->condition($survey, $blueprintQuestion[5]));
                }
            }
        }

        return $survey;
    }

    /**
     * Condition du modèle [intitulé de la source, opérateur, valeur] ; pour un CHOICE, les libellés
     * d'options sont traduits en identifiants.
     *
     * @param array{string, string, mixed} $when
     */
    private function condition(Survey $survey, array $when): DisplayCondition
    {
        [$sourceLabel, $operator, $value] = $when;

        foreach ($survey->getQuestions() as $source) {
            if ($source->getLabel() !== $sourceLabel) {
                continue;
            }

            if (QuestionType::Choice === $source->getType()) {
                $config = ChoiceConfig::fromArray($source->getConfig());
                $idOf = static function (string $label) use ($config): string {
                    foreach ($config->options as $option) {
                        if ($option['label'] === $label) {
                            return $option['id'];
                        }
                    }
                    throw new \LogicException(\sprintf('Option « %s » introuvable.', $label));
                };
                $value = \is_array($value) ? array_map($idOf, $value) : $idOf($value);
            }

            return new DisplayCondition($source->getId()->toRfc4122(), ConditionOperator::from($operator), $value);
        }

        throw new \LogicException(\sprintf('Question source « %s » introuvable.', $sourceLabel));
    }

    private function email(): string
    {
        do {
            $email = mb_strtolower(\sprintf(
                '%s.%s%s@%s',
                self::ascii($this->faker->firstName()),
                self::ascii($this->faker->lastName()),
                mt_rand(0, 3) ? '' : (string) mt_rand(1, 99),
                $this->faker->randomElement(['example.com', 'example.org', 'example.net']),
            ));
        } while (isset($this->usedEmails[$email]));

        $this->usedEmails[$email] = true;

        return $email;
    }

    /**
     * @return array{string, string, string, DeviceType}
     */
    private function userAgent(): array
    {
        $entry = self::USER_AGENTS[self::weighted(array_column(self::USER_AGENTS, 4))];

        return [$entry[0], $entry[1], $entry[2], $entry[3]];
    }

    /**
     * @param list<int> $weights
     */
    private static function weighted(array $weights): int
    {
        $pick = mt_rand(1, array_sum($weights));
        foreach ($weights as $index => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $index;
            }
        }

        return 0;
    }

    private function between(\DateTimeImmutable $from, \DateTimeImmutable $to): \DateTimeImmutable
    {
        if ($to <= $from) {
            return $from;
        }

        return $from->setTimestamp(mt_rand($from->getTimestamp(), $to->getTimestamp()));
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->now->modify($modifier);
    }

    private static function ascii(string $value): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)));
    }
}
