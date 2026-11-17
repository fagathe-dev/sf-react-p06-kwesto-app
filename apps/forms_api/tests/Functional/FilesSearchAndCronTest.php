<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Scheduler\CronRunner;
use App\Scheduler\Message\CronTask;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FilesSearchAndCronTest extends ApiTestCase
{
    public function testFileUploadChecksRealMimeTypeAndAttachesOnSubmit(): void
    {
        $this->loginAs($this->createUser());
        [$surveyId, $questionId, $token] = $this->launchedSurvey([
            ['type' => 'FILE', 'label' => 'Justificatif', 'config' => ['allowedMimeTypes' => ['image/png'], 'maxSizeMb' => 1, 'maxFiles' => 1]],
        ]);
        $this->logout();

        // Un fichier texte renommé en .png est refusé : seul le contenu compte
        $fake = $this->tempFile('pas une image', 'faux.png');
        $this->client->request('POST', "/api/public/surveys/$token/questions/$questionId/files", files: ['file' => $fake]);
        self::assertResponseStatusCodeSame(400);

        $png = $this->tempFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'photo.png');
        $this->client->request('POST', "/api/public/surveys/$token/questions/$questionId/files", files: ['file' => $png]);
        self::assertResponseStatusCodeSame(201);
        $asset = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('image/png', $asset['mimeType']);

        $this->api('POST', "/api/public/surveys/$token/submit", ['answers' => [$questionId => [$asset['id']]]]);
        self::assertResponseStatusCodeSame(201);

        // Le créateur télécharge le fichier et l'archive .zip
        $this->loginAs($this->ownerOf($surveyId));
        $files = $this->api('GET', "/api/admin/surveys/$surveyId/stats/questions/$questionId/files");
        self::assertSame('photo.png', $files['items'][0]['originalName']);

        $this->api('GET', "/api/admin/surveys/$surveyId/archive");
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
    }

    public function testSearchFindsSurveysAndVerbatims(): void
    {
        $this->loginAs($this->createUser());
        [, $questionId, $token] = $this->launchedSurvey([
            ['type' => 'TEXT', 'label' => 'Votre suggestion', 'config' => ['multiline' => true]],
        ], 'Questionnaire ergonomie bureaux');
        $creatorToken = $this->currentUserEmail;

        $this->logout();
        $this->api('POST', "/api/public/surveys/$token/submit", ['answers' => [$questionId => 'Des fauteuils ergonomiques pour toute l\'équipe']]);

        $this->loginAs($this->ownerByEmail($creatorToken));
        $results = $this->api('GET', '/api/admin/search?q=ergonomie');
        self::assertResponseIsSuccessful();
        self::assertSame('Questionnaire ergonomie bureaux', $results['surveys'][0]['title']);

        $results = $this->api('GET', '/api/admin/search?q=fauteuils');
        self::assertStringContainsString('fauteuils', $results['verbatims'][0]['text']);
    }

    public function testCronClosesExpiredSurveysAndSendsReminders(): void
    {
        $this->loginAs($this->createUser());
        [$expiredId] = $this->launchedSurvey([['type' => 'TEXT', 'label' => 'Question']], 'Expiré');
        [$reminderId] = $this->launchedSurvey([['type' => 'TEXT', 'label' => 'Question']], 'À relancer');

        $container = static::getContainer();
        $surveys = $container->get(SurveyRepository::class);

        $expired = $surveys->findOneById($expiredId);
        $expired->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        // Fenêtre J-5 / J-4, lancé depuis plus de 48 h, 0 % de réponses
        $reminder = $surveys->findOneById($reminderId);
        $reminder->setExpiresAt(new \DateTimeImmutable('+4 days 12 hours'))->setActivatedAt(new \DateTimeImmutable('-3 days'));
        $surveys->flush();

        $runner = $container->get(CronRunner::class);
        self::assertGreaterThanOrEqual(1, $runner->run(CronTask::CloseExpired)['closed']);
        self::assertGreaterThanOrEqual(1, $runner->run(CronTask::LowResponseReminders)['surveys']);

        $this->api('GET', "/api/admin/surveys/$expiredId");
        $closed = $this->api('GET', "/api/admin/surveys/$expiredId");
        self::assertSame('CLOSED', $closed['survey']['status']);
        self::assertSame('DEADLINE_REACHED', $closed['survey']['closeReason']);

        $reminded = static::getContainer()->get(SurveyRepository::class)->findOneById($reminderId);
        self::assertNotNull($reminded->getReminderSentAt());
        self::assertSame(1, $reminded->getRemindersCount());

        // Une seule relance par campagne : le sondage n'est plus candidat
        self::assertSame(0, static::getContainer()->get(CronRunner::class)->run(CronTask::LowResponseReminders)['surveys']);
    }

    private ?string $currentUserEmail = null;

    /**
     * Crée, valide et lance un questionnaire à un destinataire.
     *
     * @param list<array<string, mixed>> $questions
     *
     * @return array{string, string, string} identifiant du questionnaire, de la première question, jeton
     */
    private function launchedSurvey(array $questions, string $title = 'Questionnaire de test'): array
    {
        $me = $this->api('GET', '/api/auth/me');
        $this->currentUserEmail = $me['email'];

        $survey = $this->api('POST', '/api/admin/surveys', ['title' => $title])['survey'];
        $saved = $this->api('PUT', "/api/admin/surveys/{$survey['id']}/structure", ['questions' => $questions]);
        $this->api('POST', "/api/admin/surveys/{$survey['id']}/validate");
        self::assertResponseIsSuccessful();
        $this->api('POST', "/api/admin/surveys/{$survey['id']}/recipients", ['emails' => 'participant-'.bin2hex(random_bytes(4)).'@example.com']);
        $this->api('POST', "/api/admin/surveys/{$survey['id']}/launch", ['expiresAt' => (new \DateTimeImmutable('+10 days'))->format(\DATE_ATOM)]);
        self::assertResponseIsSuccessful();

        $entity = static::getContainer()->get(SurveyRepository::class)->findOneById($survey['id']);
        $recipient = static::getContainer()->get(SurveyRecipientRepository::class)->findBySurvey($entity)[0];
        self::assertSame(RecipientStatus::Sent, $recipient->getStatus());

        return [$survey['id'], $saved['survey']['questions'][0]['id'], (string) $recipient->getToken()];
    }

    private function ownerOf(string $surveyId): \FormsCore\Entity\User
    {
        return static::getContainer()->get(SurveyRepository::class)->findOneById($surveyId)->getOwner();
    }

    private function ownerByEmail(?string $email): \FormsCore\Entity\User
    {
        return static::getContainer()->get(\FormsCore\Repository\UserRepository::class)->findOneByEmail((string) $email);
    }

    private function tempFile(string $content, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kw');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
