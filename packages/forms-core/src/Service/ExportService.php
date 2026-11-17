<?php

declare(strict_types=1);

namespace FormsCore\Service;

use FormsCore\Entity\Survey;
use FormsCore\QuestionType\QuestionTypeRegistry;
use FormsCore\Repository\SurveyAnswerRepository;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Util\DateFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Export des réponses brutes (specs_fonc §7.2) : une ligne par destinataire, avec la télémétrie.
 *
 * Format CSV compatible Excel : UTF-8 avec BOM, séparateur « ; », dates au format français.
 */
final class ExportService
{
    private const SEPARATOR = ';';

    public function __construct(
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly SurveyAnswerRepository $answerRepository,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly ClockInterface $clock,
    ) {
    }

    public function csv(Survey $survey): StreamedResponse
    {
        $questions = $survey->getOrderedQuestions();

        $response = new StreamedResponse(function () use ($survey, $questions): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");

            $header = ['Adresse e-mail', 'Statut', 'Ouvert le', 'Soumis le', 'Durée (s)', 'Navigateur', 'Version', 'Système', 'Appareil', 'Adresse IP', 'User-Agent'];
            foreach ($questions as $question) {
                $header[] = \sprintf('Q%d. %s', $question->getPosition(), $question->getPlainLabel());
            }
            fputcsv($output, $header, self::SEPARATOR, escape: '');

            $answers = $this->answerRepository->payloadsByRecipient($survey);

            foreach ($this->recipientRepository->findBySurvey($survey) as $recipient) {
                $row = [
                    $recipient->getEmail(),
                    $recipient->getStatus()->label(),
                    null !== $recipient->getOpenedAt() ? DateFormatter::short($recipient->getOpenedAt()) : '',
                    null !== $recipient->getSubmittedAt() ? DateFormatter::short($recipient->getSubmittedAt()) : '',
                    (string) ($recipient->getCompletionSeconds() ?? ''),
                    (string) $recipient->getBrowserName(),
                    (string) $recipient->getBrowserVersion(),
                    (string) $recipient->getOsName(),
                    (string) $recipient->getDeviceType()?->value,
                    (string) $recipient->getIpAddress(),
                    (string) $recipient->getUserAgentRaw(),
                ];

                $recipientAnswers = $answers[$recipient->getId()->toRfc4122()] ?? [];
                foreach ($questions as $question) {
                    $payload = $recipientAnswers[$question->getId()->toRfc4122()] ?? null;
                    $row[] = null === $payload ? '' : $this->questionTypes->get($question->getType())->exportValue($question, $payload);
                }

                fputcsv($output, array_map(self::neutralizeFormula(...), $row), self::SEPARATOR, escape: '');
            }

            fclose($output);
        });

        $slug = (string) (new AsciiSlugger('fr'))->slug($survey->getTitle())->lower();
        $fileName = \sprintf('kwesto-%s-reponses-%s.csv', mb_substr($slug, 0, 60), $this->clock->now()->format('Ymd'));

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName));

        return $response;
    }

    /**
     * Injection de formule dans un tableur : une cellule commençant par = + - @ est préfixée d'une apostrophe.
     */
    private static function neutralizeFormula(string $value): string
    {
        if ('' === $value || !\in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        // Un nombre négatif (réponse NUMBER, ex. « -12,5 ») reste un nombre
        return 1 === preg_match('/^-\d+(,\d+)?$/', $value) ? $value : "'".$value;
    }
}
