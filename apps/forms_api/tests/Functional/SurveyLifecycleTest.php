<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Repository\SurveyRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours complet d'une campagne : conception → validation → diffusion → réponses → clôture → statistiques.
 */
final class SurveyLifecycleTest extends ApiTestCase
{
    public function testFullCampaignLifecycle(): void
    {
        $creator = $this->createUser();
        $this->loginAs($creator);

        // --- Module 1 : brouillon et structure (2 sections → stepper)
        $created = $this->api('POST', '/api/admin/surveys', ['title' => 'Enquête de test', 'description' => 'Consignes.']);
        self::assertResponseStatusCodeSame(201);
        $surveyId = $created['survey']['id'];
        self::assertSame('DRAFT', $created['survey']['status']);

        $s1 = Uuid::v7()->toRfc4122();
        $s2 = Uuid::v7()->toRfc4122();
        $structure = $this->api('PUT', "/api/admin/surveys/$surveyId/structure", [
            'sections' => [['id' => $s1, 'title' => 'Première partie'], ['id' => $s2, 'title' => 'Seconde partie']],
            'questions' => [
                ['id' => Uuid::v7()->toRfc4122(), 'sectionId' => $s2, 'type' => 'TEXT', 'label' => 'Un commentaire ?', 'required' => false, 'config' => ['multiline' => true, 'maxLength' => 500]],
                ['id' => Uuid::v7()->toRfc4122(), 'sectionId' => $s1, 'type' => 'NUMBER', 'label' => 'Votre note', 'helpTitle' => 'À savoir', 'helpText' => 'De 0 à 10.', 'config' => ['allowDecimal' => true, 'min' => 0, 'max' => 10]],
                ['id' => Uuid::v7()->toRfc4122(), 'sectionId' => $s1, 'type' => 'CHOICE', 'label' => 'Votre préférence', 'config' => ['widget' => 'checkbox', 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]]],
                ['id' => Uuid::v7()->toRfc4122(), 'sectionId' => $s2, 'type' => 'DATE', 'label' => 'Une date', 'config' => ['minDate' => '2026-01-01', 'maxDate' => '2026-12-31']],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($structure['survey']['stepper']);
        self::assertSame('LIST', $structure['survey']['displayMode']);

        // Mode d'affichage : métadonnée hors structure, valeur contrôlée
        $this->api('PATCH', "/api/admin/surveys/$surveyId", ['displayMode' => 'CAROUSEL']);
        self::assertResponseStatusCodeSame(400);
        $updated = $this->api('PATCH', "/api/admin/surveys/$surveyId", ['displayMode' => 'ONE_BY_ONE']);
        self::assertResponseIsSuccessful();
        self::assertSame('ONE_BY_ONE', $updated['survey']['displayMode']);

        // Numérotation continue : section 1 d'abord, puis section 2, dans l'ordre reçu
        $questions = $structure['survey']['questions'];
        usort($questions, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        self::assertSame(['Votre note', 'Votre préférence', 'Un commentaire ?', 'Une date'], array_column($questions, 'label'));
        self::assertSame([1, 2, 3, 4], array_column($questions, 'position'));
        [$qNumber, $qChoice, $qText, $qDate] = array_column($questions, 'id');

        // --- Validation : verrouillage de la structure
        $this->api('POST', "/api/admin/surveys/$surveyId/validate");
        self::assertResponseIsSuccessful();
        $this->api('PUT', "/api/admin/surveys/$surveyId/structure", ['sections' => [], 'questions' => []]);
        self::assertResponseStatusCodeSame(409);

        // --- Module 2 : liste de diffusion nettoyée et dédoublonnée
        $added = $this->api('POST', "/api/admin/surveys/$surveyId/recipients", ['emails' => "  Alice@Example.com\nbob@example.com; alice@example.com, pas-une-adresse@\ncarole@example.com"]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(3, $added['added']);
        self::assertSame(1, $added['duplicates']);
        self::assertSame(['pas-une-adresse@'], $added['invalid']);

        $this->api('POST', "/api/admin/surveys/$surveyId/launch", ['expiresAt' => (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM)]);
        self::assertResponseStatusCodeSame(400);

        $launched = $this->api('POST', "/api/admin/surveys/$surveyId/launch", ['expiresAt' => (new \DateTimeImmutable('+7 days'))->format(\DATE_ATOM)]);
        self::assertResponseIsSuccessful();
        self::assertSame('ACTIVE', $launched['survey']['status']);
        // Transport Messenger synchrone en test : les invitations sont parties
        self::assertSame(3, $launched['recipients']['sent'], json_encode($launched['recipients']));
        self::assertEmailCount(3);

        $tokens = $this->tokens($surveyId);
        self::assertCount(3, $tokens);
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        }

        // --- Module 3 : ouverture et soumission (participant anonyme)
        $this->logout();
        $this->api('GET', '/api/public/surveys/'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(404);

        $opened = $this->api('GET', '/api/public/surveys/'.$tokens[0]);
        self::assertSame('OPEN', $opened['state']);
        self::assertSame('Enquête de test', $opened['survey']['title']);
        self::assertSame('ONE_BY_ONE', $opened['survey']['displayMode']);
        self::assertTrue($opened['survey']['stepper']);
        self::assertNull($opened['draft']);

        // --- Brouillon : enregistré sans validation, renvoyé à la réouverture, effacé si l'on recommence
        $saved = $this->api('PUT', "/api/public/surveys/{$tokens[0]}/draft", ['answers' => [$qNumber => '7,', 'inconnue' => 'x', $qChoice => ['a']], 'questionId' => $qNumber]);
        self::assertResponseIsSuccessful();
        self::assertNotNull($saved['savedAt']);
        $reopened = $this->api('GET', '/api/public/surveys/'.$tokens[0]);
        self::assertSame([$qNumber => '7,', $qChoice => ['a']], $reopened['draft']['answers']);
        self::assertSame($qNumber, $reopened['draft']['questionId']);
        $this->api('DELETE', "/api/public/surveys/{$tokens[0]}/draft");
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->api('GET', '/api/public/surveys/'.$tokens[0])['draft']);
        $this->api('PUT', "/api/public/surveys/{$tokens[0]}/draft", ['answers' => [$qText => 'En cours…']]);
        self::assertResponseIsSuccessful();

        // Erreurs de validation : obligatoire manquant, virgule décimale acceptée mais hors intervalle
        $errors = $this->api('POST', "/api/public/surveys/{$tokens[0]}/submit", ['answers' => [$qNumber => '12,5', $qChoice => ['z']]]);
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey($qNumber, $errors['violations']);
        self::assertArrayHasKey($qChoice, $errors['violations']);
        self::assertArrayHasKey($qDate, $errors['violations']);

        $answers = [$qNumber => '7,5', $qChoice => ['b', 'a'], $qText => '  Très bon questionnaire  ', $qDate => '2026-06-15'];
        $this->api('POST', "/api/public/surveys/{$tokens[0]}/submit", ['answers' => $answers]);
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1); // accusé de réception

        // Anti-doublon : rejeu de la même soumission, puis réouverture du lien
        $replay = $this->api('POST', "/api/public/surveys/{$tokens[0]}/submit", ['answers' => $answers]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ANSWERED', $replay['state']);
        self::assertStringContainsString('Une seule participation est autorisée', $replay['message']);

        // Après l'envoi, plus de brouillon possible (le précédent a été effacé à la soumission)
        $this->api('PUT', "/api/public/surveys/{$tokens[0]}/draft", ['answers' => [$qText => 'trop tard']]);
        self::assertResponseStatusCodeSame(403);

        // --- Clôture ALL_ANSWERED après la dernière réponse attendue
        foreach (\array_slice($tokens, 1) as $token) {
            $this->api('POST', "/api/public/surveys/$token/submit", ['answers' => [$qNumber => 3, $qChoice => ['a'], $qDate => '2026-03-01']]);
            self::assertResponseStatusCodeSame(201);
        }

        $closedState = $this->api('GET', '/api/public/surveys/'.$tokens[1]);
        self::assertResponseStatusCodeSame(410);
        self::assertSame('CLOSED', $closedState['state']);

        // --- Module 4 : statistiques consolidées
        $this->loginAs($creator);
        $stats = $this->api('GET', "/api/admin/surveys/$surveyId/stats");
        self::assertResponseIsSuccessful();
        self::assertSame('CLOSED', $stats['survey']['status']);
        self::assertSame('ALL_ANSWERED', $stats['survey']['closeReason']);
        self::assertTrue($stats['stats']['frozen']);
        self::assertSame(100.0, (float) $stats['stats']['global']['participationRate']);

        $byId = array_column($stats['stats']['questions'], 'stats', 'id');
        self::assertSame(3, $byId[$qNumber]['count']);
        self::assertEqualsWithDelta(4.5, $byId[$qNumber]['mean'], 0.01); // (7.5 + 3 + 3) / 3
        self::assertSame(3.0, (float) $byId[$qNumber]['median']);
        $options = array_column($byId[$qChoice]['options'], 'count', 'id');
        self::assertSame(['a' => 3, 'b' => 1], $options);
        self::assertSame('2026-03-01', $byId[$qDate]['earliest']);

        // Verbatims recherchables (index FULLTEXT de search_text)
        $verbatims = $this->api('GET', "/api/admin/surveys/$surveyId/stats/questions/$qText/verbatims?q=questionnaire");
        self::assertSame('Très bon questionnaire', $verbatims['items'][0]['text']);

        // Export CSV : une ligne d'en-tête + une ligne par destinataire
        $this->client->request('GET', "/api/admin/surveys/$surveyId/export", server: ['HTTP_AUTHORIZATION' => $this->client->getRequest()->headers->get('Authorization')]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testValidationRequiresAtLeastOneQuestionAndTwoOptions(): void
    {
        $this->loginAs($this->createUser());
        $survey = $this->api('POST', '/api/admin/surveys', ['title' => 'Incomplet'])['survey'];

        $result = $this->api('POST', "/api/admin/surveys/{$survey['id']}/validate");
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins une question', $result['errors'][0]['message']);

        $this->api('PUT', "/api/admin/surveys/{$survey['id']}/structure", [
            'questions' => [['type' => 'CHOICE', 'label' => 'Un seul choix', 'config' => ['widget' => 'radio', 'options' => [['id' => 'a', 'label' => 'A']]]]],
        ]);
        $result = $this->api('POST', "/api/admin/surveys/{$survey['id']}/validate");
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins 2 options', $result['errors'][0]['message']);
    }

    public function testConditionalSectionIsSavedAndDuplicated(): void
    {
        $this->loginAs($this->createUser());
        $survey = $this->api('POST', '/api/admin/surveys', ['title' => 'Avec coordonnées'])['survey'];
        $s1 = '0193a1b2-0000-7000-8000-000000000001';
        $s2 = '0193a1b2-0000-7000-8000-000000000002';
        $callback = '0193a1b2-0000-7000-8000-000000000003';
        $when = ['sourceQuestionId' => $callback, 'operator' => 'EQUALS', 'value' => true];

        // Source dans la section elle-même : refusé
        $this->api('PUT', "/api/admin/surveys/{$survey['id']}/structure", [
            'sections' => [['id' => $s1, 'title' => 'Avis', 'displayCondition' => $when]],
            'questions' => [['id' => $callback, 'sectionId' => $s1, 'type' => 'BOOLEAN', 'label' => 'Recontacter ?', 'config' => ['preset' => 'YES_NO']]],
        ]);
        self::assertResponseStatusCodeSame(422);

        $saved = $this->api('PUT', "/api/admin/surveys/{$survey['id']}/structure", [
            'sections' => [['id' => $s1, 'title' => 'Avis'], ['id' => $s2, 'title' => 'Coordonnées', 'displayCondition' => $when]],
            'questions' => [
                ['id' => $callback, 'sectionId' => $s1, 'type' => 'BOOLEAN', 'label' => 'Recontacter ?', 'config' => ['preset' => 'YES_NO']],
                ['sectionId' => $s2, 'type' => 'TEXT', 'label' => 'Nom', 'config' => ['multiline' => false]],
            ],
        ])['survey'];
        self::assertResponseIsSuccessful();
        self::assertSame($when, $saved['sections'][1]['displayCondition']);

        // Copie : nouveau brouillon, condition rattachée à la copie de la question source
        $copy = $this->api('POST', "/api/admin/surveys/{$survey['id']}/duplicate")['survey'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame('DRAFT', $copy['status']);
        self::assertNotSame($survey['id'], $copy['id']);
        $copiedSource = $copy['questions'][0]['id'];
        self::assertNotSame($callback, $copiedSource);
        self::assertSame($copiedSource, $copy['sections'][1]['displayCondition']['sourceQuestionId']);
    }

    public function testTitleLengthIsEnforced(): void
    {
        $this->loginAs($this->createUser());
        $result = $this->api('POST', '/api/admin/surveys', ['title' => 'ab']);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('title', $result['violations']);
    }

    public function testFileQuestionSizeIsCappedByPlatformLimit(): void
    {
        $this->loginAs($this->createUser());
        $survey = $this->api('POST', '/api/admin/surveys', ['title' => 'Avec fichier'])['survey'];

        $saved = $this->api('PUT', "/api/admin/surveys/{$survey['id']}/structure", [
            'questions' => [['type' => 'FILE', 'label' => 'Justificatif', 'config' => ['allowedMimeTypes' => ['application/pdf'], 'maxSizeMb' => 500, 'maxFiles' => 2]]],
        ]);

        $config = static::getContainer()->get('FormsCore\Service\PlatformConfig');
        self::assertSame($config->uploadMaxSizeMb(), $saved['survey']['questions'][0]['config']['maxSizeMb']);
    }

    public function testCreatorCannotSeeAnotherCreatorsSurvey(): void
    {
        $this->loginAs($this->createUser());
        $survey = $this->api('POST', '/api/admin/surveys', ['title' => 'Privé'])['survey'];

        $this->loginAs($this->createUser());
        $this->api('GET', "/api/admin/surveys/{$survey['id']}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdministrationIsRestrictedToAdmins(): void
    {
        $this->loginAs($this->createUser());
        $this->api('GET', '/api/admin/platform/emails');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser(admin: true));
        $previews = $this->api('GET', '/api/admin/platform/emails');
        self::assertResponseIsSuccessful();
        self::assertContains('SURVEY_INVITATION', array_column($previews, 'code'));

        $rendered = $this->api('GET', '/api/admin/platform/emails/survey-invitation');
        self::assertStringContainsString('Répondre au questionnaire', $rendered['html']);

        $this->api('GET', '/api/admin/platform/logs?channel=app');
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousAccessIsRejectedOutsidePublicRoutes(): void
    {
        $this->api('GET', '/api/admin/surveys');
        self::assertResponseStatusCodeSame(401);

        $config = $this->api('GET', '/api/public/config');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('uploadMaxSizeMb', $config);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $surveyId): array
    {
        $container = static::getContainer();
        $survey = $container->get(SurveyRepository::class)->findOneById($surveyId);
        $tokens = [];

        foreach ($container->get(SurveyRecipientRepository::class)->findBySurvey($survey) as $recipient) {
            $tokens[] = (string) $recipient->getToken();
        }

        return $tokens;
    }
}
