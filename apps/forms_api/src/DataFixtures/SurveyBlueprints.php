<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Modèles de questionnaires réalistes, couvrant les 7 types de questions, l'aide contextuelle
 * et le découpage en sections (stepper).
 *
 * Format : [titre, description, sections] ; section = [titre, description, questions] ;
 * question = [type, intitulé, obligatoire, config, aide (titre, texte) | null, condition?] ;
 * condition = [intitulé de la question source, opérateur, valeur] — pour un CHOICE, la valeur est le
 * libellé de l'option (ou une liste de libellés pour IN), traduit en identifiant d'option.
 * Un modèle avec une seule section est rendu en page unique (aucune section créée).
 */
final class SurveyBlueprints
{
    /**
     * @return array<string, array{string, string, list<array{string, string|null, list<array{string, string, bool, array<string, mixed>, array{string|null, string}|null}>}>}>
     */
    public static function all(): array
    {
        return [
            'barometre' => [
                'Baromètre de satisfaction interne 2026',
                "Votre avis compte : ce baromètre annuel nous aide à améliorer le quotidien de chacun.\nVos réponses sont confidentielles et analysées de façon agrégée.",
                [
                    ['Votre environnement de travail', 'Locaux, outils et organisation au quotidien.', [
                        ['CHOICE', 'Dans l\'ensemble, êtes-vous satisfait(e) de vos conditions de travail ?', true, self::choice('radio', ['Très satisfait(e)', 'Plutôt satisfait(e)', 'Plutôt insatisfait(e)', 'Très insatisfait(e)']), null],
                        ['BOOLEAN', 'Êtes-vous satisfait(e) de votre poste actuel ?', true, ['preset' => 'YES_NO', 'widget' => 'switch', 'defaultValue' => false], null],
                        ['RANGE', 'Recommanderiez-vous l\'entreprise à un proche ?', true, ['min' => 0, 'max' => 10, 'step' => 1, 'minLabel' => 'Pas du tout', 'maxLabel' => 'Absolument', 'unit' => '/10'], ['À savoir', 'C\'est l\'indicateur eNPS : 0 = pas du tout, 10 = certainement.']],
                        ['CHOICE', 'Quels outils utilisez-vous chaque jour ?', false, self::choice('checkbox', ['Messagerie instantanée', 'Visioconférence', 'Gestionnaire de tâches', 'Intranet', 'Tableur partagé']), null],
                    ]],
                    ['Management et communication', null, [
                        ['CHOICE', 'Recevez-vous suffisamment de retours sur votre travail ?', true, self::choice('select', ['Toujours', 'Souvent', 'Parfois', 'Rarement', 'Jamais']), null],
                        ['TEXT', 'Qu\'est-ce qui faciliterait votre travail au quotidien ?', false, ['multiline' => true, 'maxLength' => 2000, 'placeholder' => 'Une idée, un irritant, une suggestion…'], ['Conseil', 'Soyez concret : un exemple vaut mieux qu\'un long discours.']],
                    ]],
                    ['Votre profil', 'Ces informations servent uniquement aux statistiques agrégées.', [
                        ['NUMBER', 'Ancienneté dans l\'entreprise', true, ['allowDecimal' => false, 'min' => 0, 'max' => 45, 'unit' => 'ans'], null],
                        ['DATE', 'Date de votre dernier entretien annuel', false, ['minDate' => '2020-01-01', 'maxDate' => '2026-12-31'], null],
                    ]],
                ],
            ],
            'formation' => [
                'Évaluation de la formation « Piloter un projet agile »',
                'Merci de prendre deux minutes pour évaluer la session à laquelle vous avez participé.',
                [
                    ['Évaluation', null, [
                        ['CHOICE', 'Comment évaluez-vous la formation dans son ensemble ?', true, self::choice('radio', ['Excellente', 'Bonne', 'Moyenne', 'Insuffisante']), null],
                        ['RANGE', 'Note du formateur ou de la formatrice', true, ['min' => 0, 'max' => 20, 'step' => 0.5, 'minLabel' => 'Insuffisant', 'maxLabel' => 'Excellent', 'unit' => '/20', 'defaultValue' => 10], null],
                        ['CHOICE', 'Les objectifs annoncés ont-ils été atteints ?', true, self::choice('radio', ['Oui, entièrement', 'En partie', 'Non']), null],
                        ['BOOLEAN', 'Recommanderiez-vous cette formation à un collègue ?', true, ['preset' => 'YES_NO', 'widget' => 'buttons'], null],
                        ['TEXT', 'Ce que vous avez le plus apprécié', false, ['multiline' => false, 'maxLength' => 255], null],
                        ['TEXT', 'Vos suggestions d\'amélioration', false, ['multiline' => true, 'maxLength' => 3000], null],
                    ]],
                ],
            ],
            'dossier' => [
                'Dossier de candidature — Programme Incubateur 2027',
                "Constituez votre dossier en ligne. Les pièces justificatives sont obligatoires.\nLe jury examinera les dossiers complets après la date limite.",
                [
                    ['Votre projet', 'Présentez votre projet en quelques lignes.', [
                        ['CHOICE', 'Civilité du porteur de projet', true, self::choice('radio', ['Monsieur', 'Madame']), null],
                        ['TEXT', 'Nom de naissance (nom de jeune fille)', false, ['multiline' => false, 'maxLength' => 120], null, ['Civilité du porteur de projet', 'EQUALS', 'Madame']],
                        ['TEXT', 'Nom du projet', true, ['multiline' => false, 'minLength' => 3, 'maxLength' => 120], null],
                        ['TEXT', 'Description du projet', true, ['multiline' => true, 'minLength' => 50, 'maxLength' => 5000], ['Qu\'attendons-nous ?', "Le problème résolu, la cible et ce qui vous différencie.\n10 à 20 lignes suffisent."]],
                        ['CHOICE', 'Secteur d\'activité', true, self::choice('select', ['Santé', 'Éducation', 'Énergie', 'Mobilité', 'Agriculture', 'Services numériques', 'Autre']), null],
                        ['NUMBER', 'Montant du financement recherché', true, ['allowDecimal' => false, 'min' => 1000, 'max' => 500000, 'step' => 1000, 'unit' => '€'], null],
                    ]],
                    ['Pièces justificatives', 'Formats acceptés : PDF, JPEG, PNG, XLSX.', [
                        ['FILE', 'Business plan', true, ['allowedMimeTypes' => ['application/pdf'], 'maxSizeMb' => 5, 'maxFiles' => 1], null],
                        ['FILE', 'Prévisionnel financier et annexes', false, ['allowedMimeTypes' => ['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'maxSizeMb' => 10, 'maxFiles' => 3], null],
                        ['DATE', 'Date de création de la structure', false, ['maxDate' => '2026-12-31'], null],
                    ]],
                ],
            ],
            'evenement' => [
                'Inscription au séminaire annuel',
                'Confirmez votre présence et vos préférences pour l\'organisation de la journée.',
                [
                    ['Inscription', null, [
                        ['CHOICE', 'Serez-vous présent(e) ?', true, self::choice('radio', ['Oui', 'Non', 'Je ne sais pas encore']), null],
                        ['CHOICE', 'Ateliers souhaités', false, self::choice('checkbox', ['Intelligence artificielle', 'Qualité de vie au travail', 'Relation client', 'Sobriété numérique']), ['Bon à savoir', 'Chaque atelier est limité à 30 personnes.'], ['Serez-vous présent(e) ?', 'NOT_EQUALS', 'Non']],
                        ['CHOICE', 'Régime alimentaire', false, self::choice('select', ['Aucun', 'Végétarien', 'Végétalien', 'Sans gluten', 'Halal', 'Casher']), null, ['Serez-vous présent(e) ?', 'NOT_EQUALS', 'Non']],
                        ['DATE', 'Date d\'arrivée souhaitée', true, ['minDate' => '2027-01-10', 'maxDate' => '2027-01-14'], null, ['Serez-vous présent(e) ?', 'EQUALS', 'Oui']],
                        ['BOOLEAN', 'Viendrez-vous accompagné(e) ?', true, ['preset' => 'YES_NO', 'widget' => 'switch', 'defaultValue' => false], null, ['Serez-vous présent(e) ?', 'EQUALS', 'Oui']],
                        ['NUMBER', 'Nombre d\'accompagnants', true, ['allowDecimal' => false, 'min' => 1, 'max' => 3], null, ['Viendrez-vous accompagné(e) ?', 'EQUALS', true]],
                    ]],
                ],
            ],
            'teletravail' => [
                'Enquête sur le télétravail',
                'Aidez-nous à adapter la charte de télétravail à vos besoins.',
                [
                    ['Vos habitudes', null, [
                        ['NUMBER', 'Combien de jours par semaine télétravaillez-vous ?', true, ['allowDecimal' => true, 'min' => 0, 'max' => 5, 'step' => 0.5, 'unit' => 'jours'], null],
                        ['CHOICE', 'Votre équipement à domicile est-il adapté ?', true, self::choice('radio', ['Oui', 'En partie', 'Non']), null],
                        ['TEXT', 'Que vous manque-t-il ?', true, ['multiline' => false, 'maxLength' => 255], null, ['Votre équipement à domicile est-il adapté ?', 'IN', ['En partie', 'Non']]],
                        ['TEXT', 'Votre principal frein au télétravail', false, ['multiline' => true, 'maxLength' => 1000], null],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $labels
     *
     * @return array<string, mixed>
     */
    private static function choice(string $widget, array $labels): array
    {
        $options = [];
        foreach ($labels as $index => $label) {
            $options[] = ['id' => 'opt'.($index + 1), 'label' => $label];
        }

        return ['widget' => $widget, 'options' => $options];
    }
}
