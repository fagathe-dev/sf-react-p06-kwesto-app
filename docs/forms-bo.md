# Back-office (`apps/forms_bo`)

React 19 + Vite + TypeScript strict. UI Kit, thème et icônes de `core-react` ; client HTTP, stockage et formats de `core-ts`. L'application ne parle qu'à `forms_api` (`VITE_API_URL`).

```bash
npm run dev         # http://localhost:5173
npm run build       # tsc -b puis vite build → dist/
npm run lint        # ESLint (règles React Compiler incluses)
npm test            # Vitest + Testing Library
```

## Routes

| Route | Page | Accès |
| :--- | :--- | :--- |
| `/login`, `/logout` | Connexion, déconnexion | anonyme |
| `/surveys` | Mes questionnaires (filtre par état, recherche, création, duplication) | créateur |
| `/surveys/:id` | Builder (module 1) | créateur |
| `/surveys/:id/recipients` | Diffusion et suivi des destinataires (module 2) | créateur |
| `/surveys/:id/stats` | Statistiques, exports (module 4) | créateur |
| `/search?q=` | Recherche plein texte | créateur |
| `/admin/emails`, `/admin/logs` | Espace Administration | `ROLE_ADMIN` |
| `/s/:token` | Formulaire participant (module 3), sans la coque du dashboard | anonyme |

## Organisation

```text
src/
├── services/        api.ts (un verbe par endpoint), participant.api.ts, http.ts (fetchAPI de core-ts + JWT), errors.ts
├── stores/          auth.store.ts (session JWT), config.store.ts (limite d'upload lue depuis l'API)
├── types/           contrats JSON de l'API, un fichier par domaine
├── hooks/           useAsync (chargement, réponses obsolètes ignorées)
├── layouts/         AppLayout (barre latérale, recherche, mode d'affichage)
├── router/          routes et gardes (RequireAuth, RequireAdmin, GuestOnly)
├── ui/              HelpBox, BarList, DateTimeField, Page, primitives, thème
└── pages/           une page par route ; sous-composants et modèles à côté de leur page
```

