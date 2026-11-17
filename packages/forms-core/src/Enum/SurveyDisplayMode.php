<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Affichage des questions dans le formulaire participant (specs_fonc §6.4).
 *
 * Indépendant des sections : avec au moins 2 sections, le stepper s'affiche dans les deux modes.
 */
enum SurveyDisplayMode: string
{
    /** Toutes les questions de la page (ou de la section en cours) en même temps. */
    case List = 'LIST';

    /** Une question par écran, « Suivant » pour passer à la suivante. */
    case OneByOne = 'ONE_BY_ONE';
}
