<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use FormsCore\DTO\DisplayCondition;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveySection;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Entity\User;
use FormsCore\Enum\ConditionOperator;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\QuestionType\BooleanHandler;
use FormsCore\QuestionType\ChoiceHandler;
use FormsCore\QuestionType\DateHandler;
use FormsCore\QuestionType\NumberHandler;
use FormsCore\QuestionType\RangeHandler;
use FormsCore\QuestionType\TextHandler;
use FormsCore\Service\ConditionEvaluatorService;
use FormsCore\Util\EmailListParser;
use FormsCore\Util\MarkdownText;
use FormsCore\Util\Statistics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles de validation des types de questions (specs_fonc §4).
 */
final class QuestionTypeHandlersTest extends TestCase
{
    private SurveyRecipient $recipient;

    protected function setUp(): void
    {
        $this->recipient = new SurveyRecipient(new Survey(new User()), 'participant@example.com');
    }

    public function testTextIsTrimmedAndLengthChecked(): void
    {
        $question = $this->question(['multiline' => false, 'minLength' => 3, 'maxLength' => 10]);
        $handler = new TextHandler();

        self::assertSame(['value' => 'ab cd'], $handler->validateAnswer($question, "  ab \n cd  ", $this->recipient)->toArray());

        $this->expectException(InvalidAnswerException::class);
        $handler->validateAnswer($question, 'Une réponse bien trop longue', $this->recipient);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, int|float|null}>
     */
    public static function numbers(): iterable
    {
        yield 'virgule décimale' => [['allowDecimal' => true], '12,5', 12.5];
        yield 'point décimal' => [['allowDecimal' => true], '12.5', 12.5];
        yield 'entier' => [['allowDecimal' => false], '42', 42];
        yield 'décimale refusée' => [['allowDecimal' => false], '4,2', null];
        yield 'pas un nombre' => [['allowDecimal' => true], '12abc', null];
        yield 'borne min incluse' => [['min' => 0, 'max' => 10], '0', 0];
        yield 'borne max incluse' => [['min' => 0, 'max' => 10], '10', 10];
        yield 'hors intervalle' => [['min' => 0, 'max' => 10], '11', null];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('numbers')]
    public function testNumberValidation(array $config, mixed $raw, int|float|null $expected): void
    {
        $handler = new NumberHandler();

        if (null === $expected) {
            $this->expectException(InvalidAnswerException::class);
        }

        self::assertSame(['value' => $expected], $handler->validateAnswer($this->question($config), $raw, $this->recipient)->toArray());
    }