Règles suivies : aucun `fetch` dans un composant, props de style transitoires `$` (core-react), couleurs uniquement depuis le thème, libellés en français, rendu pur (pas de `Date.now()` ni d'accès aux refs pendant le rendu).

## Builder (`pages/surveys/builder`)

- « Dupliquer » (`pages/surveys/DuplicateSurveyButton.tsx`), sur chaque carte de « Mes questionnaires » et dans l'en-tête du builder, quel que soit l'état : `POST …/duplicate` crée un brouillon « … (copie) » avec les mêmes options, sections, questions et conditions, sans destinataires ni réponses, puis l'ouvre dans le builder. Depuis le builder, les modifications en attente sont enregistrées avant la copie. C'est le moyen de repartir d'un questionnaire verrouillé.

- `builderModel.ts` : état pur (sections, questions dans l'ordre), numérotation continue, déplacements, conversion vers le contrat de l'API — testé unitairement.
- `useBuilder.ts` : enregistrement automatique 800 ms après chaque modification, déplacements compris (`PUT /structure`), indicateur « Enregistré », enregistrement forcé avant de quitter.
- Glisser-déposer `@dnd-kit` par la poignée, au clavier (flèches, Espace) avec annonces vocales : questions au sein d'une section et d'une section à l'autre ; plan des étapes pour réordonner les sections.
- Questions en accordéon (`QuestionCard`) : une seule question dépliée à la fois ; repliée, elle tient sur une ligne (numéro, type, intitulé, obligatoire/facultative), ce qui facilite la relecture et le glisser-déposer. Une question ajoutée ou dupliquée s'ouvre, les autres se replient. Les erreurs de validation restent visibles sur les questions repliées.
- Type de question : liste déroulante accessible avec icône, libellé et description (`QuestionTypeSelect`, motif ARIA combobox : flèches, Début/Fin, Entrée, Échap, première lettre).
- Ajout de question : bouton pleine largeur en fin de section (choix du type par cartes), « + » d'insertion entre deux questions, séparateur « Nouvelle section ».
- « Affichage pour le participant » : `Liste` ou `Question par question` (`displayMode`), enregistré immédiatement via `PATCH`, modifiable jusqu'au lancement ; une phrase explique le rendu selon le nombre de sections.
- « Aperçu » (`SurveyPreview`) : le vrai parcours participant (`SurveyRunner`) en plein écran, construit depuis l'état courant du builder (modifications non enregistrées comprises), sans aucun appel à l'API. Démarre dans le mode du questionnaire, bascule possible entre Liste et Question par question. « Suivant » / « Précédent » sans contrôle, sauf si « Appliquer les contrôles » est coché ; sections du stepper cliquables ; fichiers simulés localement ; intitulés vides signalés. Échap ferme l'aperçu. Disponible aussi une fois le questionnaire verrouillé.
- « Ajouter une section » : la première section reçoit toutes les questions existantes ; supprimer une section rattache ses questions à la précédente ; sans section, le formulaire redevient une page unique.
- Intitulé (Markdown en ligne) et texte d'aide (Markdown) saisis avec le `MarkdownEditor` de core-react ; rendu sûr par `ui/Markdown.tsx` (`InlineMarkdown`, `MarkdownBlock`), texte brut par `ui/markdownText.ts`.
- Bloc « Options du questionnaire » : affichage des questions (liste / question par question) et « Afficher la durée estimée » (`showDuration`), avec l'aperçu de ce que verra le participant ; suggestion de masquer la durée au-delà de 10 minutes. Le nombre de questions est toujours affiché.
- Aide contextuelle : titre (80 caractères) et texte, aperçu en direct de l'encart. Choix multiple sans aide : « Plusieurs réponses possibles. » par défaut (placeholder et aperçu).
- Type « Oui / Non » (BOOLEAN) : libellés (modèles ou personnalisés), deux boutons ou interrupteur, valeur par défaut, aperçu manipulable.
- Type « Échelle » (RANGE, `builder/RangeEditor.tsx`) : « Intervalle régulier » (min, max, pas) ou « Valeurs personnalisées » (liste séparée par « ; », « , » ou des espaces, ex. 2 ; 4 ; 6 ; 22 ; 40 ; 79), suffixe (`/10`, `%`…), libellés des extrémités, valeur de départ facultative, aperçu manipulable (`ui/RangeSlider.tsx`, `Slider` de core-react).
- Affichage conditionnel : « Afficher cette question uniquement si… » (source : question précédente **Oui / Non, à choix unique — radio ou liste —, Chiffres ou Échelle** seulement ; opérateurs « est », « n'est pas », « est l'une de » pour un choix, « est égal à », « est différent de », « est inférieur (ou égal) à », « est supérieur (ou égal) à » pour un nombre ; valeur parmi les options, Oui / Non, les crans de l'échelle ou un seuil saisi ; une source qui change de type ou passe en choix multiple perd ses conditions dépendantes), badge « Si Q1 est « Madame » » sur la carte, même repliée ; suppression ou changement de type de la source → conditions dépendantes retirées ; déplacement au-dessus de la source → confirmation (retirer la condition ou annuler).
- Section conditionnelle : « Afficher cette section uniquement si… » sous le titre et la description de chaque section (à partir de la 2ᵉ). Sources : questions des sections précédentes. Badge dans l'en-tête de la section. Condition retirée si la source est supprimée, change de type ou n'est plus dans une section précédente (déplacement confirmé, suppression de section).
- Question FILE : encart « Envoi de fichiers » ou « Limite dépassée » selon `uploadMaxSizeMb` lu depuis l'API.
- « Valider le questionnaire » : confirmation, puis erreurs 422 affichées sur les questions et sections concernées ; succès → page de diffusion. Hors `DRAFT`, tout est en lecture seule.

## Formulaire participant (`pages/participant`)

- États : chargement, lien invalide, clôturé, déjà répondu (formulaire masqué), formulaire, remerciement.
- Parcours commun `SurveyRunner` + `participantModel.buildFlow` : liste (un écran, ou un par section) ou question par question (un écran par question, focus placé sur le champ, Entrée → suivant ; Entrée n'envoie jamais).
- Barre de navigation, puis bandeau collé en haut (stepper puis progression), aussi sur mobile.
- Barre de progression toujours visible (`role="progressbar"`, `aria-valuenow/min/max`) : « N questions renseignées sur T » sur un seul écran ; « Étape x sur n » en liste par sections ; « Question x sur n » en question par question.
- Stepper uniquement dès 2 sections, quel que soit le mode : un item par section (position + titre), section en cours en `aria-current="step"` ; sur mobile, une ligne défilante avec le seul titre de la section en cours.
- « Suivant » valide l'écran et place le focus sur la première erreur, « Précédent » sans validation, réponses conservées, « Envoyer » au dernier écran seulement.
- Encart d'aide lu après l'intitulé et relié au champ par `aria-describedby`.
- Sous la description : nombre de questions (toujours) et durée estimée (si `showDuration`).
- Choix à 10 options et plus (`ChoiceAutocomplete.tsx`, `choiceSearch.ts`) : filtre au-dessus des boutons radio / cases à cocher (réponses cochées toujours visibles), saisie semi-automatique à la place de la liste déroulante.
- Questions conditionnelles (`conditionEvaluator.ts`, miroir de `ConditionEvaluatorService`) : réévaluées à chaque réponse, apparition annoncée (`aria-live`), valeur effacée (fichiers retirés) quand la question redevient masquée, obligatoire seulement si visible, jamais envoyée masquée ; section conditionnelle ou entièrement masquée sautée (stepper renuméroté) ; numérotation du builder conservée.
- Validation locale identique aux règles serveur (`participantModel.ts`, testée), le serveur restant seul juge.
- Fichiers : glisser-déposer (`FileDropzone` de core-react), progression d'envoi, taille effective et formats affichés, retrait avant envoi, reprise après rechargement (`pendingAssets`).
- Brouillon : réponses et position enregistrées 800 ms après la dernière modification (`PUT …/draft`), note « Réponses enregistrées à HH:MM… ». Au retour sur le lien, écran de reprise (date, nombre de réponses) : « Reprendre où j'en étais » ou « Recommencer » (`DELETE …/draft`, fichiers déposés retirés).

## Production

`dist/` est une SPA : le serveur web doit renvoyer `index.html` pour toutes les routes et poser l'en-tête `X-Robots-Tag: noindex, nofollow` (déjà posé par `vite dev` et `vite preview`, et doublé d'une balise meta et d'un `robots.txt` « Disallow: / »). Exemple Nginx :

```nginx
location / {
    add_header X-Robots-Tag "noindex, nofollow" always;
    try_files $uri /index.html;
}
```

Le bundle principal dépasse 500 Ko (UI Kit complet) : un découpage par route (`React.lazy`) est une piste d'optimisation.