    public function testChoiceRejectsUnknownOptionAndMultipleForRadio(): void
    {
        $handler = new ChoiceHandler();
        $options = [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']];

        $checkbox = $this->question(['widget' => 'checkbox', 'options' => $options]);
        self::assertSame(['optionIds' => ['a', 'b']], $handler->validateAnswer($checkbox, ['b', 'a', 'a'], $this->recipient)->toArray());

        $radio = $this->question(['widget' => 'radio', 'options' => $options]);
        try {
            $handler->validateAnswer($radio, ['a', 'b'], $this->recipient);
            self::fail('Un bouton radio n\'accepte qu\'une option.');
        } catch (InvalidAnswerException) {
        }

        $this->expectException(InvalidAnswerException::class);
        $handler->validateAnswer($checkbox, ['c'], $this->recipient);
    }

    public function testDateRangeAndCalendarValidity(): void
    {
        $handler = new DateHandler();
        $question = $this->question(['minDate' => '2026-01-01', 'maxDate' => '2026-12-31']);

        self::assertSame(['value' => '2026-02-28'], $handler->validateAnswer($question, '2026-02-28', $this->recipient)->toArray());

        foreach (['2026-02-30', '2025-12-31', '13/11/2026'] as $invalid) {
            try {
                $handler->validateAnswer($question, $invalid, $this->recipient);
                self::fail(\sprintf('« %s » aurait dû être refusée.', $invalid));
            } catch (InvalidAnswerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBooleanConfigPresetsDefaultsAndSwitch(): void
    {
        $handler = new BooleanHandler();

        // Preset par défaut et libellés complétés
        self::assertSame(['preset' => 'YES_NO', 'trueLabel' => 'Oui', 'falseLabel' => 'Non', 'widget' => 'buttons', 'defaultValue' => null], $handler->normalizeConfig([]));
        self::assertSame(['Vrai', 'Faux'], array_values(array_intersect_key($handler->normalizeConfig(['preset' => 'TRUE_FALSE']), ['trueLabel' => 1, 'falseLabel' => 1])));

        // Libellés modifiés → CUSTOM ; valeur par défaut conservée
        $custom = $handler->normalizeConfig(['preset' => 'YES_NO', 'trueLabel' => 'Intéressé', 'falseLabel' => 'Pas intéressé', 'defaultValue' => 'false']);
        self::assertSame('CUSTOM', $custom['preset']);
        self::assertFalse($custom['defaultValue']);

        // Un interrupteur a toujours une valeur par défaut (non)
        self::assertFalse($handler->normalizeConfig(['widget' => 'switch'])['defaultValue']);
        self::assertTrue($handler->normalizeConfig(['widget' => 'switch', 'defaultValue' => true])['defaultValue']);

        self::assertSame(['Les deux libellés doivent être différents.'], $handler->validateConfig(['trueLabel' => 'Oui', 'falseLabel' => 'oui']));
    }

    public function testBooleanAnswerFalseIsAnAnswer(): void
    {
        $handler = new BooleanHandler();
        $question = $this->question(['preset' => 'YES_NO'])->setType(QuestionType::Boolean);

        self::assertFalse($handler->isEmptyAnswer(false));
        self::assertTrue($handler->isEmptyAnswer(''));
        self::assertTrue($handler->isEmptyAnswer(null));

        foreach ([[true, true], ['true', true], [1, true], [false, false], ['false', false], ['0', false]] as [$raw, $expected]) {
            self::assertSame(['value' => $expected], $handler->validateAnswer($question, $raw, $this->recipient)->toArray());
        }

        self::assertSame('Non', $handler->exportValue($question, ['value' => false]));
        $stats = $handler->computeStats($question, [['value' => true], ['value' => true], ['value' => false], ['value' => true]]);
        self::assertSame([4, 3, 1, 75.0, 25.0], [$stats['count'], $stats['trueCount'], $stats['falseCount'], $stats['truePercent'], $stats['falsePercent']]);

        $this->expectException(InvalidAnswerException::class);
        $handler->validateAnswer($question, 'peut-être', $this->recipient);
    }

    public function testConditionalDisplayCascadesAndHidesWithoutAnswer(): void
    {
        $evaluator = new ConditionEvaluatorService();
        $options = [['id' => 'mr', 'label' => 'Monsieur'], ['id' => 'mme', 'label' => 'Madame']];

        $civility = $this->question(['widget' => 'radio', 'options' => $options])->setType(QuestionType::Choice)->setPosition(1);
        // « Avez-vous changé de nom ? » (Oui / Non), seulement pour Madame
        $changedName = $this->question(['preset' => 'YES_NO'])->setType(QuestionType::Boolean)->setPosition(2);
        $changedName->setCondition(new DisplayCondition($civility->getId()->toRfc4122(), ConditionOperator::Equals, 'mme'));
        // Cascade : « Nom de naissance » dépend de la question 2, elle-même conditionnelle
        $maidenName = $this->question(['multiline' => false])->setType(QuestionType::Text)->setPosition(3);
        $maidenName->setCondition(new DisplayCondition($changedName->getId()->toRfc4122(), ConditionOperator::Equals, true));

        $ids = static fn (Question ...$q): array => array_map(static fn (Question $x): string => $x->getId()->toRfc4122(), $q);
        $all = [$civility, $changedName, $maidenName];
        $civ = $civility->getId()->toRfc4122();
        $changed = $changedName->getId()->toRfc4122();

        self::assertSame($ids($civility, $changedName, $maidenName), $evaluator->visibleQuestionIds($all, [$civ => ['optionIds' => ['mme']], $changed => ['value' => true]]));
        self::assertSame($ids($civility, $changedName), $evaluator->visibleQuestionIds($all, [$civ => ['optionIds' => ['mme']], $changed => ['value' => false]]));
        // Monsieur : question 2 masquée, donc la question 3 aussi (même avec une réponse « Oui » envoyée)
        self::assertSame($ids($civility), $evaluator->visibleQuestionIds($all, [$civ => ['optionIds' => ['mr']], $changed => ['value' => true]]));
        // Source sans réponse : condition fausse, même avec NOT_EQUALS
        $maidenName->setCondition(new DisplayCondition($changed, ConditionOperator::NotEquals, false));
        self::assertSame($ids($civility, $changedName), $evaluator->visibleQuestionIds($all, [$civ => ['optionIds' => ['mme']]]));
    }

    public function testConditionalSectionHidesAllItsQuestions(): void
    {
        $evaluator = new ConditionEvaluatorService();
        $first = (new SurveySection())->setTitle('Votre avis')->setPosition(1);
        $contact = (new SurveySection())->setTitle('Vos coordonnées')->setPosition(2);

        // « Souhaitez-vous être recontacté(e) ? » → section « Vos coordonnées » si Oui
        $callback = $this->question(['preset' => 'YES_NO'])->setType(QuestionType::Boolean)->setPosition(1)->setSection($first);
        $source = $callback->getId()->toRfc4122();
        $contact->setCondition(new DisplayCondition($source, ConditionOperator::Equals, true));
        $name = $this->question(['multiline' => false])->setType(QuestionType::Text)->setPosition(2)->setSection($contact);
        $email = $this->question(['multiline' => false])->setType(QuestionType::Text)->setPosition(3)->setSection($contact);

        $all = [$callback, $name, $email];
        $ids = static fn (Question ...$q): array => array_map(static fn (Question $x): string => $x->getId()->toRfc4122(), $q);

        self::assertSame($ids($callback, $name, $email), $evaluator->visibleQuestionIds($all, [$source => ['value' => true]]));
        self::assertSame($ids($callback), $evaluator->visibleQuestionIds($all, [$source => ['value' => false]]));
        self::assertSame($ids($callback), $evaluator->visibleQuestionIds($all, []));

        // Condition de question dans une section conditionnelle : les deux doivent être vraies
        $email->setCondition(new DisplayCondition($name->getId()->toRfc4122(), ConditionOperator::Equals, 'x'));
        self::assertSame($ids($callback, $name), $evaluator->visibleQuestionIds($all, [$source => ['value' => true]]));

        // Conception : la source doit appartenir à une section précédente
        $byId = [$source => $callback];
        self::assertSame([], $evaluator->sectionDesignErrors($contact, $byId));
        self::assertSame([ConditionEvaluatorService::SECTION_ORDER_RULE], $evaluator->sectionStructureErrors(new DisplayCondition($source, ConditionOperator::Equals, true), QuestionType::Boolean, [], 2, 2));
        self::assertSame([ConditionEvaluatorService::SOURCE_RULE], $evaluator->sectionStructureErrors(new DisplayCondition($source, ConditionOperator::Equals, 'a'), QuestionType::Text, [], 1, 2));
        self::assertStringContainsString('n\'existe plus', $evaluator->sectionDesignErrors($contact, [])[0]);
    }

    public function testConditionStructureAndDesignErrors(): void
    {
        $evaluator = new ConditionEvaluatorService();
        $radio = ['widget' => 'radio', 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]];
        $choice = new DisplayCondition('x', ConditionOperator::Equals, 'a');

        self::assertStringContainsString('avant', $evaluator->structureErrors($choice, QuestionType::Choice, $radio, 3, 2)[0]);

        // Sources autorisées : BOOLEAN et CHOICE à choix unique (radio ou liste) uniquement
        self::assertSame([], $evaluator->structureErrors($choice, QuestionType::Choice, $radio, 1, 2));
        self::assertSame([], $evaluator->structureErrors($choice, QuestionType::Choice, ['widget' => 'select'] + $radio, 1, 2));
        self::assertSame([], $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::Equals, true), QuestionType::Boolean, [], 1, 2));
        foreach ([[QuestionType::Choice, ['widget' => 'checkbox'] + $radio], [QuestionType::Text, []], [QuestionType::Date, []], [QuestionType::File, []]] as [$type, $config]) {
            self::assertSame([ConditionEvaluatorService::SOURCE_RULE], $evaluator->structureErrors($choice, $type, $config, 1, 2), $type->value);
        }
        self::assertTrue(ConditionEvaluatorService::canBeSource(QuestionType::Choice, $radio));
        self::assertFalse(ConditionEvaluatorService::canBeSource(QuestionType::Choice, ['widget' => 'checkbox'] + $radio));

        // Chiffres et Échelle : comparaisons numériques ; la valeur doit être un nombre
        $below5 = new DisplayCondition('x', ConditionOperator::LessThan, 5);
        self::assertSame([], $evaluator->structureErrors($below5, QuestionType::Range, ['min' => 1, 'max' => 10], 1, 2));
        self::assertSame([], $evaluator->structureErrors($below5, QuestionType::Number, [], 1, 2));
        self::assertNotSame([], $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::LessThan, 'cinq'), QuestionType::Range, [], 1, 2));
        self::assertStringContainsString('opérateur', $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::In, [5]), QuestionType::Number, [], 1, 2)[0]);
        self::assertStringContainsString('opérateur', $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::LessThan, 'a'), QuestionType::Choice, $radio, 1, 2)[0]);

        // « Note de 1 à 10 inférieure à 5 » : 4 → visible, 5 → masquée ; ≤ inclut la borne
        self::assertTrue($evaluator->isSatisfied($below5, QuestionType::Range, ['value' => 4]));
        self::assertFalse($evaluator->isSatisfied($below5, QuestionType::Range, ['value' => 5]));
        self::assertTrue($evaluator->isSatisfied(new DisplayCondition('x', ConditionOperator::LessOrEqual, 5), QuestionType::Range, ['value' => 5]));
        self::assertTrue($evaluator->isSatisfied(new DisplayCondition('x', ConditionOperator::GreaterOrEqual, 2.5), QuestionType::Number, ['value' => 2.5]));
        self::assertFalse($evaluator->isSatisfied(new DisplayCondition('x', ConditionOperator::GreaterThan, 2.5), QuestionType::Number, ['value' => 2.5]));
        self::assertTrue($evaluator->isSatisfied(new DisplayCondition('x', ConditionOperator::NotEquals, 3), QuestionType::Number, ['value' => 4]));
        self::assertFalse($evaluator->isSatisfied($below5, QuestionType::Range, null));

        // Opérateur ou valeur incompatibles
        self::assertStringContainsString('opérateur', $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::In, [true]), QuestionType::Boolean, [], 1, 2)[0]);
        self::assertNotSame([], $evaluator->structureErrors(new DisplayCondition('x', ConditionOperator::Equals, 'oui'), QuestionType::Boolean, [], 1, 2));

        // Option supprimée de la source : signalé à la validation
        $source = $this->question(['widget' => 'radio', 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]])->setType(QuestionType::Choice)->setPosition(1);
        $dependent = $this->question([])->setPosition(2)->setCondition(new DisplayCondition($source->getId()->toRfc4122(), ConditionOperator::Equals, 'zz'));
        self::assertStringContainsString('n\'existe plus', $evaluator->designErrors($dependent, [$source->getId()->toRfc4122() => $source, $dependent->getId()->toRfc4122() => $dependent])[0]);
    }

    public function testRangeConfigAndAnswers(): void
    {
        $handler = new RangeHandler();

        // Valeurs par défaut : échelle de 0 à 10, sans réponse présélectionnée
        self::assertSame(['min' => 0, 'max' => 10, 'step' => 1, 'defaultValue' => null], $handler->normalizeConfig([]));
        $config = $handler->normalizeConfig(['min' => '0', 'max' => 20, 'step' => 0.5, 'minLabel' => ' Insuffisant ', 'maxLabel' => 'Excellent', 'unit' => '/20', 'defaultValue' => 10]);
        self::assertSame(['min' => 0, 'max' => 20, 'step' => 0.5, 'minLabel' => 'Insuffisant', 'maxLabel' => 'Excellent', 'unit' => '/20', 'defaultValue' => 10], $config);
        self::assertSame([], $handler->validateConfig($config));

        self::assertSame(['La valeur minimale doit être inférieure à la valeur maximale.'], $handler->validateConfig(['min' => 10, 'max' => 0]));
        self::assertStringContainsString('multiple du pas', $handler->validateConfig(['min' => 0, 'max' => 10, 'step' => 3])[0]);
        self::assertStringContainsString('trop de crans', $handler->validateConfig(['min' => 0, 'max' => 10000, 'step' => 1])[0]);
        self::assertStringContainsString('valeur de départ', $handler->validateConfig(['defaultValue' => 11])[0]);

        $question = $this->question($config)->setType(QuestionType::Range);
        self::assertSame(['value' => 8], $handler->validateAnswer($question, '8', $this->recipient)->toArray());
        self::assertSame(['value' => 7.5], $handler->validateAnswer($question, '7,5', $this->recipient)->toArray());
        self::assertSame('7,5', $handler->exportValue($question, ['value' => 7.5]));

        foreach (['7.3', '21', '-1', 'huit'] as $invalid) {
            try {
                $handler->validateAnswer($question, $invalid, $this->recipient);
                self::fail(\sprintf('« %s » aurait dû être refusée.', $invalid));
            } catch (InvalidAnswerException) {
                $this->addToAssertionCount(1);
            }
        }

        // Valeurs personnalisées : triées, dédoublonnées ; min et max déduits ; seules ces valeurs sont acceptées
        $custom = $handler->normalizeConfig(['values' => [79, '2', 4, 6, 22, 40, 4], 'defaultValue' => 22]);
        self::assertSame([2, 4, 6, 22, 40, 79], $custom['values']);
        self::assertSame([2, 79], [$custom['min'], $custom['max']]);
        self::assertSame([], $handler->validateConfig($custom));
        $customQuestion = $this->question($custom)->setType(QuestionType::Range);
        self::assertSame(['value' => 40], $handler->validateAnswer($customQuestion, '40', $this->recipient)->toArray());
        try {
            $handler->validateAnswer($customQuestion, '5', $this->recipient);
            self::fail('5 ne fait pas partie de l\'échelle personnalisée.');
        } catch (InvalidAnswerException $e) {
            self::assertStringContainsString('l\'une des valeurs', $e->getMessage());
        }
        self::assertStringContainsString('au moins 2 valeurs', $handler->validateConfig(['values' => [3]])[0]);
        self::assertStringContainsString('valeur de départ', $handler->validateConfig(['values' => [1, 2], 'defaultValue' => 5])[0]);
        $customStats = $handler->computeStats($customQuestion, [['value' => 22], ['value' => 22], ['value' => 79]]);
        self::assertSame([2, 4, 6, 22, 40, 79], array_column($customStats['distribution'], 'value'));

        // Répartition cran par cran sur une échelle courte
        $nps = $this->question(['min' => 0, 'max' => 10, 'minLabel' => 'Pas du tout', 'maxLabel' => 'Absolument'])->setType(QuestionType::Range);
        $stats = $handler->computeStats($nps, [['value' => 8], ['value' => 10], ['value' => 8], ['value' => 3]]);
        self::assertSame([4, 3, 10, 7.25, 8.0], [$stats['count'], $stats['min'], $stats['max'], $stats['mean'], $stats['median']]);
        self::assertCount(11, $stats['distribution']);
        self::assertSame(['value' => 8, 'count' => 2], $stats['distribution'][8]);
        self::assertSame('Pas du tout', $stats['minLabel']);
    }

    public function testMarkdownLabelIsFlattenedForExports(): void
    {
        self::assertSame('Êtes-vous satisfait de votre poste actuel ?', MarkdownText::toPlain('Êtes-vous **satisfait** de votre _poste_ [actuel](https://exemple.fr) ?'));
        self::assertSame("Précisez :\nvos horaires\nvos outils", MarkdownText::toPlain("Précisez :\n- `vos horaires`\n- ~~vos~~ outils"));
        self::assertSame('Prix : 10 * 2 = 20', MarkdownText::toPlain('Prix : 10 * 2 = 20'));
    }

    public function testNumberStatistics(): void
    {
        $values = [2, 4, 4, 4, 5, 5, 7, 9];

        self::assertSame(5.0, Statistics::mean($values));
        self::assertSame(4.5, Statistics::median($values));
        self::assertSame(2.0, Statistics::standardDeviation($values));
        self::assertSame(\count($values), array_sum(array_column(Statistics::histogram($values, true), 'count')));
    }

    public function testEmailListIsCleanedAndDeduplicated(): void
    {
        $result = EmailListParser::parse("email;nom\n  JEAN.Dupont@Example.com ;Jean\n\"Marie\" <marie@example.org>\njean.dupont@example.com\nfaux@\nlocal@localhost");

        self::assertSame(['jean.dupont@example.com', 'marie@example.org'], $result['valid']);
        self::assertSame(1, $result['duplicates']);
        self::assertSame(['faux@', 'local@localhost'], $result['invalid']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function question(array $config): Question
    {
        return (new Question())->setLabel('Question')->setConfig($config);
    }
}
