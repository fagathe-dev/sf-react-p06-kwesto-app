# Spécifications Fonctionnelles — Plateforme de Questionnaires & Sondages par Invitation

> **Contexte d'utilisation :** référence fonctionnelle complète du produit. Toute implémentation (API Symfony ou interface React) doit respecter strictement les règles métier, états, flux et permissions décrits ci-dessous.
> Document compagnon : [`specs_tech.prompt.md`](./specs_tech.prompt.md).

## Table des matières

1. [Vision du produit et rôles](#1-vision-du-produit-et-rôles)
2. [Cycle de vie d'un questionnaire](#2-cycle-de-vie-dun-questionnaire)
3. [Module 1 — Conception du questionnaire](#3-module-1--conception-du-questionnaire-builder) (dont affichage conditionnel, §3.5)
4. [Les 7 types de questions](#4-les-7-types-de-questions)
5. [Module 2 — Liste de diffusion et planification](#5-module-2--liste-de-diffusion-et-planification)
6. [Module 3 — Interface participant et télémétrie](#6-module-3--interface-participant-unicité-et-télémétrie)
7. [Module 4 — Clôture et statistiques](#7-module-4--clôture-automatique-et-statistiques-consolidées)
8. [Catalogue des e-mails transactionnels](#8-catalogue-des-e-mails-transactionnels)
9. [Tâches automatisées](#9-tâches-automatisées-crons--maintenance)
10. [Espace Administration](#10-espace-administration-role_admin)
11. [Site vitrine public](#11-site-vitrine-public)

---

## 1. Vision du produit et rôles

Plateforme de création, de diffusion ciblée et d'analyse statistique de questionnaires en ligne (inspirée de Slido, orientée campagnes sur invitation nominative).

| Profil | Authentification | Capacités |
| :--- | :--- | :--- |
| **Créateur** (`ROLE_USER`) | Dashboard | Concevoir ses questionnaires, ordonner les questions par drag-and-drop, verrouiller la structure, configurer la liste de diffusion et la date de fin, suivre les réponses, exploiter les statistiques. |
| **Administrateur** (`ROLE_ADMIN`) | Dashboard | Tous les droits du créateur + espace Administration (prévisualisation des templates d'e-mails, consultation des logs). Voir [§10](#10-espace-administration-role_admin). |
| **Participant** | Aucune (pas de compte, pas de mot de passe) | Accès via URL personnalisée `/s/{token}` reçue par e-mail. Une seule soumission possible. |

---

## 2. Cycle de vie d'un questionnaire

Machine à états stricte à quatre statuts :

| État | Déclencheur | Questions | Accès participant (`/s/{token}`) | Statistiques |
| :--- | :--- | :--- | :--- | :--- |
| **`DRAFT`** | Création initiale | Création, édition, suppression, réorganisation | Inaccessible (404 / non publié) | Aucune donnée |
| **`VALIDATED`** | Clic sur « Valider le questionnaire » | **Verrouillées** (lecture seule) | Inaccessible (en attente de diffusion) | En attente |
| **`ACTIVE`** | Lancement de la diffusion (envoi des invitations) | Verrouillées | **Ouvert** si jeton non utilisé et date limite non atteinte | Calcul temps réel partiel |
| **`CLOSED`** | 100 % des destinataires valides ont répondu, **ou** date limite atteinte, **ou** clôture manuelle | Verrouillées | **Bloqué** (écran « Formulaire clôturé ») | **Consolidées et figées** |

### Règle d'immutabilité

Dès `VALIDATED`, il est impossible d'ajouter, supprimer, réordonner ou modifier le type, les règles de validation ou la condition d'affichage (§3.5) d'une question. Cela garantit l'intégrité mathématique des statistiques consolidées.

---

## 3. Module 1 — Conception du questionnaire (Builder)

### 3.1 Métadonnées

| Champ | Contrainte |
| :--- | :--- |
| Titre | Obligatoire, 3 à 150 caractères |
| Description / consignes | Optionnelle, texte riche ou paragraphe affiché en en-tête du formulaire participant |

### 3.2 Questions et réorganisation (drag-and-drop)

Tant que le statut est `DRAFT`, le nombre de questions est illimité.

**Propriétés communes à toute question**

| Propriété | Type | Règle |
| :--- | :--- | :--- |
| `label` | texte **Markdown en ligne**, max 500 car. | Obligatoire. Gras, italique, barré, surligné, code, lien (rendu dans un `<label>` : pas de titres, listes ni images) |
| `helpTitle` | texte, max 80 car. | Optionnel. Titre de l'encart d'aide (ex. : « À savoir »). Ignoré si `helpText` est vide |
| `helpText` | texte **Markdown** | Optionnel. Texte d'aide / précision explicative (voir « Aide contextuelle » ci-dessous) |

**Markdown (intitulé et aide).** Saisi avec l'éditeur Markdown de `core-react` (barre d'outils, onglet Aperçu). Rendu sûr : aucun HTML interprété, seuls les liens `http(s)`, `mailto` et relatifs sont actifs (nouvel onglet, `rel="noopener noreferrer nofollow"`), pas d'images. Là où il faut du texte brut (export CSV / Excel, dossiers de l'archive ZIP, recherche, ligne repliée du builder, listes de questions sources, annonces aux lecteurs d'écran), le Markdown est retiré (`FormsCore\Util\MarkdownText`, `forms_bo/src/ui/markdownText.ts`).
| `required` | booléen | `true` par défaut. Pour une question conditionnelle, obligatoire **uniquement si elle est affichée** (§3.5) |
| `position` | entier | ≥ 1, séquentiel, démarre à `1` |
| `displayCondition` | objet, optionnel | Condition d'affichage portant sur une question **précédente** (§3.5). Absent = question toujours affichée |

**Aide contextuelle**

Une question peut afficher une aide pour préciser son sens (ex. : « Qu'est-ce que vous pensez de la … ? » suivi d'une explication). Il n'existe **qu'un seul mode d'affichage** : un encart (box) d'information, affiché en permanence sous l'intitulé de la question.

| Élément | Règle |
| :--- | :--- |
| Icône | Icône « info » (ⓘ), toujours présente |
| Titre | `helpTitle`, optionnel, en gras |
| Texte | `helpText` en Markdown (paragraphes, retours à la ligne, listes, citations, liens, gras…), pas de HTML arbitraire |
| Style | Un seul style : encart teinté bleu clair, coins arrondis, texte bleu foncé. Aucune variante de couleur ni de mode (pas d'infobulle, pas de boîte de dialogue) |
| Boutons | Aucun (pas d'action, pas de fermeture) |

Règles :
* Sans `helpText`, aucun encart n'est affiché.
* Accessibilité : contenu lu après l'intitulé, relié au champ par `aria-describedby`.
* Le builder propose un aperçu en direct de l'encart pendant l'édition.
* L'aide est figée dès `VALIDATED` (règle d'immutabilité, §2).

**Numérotation dynamique**
* Chaque carte de question dans le builder dispose d'une poignée de glisser-déposer (*drag handle*).
* Au déplacement, l'interface renumérote immédiatement toutes les questions (`Question 1` … `Question N`) et persiste le nouvel ordre en base.
* Cette numérotation fixe l'ordre d'affichage sur le formulaire participant **et** dans le rapport statistique.
* La numérotation reste **continue sur tout le questionnaire**, même lorsqu'il est découpé en sections (voir §3.4) : une question déplacée d'une section à l'autre est renumérotée comme les autres.

### 3.3 Validation du questionnaire

* L'action « Valider le questionnaire » exige au moins **1 question** et une configuration valide pour chacune (ex. : au moins 2 options pour une question à choix).
* Chaque condition d'affichage doit être valide (§3.5) : question source existante et située avant, opérateur compatible avec son type, valeur cohérente (ex. : option toujours présente dans la question source).
* Le questionnaire passe alors en `VALIDATED` et le créateur est redirigé vers la configuration de la diffusion.

### 3.4 Sections et formulaire en étapes (stepper)

Comme dans Google Forms, le créateur peut **découper son questionnaire en sections**. Cette fonctionnalité est **optionnelle** : sans sections, le formulaire reste une page unique.

| Élément | Règle |
| :--- | :--- |
| Activation | Action « Ajouter une section » dans le builder. Le formulaire ne devient un parcours par étapes (**stepper**) **que s'il compte au moins 2 sections**. |
| Section | `title` (obligatoire, 3 à 150 caractères), `description` (optionnelle), `position` (entier ≥ 1) |
| Contenu | Chaque question appartient à exactement une section. Au moins **1 question** par section. |
| Réorganisation | Drag-and-drop des sections entre elles et des questions au sein d'une section ou **d'une section à l'autre**, dans le respect de l'ordre des conditions d'affichage (§3.5). |
| Passage à 1 seule section | Si le créateur supprime des sections jusqu'à n'en garder qu'une, le formulaire redevient une page unique. |
| Validation du questionnaire | En plus des règles du §3.3 : chaque section contient au moins 1 question. |
| Immutabilité | Les sections sont figées dès `VALIDATED` (ajout, suppression, réordonnancement, contenu), comme les questions. |

Le rendu côté participant est décrit au §6.4.

### 3.5 Affichage conditionnel (`displayCondition`)

Une question peut n'être affichée qu'en fonction de la réponse donnée à une question **précédente** (sa question *source*, ou *parente*). Sans condition, la question est toujours affichée.

**Cas d'usage couverts**

| Source | Exemple | Condition |
| :--- | :--- | :--- |
| `CHOICE` | Q1 « Civilité » → si `Femme`, afficher Q2 « Nom de jeune fille » | `EQUALS` l'option `Femme` |
| `CHOICE` → `TEXT` (« Autre ») | Q1 « Comment nous avez-vous connus ? » → si `Autre`, afficher Q2 « Précisez » | `EQUALS` l'option `Autre` |
| `BOOLEAN` | Q1 « Avez-vous plus de 18 ans ? » → si Oui, afficher les questions suivantes | `EQUALS` `true` |
| `BOOLEAN` | Q1 « Viendrez-vous accompagné(e) ? » → si Oui, afficher Q2 « Nombre d'accompagnants » | `EQUALS` `true` |
| `RANGE` | Q2 « Votre note de 1 à 10 » → si inférieure à 5, afficher Q3 « Comment pourrait-on améliorer l'expérience ? » | `LESS_THAN` `5` |
| `NUMBER` | Q1 « Nombre de participants » → si 2 ou plus, afficher Q2 « Noms des accompagnants » | `GREATER_OR_EQUAL` `2` |

**Sources autorisées (règle de conception).** Une question ne peut être conditionnée **que** par une question :
* **`BOOLEAN`** (Oui / Non) ;
* **`CHOICE` à choix unique** : boutons radio (`radio`) ou liste déroulante (`select`) ;
* **`NUMBER`** (Chiffres) ;
* **`RANGE`** (Échelle).

Une question `TEXT`, `DATE`, `FILE` ou `CHOICE` à choix multiple (`checkbox`) ne peut **jamais** servir de source. Le builder ne les propose pas ; l'API refuse une telle condition (422 à l'enregistrement de la structure, erreur bloquante à la validation). Si une question source change de type ou passe en choix multiple, les conditions qui en dépendaient sont retirées.

**Structure d'une condition** : une seule condition par question (pas de combinaison ET / OU).

| Élément | Règle |
| :--- | :--- |
| Question source | Une question `BOOLEAN`, `CHOICE` à choix unique, `NUMBER` ou `RANGE` du même questionnaire, de position **strictement inférieure** (une question en position 1 ne peut donc pas être conditionnelle) |
| Opérateur | Selon le type de la source (tableau ci-dessous) |
| Valeur | Identifiant d'option (`CHOICE`), liste d'identifiants (`IN`), booléen (`BOOLEAN`), nombre (`NUMBER`, `RANGE`) |

| Type de la source | Opérateurs autorisés | Sens |
| :--- | :--- | :--- |
| `CHOICE` à choix unique (`radio`, `select`) | `EQUALS`, `NOT_EQUALS`, `IN` | `EQUALS` (« est ») : l'option choisie est celle-ci ; `NOT_EQUALS` (« n'est pas ») : une autre option est choisie ; `IN` (« est l'une de ») : l'option choisie fait partie de la liste |
| `BOOLEAN` | `EQUALS`, `NOT_EQUALS` | comparaison avec `true` / `false` (libellés « Oui » / « Non » dans le builder) |
| `NUMBER`, `RANGE` | `EQUALS`, `NOT_EQUALS`, `LESS_THAN`, `LESS_OR_EQUAL`, `GREATER_THAN`, `GREATER_OR_EQUAL` | comparaison numérique (« est égal à », « est différent de », « est inférieur à », « est inférieur ou égal à », « est supérieur à », « est supérieur ou égal à ») ; pour une `RANGE`, la valeur se choisit parmi les crans de l'échelle |
| `CHOICE` à choix multiple, `TEXT`, `DATE`, `FILE` | — | ne peuvent pas servir de source |

**Évaluation**
* Une condition est évaluée à partir des réponses du participant, dans l'ordre des positions.
* Si la question source **n'a pas de réponse** (facultative laissée vide, ou elle-même masquée), la condition est **fausse**, quel que soit l'opérateur : la question dépendante est masquée. Le masquage se propage donc en cascade.

**Invariants**
1. **Dépendance unidirectionnelle stricte** : `source.position < question.position`, en permanence.
   * Glisser-déposer : si un déplacement placerait une question conditionnelle au-dessus de sa source (ou la source en dessous de la question), le builder demande confirmation et **retire la condition**, ou annule le déplacement. Une structure enregistrée qui viole la règle est refusée par l'API.
   * La règle s'applique aussi entre sections : la source peut se trouver dans une section précédente.
2. **Suppression de la source** : supprimer une question référencée retire automatiquement la condition des questions qui en dépendaient ; elles redeviennent toujours affichées.
3. **Modification de la source** (en `DRAFT`) : si l'option ou le type visé disparaît, la condition devient invalide ; elle est signalée dans le builder et bloque la validation (§3.3).
4. **Obligation dynamique** : une question `required` et conditionnelle n'est obligatoire que si sa condition est vraie. Masquée, elle n'exige aucune réponse et toute valeur envoyée pour elle est **ignorée** (jamais enregistrée).
5. **Immutabilité** : la condition est figée dès `VALIDATED` (§2).

**Builder** : action « Afficher cette question uniquement si… » sur chaque question (sauf la première) : choix de la question source parmi les questions précédentes, de l'opérateur proposé selon son type, puis de la valeur (liste des options pour un `CHOICE`, libellés vrai/faux pour un `BOOLEAN`). Les cartes conditionnelles portent un badge « Conditionnelle — si Q{n} … ».

**Section conditionnelle.** Une section entière peut dépendre d'une question, avec les mêmes sources, opérateurs et valeurs. Exemple : Q12 « Souhaitez-vous être recontacté(e) ? » → section « Vos coordonnées » (nom, prénom, e-mail, téléphone) si Oui.
* La source appartient à une **section précédente** : la première section est toujours affichée, et une section ne peut pas dépendre de ses propres questions. Une source déplacée dans la section ou après elle fait retirer la condition (confirmation lors d'un glisser-déposer, comme pour une question).
* Condition fausse : la section est **sautée**. Elle disparaît du stepper (les étapes sont renumérotées) et toutes ses questions sont masquées, avec les mêmes effets que pour une question masquée (invariant 4) : non obligatoires, réponses ignorées.
* Une question d'une section conditionnelle peut avoir sa propre condition : elle n'est affichée que si les deux sont vraies.
* Supprimer la source, ou changer son type, retire la condition de la section. Supprimer une section dont les questions servaient de source fait de même si ces questions passent dans la section conditionnelle.
* Builder : « Afficher cette section uniquement si… » sous le titre et la description de la section (à partir de la 2ᵉ), badge « Si Q{n} … » dans l'en-tête de la section.

Le rendu côté participant est décrit au §6.4, les statistiques au §7.2.

---

## 4. Les 7 types de questions

Le système gère exactement sept types : `TEXT`, `NUMBER`, `CHOICE`, `DATE`, `FILE`, `BOOLEAN`, `RANGE`.

### 4.1 `TEXT` — Zone de texte

**Rendu :** champ court (`input:text`) ou zone longue (`textarea`).

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `multiline` | booléen | `false` = `input:text`, `true` = `textarea` |
| `minLength` | entier ≥ 0, optionnel | Longueur minimale |
| `maxLength` | entier, optionnel | Ex. : 255 (court), 5000 (long) |
| `placeholder` | texte, optionnel | Texte indicatif |

**Validation :** `trim` début/fin, contrôle des longueurs min/max.
**Statistiques :** nombre de réponses, longueur moyenne, liste paginée des verbatims avec recherche par mot-clé.

### 4.2 `NUMBER` — Chiffres

**Rendu :** champ numérique optimisé mobile et desktop, acceptant `.` et `,` comme séparateur décimal.

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `allowDecimal` | booléen | `false` = entier uniquement |
| `min` | nombre, optionnel | Valeur minimale |
| `max` | nombre, optionnel | Valeur maximale |
| `step` | nombre, optionnel | Pas d'incrémentation suggéré |
| `unit` | texte, optionnel | Unité affichée (`€`, `kg`, `%`, `ans`…) |

**Validation :**
* Normalisation de la virgule en point avant analyse.
* Rejet si ce n'est pas un nombre valide, ou si `allowDecimal = false` avec partie fractionnaire.
* Intervalle strict : `min <= valeur <= max`.

**Statistiques :** minimum, maximum, moyenne, médiane, écart-type, histogramme par tranches.

### 4.3 `CHOICE` — Choix

**Rendu :** boutons radio (sélection unique), cases à cocher (multiple) ou liste déroulante (unique compacte).

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `widget` | `'radio' \| 'checkbox' \| 'select'` | Variante d'affichage |
| `multiple` | booléen | `true` si `checkbox`, `false` si `radio` ou `select` |
| `options` | liste ordonnée `{ id, label }` | Minimum 2 options |

**Builder :** une nouvelle question à choix démarre avec deux options **vides** (« Option 1 », « Option 2 » ne sont que des placeholders).

**Recherche dans les réponses (au moins 10 options) :**
* `radio` / `checkbox` : un champ « Rechercher parmi N réponses… » filtre la liste (sans tenir compte de la casse ni des accents) ; les réponses déjà cochées restent visibles ; le nombre de réponses affichées est annoncé.
* `select` : la liste déroulante native est remplacée par une **saisie semi-automatique** (motif ARIA combobox) : on tape quelques lettres, la liste se filtre, flèches + Entrée pour choisir, Échap pour refermer, bouton pour effacer.
* Le builder l'indique sous la liste des options.

**Aide par défaut (choix multiple) :** sans texte d'aide saisi par le créateur, une question `checkbox` affiche l'encart d'aide « Plusieurs réponses possibles. ». Le texte d'aide du créateur, s'il existe, le remplace.

**Validation :**
* Chaque identifiant sélectionné doit appartenir aux options de la question.
* Si `multiple = false`, au plus 1 option sélectionnée.

**Statistiques :** décompte absolu et pourcentage par option (base : répondants à la question), barres horizontales triables.

### 4.4 `DATE` — Date

**Rendu :** sélecteur de date (`input:date`).

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `minDate` | `YYYY-MM-DD`, optionnel | Date minimale |
| `maxDate` | `YYYY-MM-DD`, optionnel | Date maximale |

**Validation :** format calendrier valide, `minDate <= date <= maxDate`.
**Statistiques :** date la plus ancienne, la plus récente, médiane, distribution chronologique (regroupement jour/mois).

### 4.5 `FILE` — Fichier

**Rendu :** zone de dépôt par glisser-déposer + explorateur de fichiers, avec progression et contraintes affichées.

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `allowedMimeTypes` | liste | Ex. : `application/pdf`, `image/jpeg`, `image/png`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| `maxSizeMb` | entier | Poids max par fichier (ex. : `10`). **Plafonné par la limite globale de la plateforme** (variable d'environnement `UPLOAD_MAX_SIZE_MB`) : taille effective = `min(maxSizeMb, limite globale)` |
| `maxFiles` | entier | Nombre max de fichiers (ex. : `1` à `5`) |

**Validation :** type MIME **réel** (pas seulement l'extension), poids maximal, quota de fichiers.

**Information dans le builder.** Dès qu'une question `FILE` est ajoutée ou éditée, une box d'information (même composant que l'aide contextuelle) rappelle la limite de la plateforme :

| Situation | Type de box | Titre | Texte |
| :--- | :--- | :--- | :--- |
| `maxSizeMb` ≤ limite globale (ou vide) | **Info** | Envoi de fichiers | « Les participants pourront déposer des fichiers. La taille maximale acceptée par kwesto est de **{limite} Mo par fichier**. Vous pouvez fixer une limite plus basse pour cette question. » |
| `maxSizeMb` > limite globale | **Warning** | Limite dépassée | « La taille saisie ({valeur} Mo) dépasse la limite de la plateforme. Une limite de **{limite} Mo par fichier** sera appliquée à cette question. » |

La valeur `{limite}` est lue depuis la configuration serveur (jamais codée en dur). Côté participant, la taille effective est affichée près de la zone de dépôt.
**Statistiques :** nombre total de fichiers, poids cumulé, répartition par format, galerie de prévisualisation, **téléchargement groupé `.zip`**.

### 4.6 `BOOLEAN` — Booléen

**Objectif :** un choix binaire (ex. : « J'ai plus de 18 ans ? », « Je suis… Intéressé / Pas intéressé ») plus rapide à configurer et plus ergonomique qu'un `CHOICE` à deux options.

**Rendu (`widget`) :**
* `buttons` (défaut) : deux boutons / cartes tactiles mutuellement exclusifs (boutons radio), affichant `trueLabel` et `falseLabel`, pleine largeur sur mobile ;
* `switch` : un **interrupteur** ; le libellé de la valeur courante est affiché à côté (ex. « Êtes-vous satisfait ? » [ ] Non → [x] Oui).

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `preset` | `'YES_NO' \| 'TRUE_FALSE' \| 'CUSTOM'` | Modèle de libellés ; `YES_NO` par défaut |
| `trueLabel` | texte, 1 à 80 car. | Libellé de la valeur `true` (obligatoire) |
| `falseLabel` | texte, 1 à 80 car. | Libellé de la valeur `false` (obligatoire), différent de `trueLabel` |
| `widget` | `'buttons' \| 'switch'` | Rendu ; `buttons` par défaut |
| `defaultValue` | `true \| false \| null` | Réponse **présélectionnée** à l'ouverture du formulaire (ex. « Non »), `null` = aucune. Un interrupteur a toujours une valeur : `false` si rien n'est choisi. Une valeur par défaut compte comme une réponse (progression, obligation) ; elle est envoyée si la question est visible. |

**Presets dans le builder**

| Preset | `trueLabel` | `falseLabel` |
| :--- | :--- | :--- |
| `YES_NO` (par défaut) | Oui | Non |
| `TRUE_FALSE` | Vrai | Faux |
| `CUSTOM` | libre (ex. : Intéressé, J'accepte) | libre (ex. : Pas intéressé, Je refuse) |

Choisir un preset pré-remplit les deux libellés ; ceux-ci restent toujours modifiables, et toute modification manuelle qui s'écarte du preset le fait passer à `CUSTOM`.

**Validation :** la réponse est normalisée en booléen natif (`true` / `false`) ; toute autre valeur est rejetée. Une question facultative laissée sans réponse n'équivaut **pas** à `false`.

**Statistiques :** effectif et pourcentage de chaque valeur (`trueCount` / `falseCount`, base : répondants à la question), avec une barre comparative affichant les deux libellés.

### 4.7 `RANGE` — Échelle (curseur)

**Objectif :** une note ou une intensité sur une échelle (« Recommanderiez-vous ce service ? » de 0 à 10), plus rapide qu'un `NUMBER` et plus parlant visuellement.

**Rendu :** curseur (`Slider` de `core-react`, `<input type="range">` natif : clavier, lecteurs d'écran, tactile), valeur affichée en grand (« 8/10 »), libellés des extrémités sous la piste (« Pas du tout » / « Absolument »).

| Paramètre | Type | Description |
| :--- | :--- | :--- |
| `min` / `max` / `step` | nombres | **Intervalle régulier** (défaut 0 à 10, pas de 1). `max - min` multiple du pas, 1000 crans au plus |
| `values` | liste de nombres, optionnelle | **Valeurs personnalisées** (ex. 2, 4, 6, 22, 40, 79) : triées, sans doublon, au moins 2. Le curseur avance d'une valeur à la suivante ; `min` / `max` en sont déduits, le pas est ignoré |
| `minLabel` / `maxLabel` | texte, 60 car. | Libellés des extrémités |
| `unit` | texte, 12 car. | Suffixe affiché (« /10 » collé, « % » ou « € » après une espace) |
| `defaultValue` | nombre ou `null` | Position de départ, **comptée comme réponse**. `null` : curseur au milieu, grisé, « Déplacez le curseur » ; le participant doit le déplacer (ou cliquer dessus) pour répondre |

**Builder :** choix « Intervalle régulier » / « Valeurs personnalisées », aperçu manipulable du curseur, contrôles signalés en direct.

**Validation :** la réponse doit être une valeur de l'échelle (bornes incluses et alignée sur le pas, ou présente dans la liste personnalisée).

**Statistiques :** moyenne, médiane, minimum, maximum ; répartition cran par cran (échelle de 21 crans au plus), histogramme par tranches au-delà. Export : la valeur numérique.

---

## 5. Module 2 — Liste de diffusion et planification

Accessible uniquement à l'état `VALIDATED`.

### 5.1 Saisie de l'audience
* Ajout manuel d'e-mails (multi-lignes ou tags/pastilles) ou import par copier-coller / CSV.
* Nettoyage automatique : minuscules, suppression des espaces, validation syntaxique RFC 5322, **dédoublonnage** (une adresse = une seule fois par questionnaire).

### 5.2 Date limite de réponse (`expiresAt`)
Obligatoire. Date et heure exactes de clôture automatique, strictement postérieures à l'instant de lancement.

### 5.3 Lancement de la campagne
1. Génération, pour chaque destinataire, d'un jeton unique (`token` de 64 caractères hexadécimaux ou UUID v4).
2. Passage du questionnaire à `ACTIVE`.
3. Envoi asynchrone des e-mails d'invitation personnalisés.

### 5.4 Suivi unitaire des destinataires

| Statut | Signification |
| :--- | :--- |
| `PENDING` | Invitation en file d'attente |
| `SENT` | E-mail d'invitation expédié |
| `OPENED` | Lien `/s/{token}` ouvert au moins une fois |
| `ANSWERED` | Réponses validées et soumises |
| `BOUNCED` | Adresse invalide / rejet définitif du serveur mail |

---

## 6. Module 3 — Interface participant, unicité et télémétrie

### 6.1 Contrôle d'accès à l'ouverture de `/s/{token}`

Le lien personnel `https://app.{domaine}/s/{token}` est servi par l'application back-office (voir [specs_tech §6](./specs_tech.prompt.md#6-domaines-et-politique-dindexation)). À l'ouverture, les cas sont évalués dans cet ordre :

| # | Condition | Résultat |
| :--- | :--- | :--- |
| 1 | Jeton inexistant | Page d'erreur « Lien invalide » |
| 2 | `CLOSED` ou `now >= expiresAt` | « Ce questionnaire est désormais clôturé. Les réponses ne sont plus acceptées. » |
| 3 | `recipient.status === 'ANSWERED'` | « Vous avez déjà répondu à ce questionnaire le {date}. Une seule participation est autorisée. » (formulaire totalement masqué) |
| 4 | Accès valide (`SENT` ou `OPENED`) | Formulaire affiché, questions ordonnées par `position`, avec leur encart d'aide contextuelle éventuel (voir §3.2) ; les questions conditionnelles dont la condition est fausse sont masquées (§3.5). À la première ouverture : statut `OPENED` + `openedAt` horodaté. |

### 6.2 Soumission atomique et anti-doublon
* Les conditions d'affichage sont **réévaluées par le serveur** à partir des réponses reçues, avant tout contrôle : une question masquée n'est ni obligatoire ni validée, et sa valeur éventuelle est ignorée (§3.5).
* L'ensemble des réponses est validé et enregistré en **une seule opération atomique**. Le brouillon éventuel (§6.5) n'est jamais une réponse : il est ignoré par la validation et effacé à l'envoi.
* Le destinataire passe immédiatement à `ANSWERED` (`submittedAt` horodaté), ce qui rend toute seconde soumission impossible (rafraîchissement, onglet parallèle, rejeu HTTP).

### 6.3 Télémétrie (à la soumission, sur la fiche du destinataire)

| Donnée | Champ / détail |
| :--- | :--- |
| Adresse IP | Résolue correctement derrière un reverse-proxy |
| User-Agent brut | `userAgentRaw` |
| Navigateur | `browserName` (Chrome, Edge, Safari, Firefox…) |
| Version majeure | `browserVersion` |
| Système | `osName` (Windows, macOS, iOS, Android, Linux…) |
| Appareil | `deviceType` : `desktop`, `mobile`, `tablet` |
| Durée de complétion | `submittedAt - openedAt` |

### 6.4 Parcours de saisie : affichage, barre de progression et stepper

**Mode d'affichage (réglage du questionnaire, `displayMode`).** Choisi par le créateur dans le builder (« Affichage pour le participant »), modifiable tant que la campagne n'est pas lancée (`DRAFT` ou `VALIDATED`) : c'est de la présentation, hors structure verrouillée. Conservé par la duplication.

| Mode | Rendu |
| :--- | :--- |
| `LIST` (défaut) | **Liste** : toutes les questions de l'écran en même temps. Sans stepper, un seul écran ; avec stepper, un écran par section. |
| `ONE_BY_ONE` | **Question par question** : une question par écran, « Précédent » / « Suivant ». Avec stepper, celui-ci indique la section de la question affichée ; titre et description de section en tête de la première question de la section. |

Le mode d'affichage et le stepper sont **indépendants** : le stepper ne dépend que du nombre de sections (≥ 2), jamais du mode.

**Nombre de questions et durée estimée (options du questionnaire).** Sous le titre et la description du premier écran : « Durée estimée : 3 à 5 minutes · 12 questions ». Le **nombre de questions est toujours affiché** ; la **durée estimée est facultative** : option « Afficher la durée estimée » (`showDuration`, activée par défaut) dans le bloc « Options du questionnaire » du builder, modifiable tant que la campagne n'est pas lancée. Sans la durée, seul « 12 questions » est affiché. Estimation faite par `forms_bo` (temps moyen par type de question + lecture de l'intitulé et de l'aide) ; avec des questions conditionnelles, nombre de questions et durée sont des fourchettes (« 8 à 12 questions »). Au-delà de 10 minutes estimées, le builder suggère de masquer la durée pour ne pas décourager les participants.

**Bandeau de progression.** Le stepper (s'il existe) puis la barre de progression forment un bandeau placé **tout en haut, juste sous la barre de navigation**, et qui reste collé en haut de l'écran pendant le défilement (mobile compris). Le titre et la description du questionnaire s'affichent sous ce bandeau, sur le premier écran.

**Barre de progression (tous les formulaires, y compris en liste).** Pour renforcer le sentiment d'avancement, une barre de progression est **toujours affichée**, avec un libellé et un pourcentage lisibles (ex. : « 40 % »).

| Formulaire | Calcul de la progression | Libellé |
| :--- | :--- | :--- |
| Liste sans sections (un seul écran) | Questions renseignées / nombre de questions **affichées**, mise à jour en direct à chaque réponse | « 3 questions renseignées sur 8 » |
| Liste avec sections (stepper) | Étape courante / nombre d'étapes **affichées** (ex. : étape 2 sur 4 = 50 %) ; atteint 100 % à la dernière étape | « Étape 2 sur 4 » |
| Question par question | Question courante / nombre de questions **affichées** ; 100 % à la dernière | « Question 3 sur 8 » |

Accessibilité : `role="progressbar"` avec `aria-valuenow`, `aria-valuemin`, `aria-valuemax`.

**Saisie au clavier.** Entrée dans un champ passe à l'écran suivant ; elle n'envoie **jamais** le questionnaire (seul le bouton « Envoyer » le fait).

**Questions conditionnelles (§3.5).**
* Les conditions sont évaluées **en temps réel** à chaque changement de réponse : une question dépendante apparaît ou disparaît immédiatement, sans rechargement.
* Quand une question redevient masquée, sa valeur saisie est **effacée** (fichiers déposés compris) ; elle n'est jamais envoyée.
* L'apparition d'une question est annoncée aux technologies d'assistance (région `aria-live` polie) ; la numérotation affichée reste celle du builder.
* En mode stepper, une étape dont **toutes** les questions sont masquées est sautée et n'est pas comptée dans le nombre d'étapes.

**Stepper (uniquement si le questionnaire compte au moins 2 sections, voir §3.4).** Aucune autre règle ne fait apparaître de stepper.
* Un item par section : son **numéro est la position de la section** (1, 2, 3, 4…) et son **libellé est le titre de la section** ; la section en cours est mise en évidence (`aria-current="step"`).
* Sur mobile, le stepper tient sur une ligne qui défile horizontalement : numéros de toutes les sections, titre de la section en cours seulement (les autres titres restent lus par les lecteurs d'écran).
* En liste, le formulaire devient un **tunnel** : une section par étape.
* Boutons « Précédent » / « Suivant ». Le bouton « Suivant » valide d'abord les questions de l'étape (obligatoires, formats, contraintes) et bloque tant que des erreurs subsistent ; « Précédent » n'exige aucune validation.
* Les réponses saisies sont **conservées lors de la navigation** entre étapes.
* Le bouton « Envoyer » n'apparaît qu'à la **dernière étape**. La soumission reste **atomique** (§6.2) : aucune réponse n'est enregistrée avant l'envoi final (seul un brouillon l'est, §6.5), et l'unicité du jeton est inchangée.
* La description de la section est affichée en tête d'étape.

### 6.5 Brouillon : reprendre plus tard

Chaque participant a son propre lien : ses réponses en cours sont enregistrées au fil de la saisie, pour qu'il puisse quitter et revenir sans tout ressaisir (moins de friction, moins d'abandons).

* **Enregistrement automatique** (`PUT /api/public/surveys/{token}/draft`), environ 1 s après la dernière modification et à chaque changement d'écran : réponses telles que saisies (questions visibles uniquement, **sans validation métier**), plus la première question de l'écran courant. Stockage JSON sur la fiche du destinataire (`draftAnswers`, `draftQuestionId`, `draftSavedAt`). Un discret « Réponses enregistrées à 14:32 » le confirme ; un échec n'empêche jamais de répondre.
* **Au retour** (lien rouvert, brouillon non vide) : écran « Vous avez commencé ce questionnaire le … (N réponses déjà saisies) » avec deux choix :
  * **Reprendre où j'en étais** : réponses restaurées, reprise sur l'écran où le participant s'était arrêté ;
  * **Recommencer** (`DELETE …/draft`) : brouillon **et fichiers déjà déposés** effacés, retour au premier écran.
* **Confidentialité** : le brouillon n'est jamais visible du créateur, ni compté dans les statistiques ou les exports. Il est effacé à l'envoi (passage à `ANSWERED`), quand le participant recommence, et à la **clôture** du questionnaire.
* Une fois le questionnaire envoyé ou clôturé, le lien ne permet plus d'enregistrer de brouillon (403).

---

## 7. Module 4 — Clôture automatique et statistiques consolidées

### 7.1 Déclencheurs de clôture
Un questionnaire `ACTIVE` passe à `CLOSED` dès que :
1. **`ALL_ANSWERED`** — juste après une soumission, si `nb ANSWERED === nb destinataires valides (hors BOUNCED)`.
2. **`DEADLINE_REACHED`** — dès que `now >= expiresAt` (détecté par tâche planifiée haute fréquence).

### 7.2 Tableau de bord statistique

**Indicateurs globaux**
* Taux de participation (`répondants / invités valides`, en % et en valeur absolue).
* Taux d'ouverture sans soumission (`OPENED` non `ANSWERED`).
* Temps moyen de remplissage.
* Répartition technique des répondants (navigateurs, OS, mobile vs desktop).

**Analyse question par question :** rendu adapté à chacun des 7 types ([§4](#4-les-7-types-de-questions)).

**Questions conditionnelles (§3.5)**
* Le taux de réponse d'une question conditionnelle est calculé sur les **répondants éligibles** (ceux pour qui la condition était vraie, donc qui ont vu la question), et non sur l'ensemble des répondants.
* Chaque question conditionnelle affiche « Affichée à X répondants » et rappelle sa condition (« si Q{n} … »).
* Les pourcentages par valeur (options, booléen) gardent pour base les répondants à la question.

**Recherche plein texte**
* Recherche par mot-clé, triée par pertinence, sur : les questionnaires (titre, description), les questions (intitulé, aide), les destinataires (adresse e-mail) et les verbatims des questions `TEXT`.
* Disponible dans le dashboard (via l'API) et, pour les contenus publics éventuels, sur le site vitrine. Détails techniques : [specs_tech §3.5](./specs_tech.prompt.md#35-recherche-plein-texte-index-fulltext--extension-dql).

**Exports**
* CSV/Excel des réponses brutes (une ligne par destinataire, avec télémétrie). Une question masquée pour un destinataire laisse sa cellule vide ; une réponse `BOOLEAN` est exportée avec son libellé (`trueLabel` / `falseLabel`).
* Archive `.zip` consolidée pour les questions `FILE`.

---

## 8. Catalogue des e-mails transactionnels

5 e-mails principaux + 1 alerte de délivrabilité.

| Code | Destinataire | Déclencheur | Contenu |
| :--- | :--- | :--- | :--- |
| `SURVEY_INVITATION` | Participant | Lancement de la campagne (`ACTIVE`) | Titre, description, nombre de questions, lien `/s/{token}`, date limite |
| `SURVEY_REMINDER` | Participant non-répondant | Tâche planifiée, fenêtre **J-5 à J-4 avant `expiresAt`**, si taux de réponse **≤ 20 %** et sondage lancé depuis **≥ 48 h**. Uniquement `SENT` ou `OPENED`, **une seule fois par campagne** | Rappel de l'échéance + lien `/s/{token}` |
| `SURVEY_RECEIPT` | Participant | Immédiatement après validation (`ANSWERED`) | Accusé de réception horodaté, enregistrement définitif, lien verrouillé |
| `CREATOR_LOW_RESPONSE` | Créateur | En même temps que les `SURVEY_REMINDER` | Alerte de faible participation, nombre de relances envoyées, lien vers le suivi des destinataires |
| `CREATOR_SURVEY_CLOSED` | Créateur | Passage à `CLOSED` (100 % ou date atteinte) | Motif de clôture, taux final, lien vers les statistiques |
| `CREATOR_BOUNCE_ALERT` *(optionnel, recommandé)* | Créateur | Rejet SMTP définitif (`BOUNCED`) | Adresse invalide signalée, exclue du calcul des 100 % |

---

## 9. Tâches automatisées (crons & maintenance)

### 9.1 Cycle de vie (haute fréquence)

| Fréquence | Tâche | Détail |
| :--- | :--- | :--- |
| Toutes les 5 min | Clôture des sondages expirés | Clôture les `ACTIVE` avec `expiresAt` dépassé, envoie `CREATOR_SURVEY_CLOSED` |
| Toutes les heures, 08h00–20h00 | Relance faible participation J-4/J-5 | Fenêtre `[expiresAt - 5j, expiresAt - 4j]`, `taux <= 20 %`, `activatedAt <= now - 48h`, aucune relance antérieure → `SURVEY_REMINDER` + `CREATOR_LOW_RESPONSE` |
| Toutes les 15 min | Rattrapage SMTP | Réessaie les envois échoués temporairement, marque les rebonds définitifs `BOUNCED` |

### 9.2 Maintenance nocturne (02h00–04h00)

| Heure | Tâche | Détail |
| :--- | :--- | :--- |
| 02h00 | Purge des fichiers temporaires orphelins | Supprime (stockage + base) les fichiers téléversés mais jamais rattachés à une soumission validée depuis > 24 h |
| 02h30 | Anonymisation RGPD de la télémétrie | Sondages `CLOSED` depuis > 30 jours : IP tronquée ou hachée de façon irréversible, statistiques agrégées conservées (navigateur, OS, appareil) |
| 03h00 | Pré-génération des archives ZIP | Archive des pièces jointes des questionnaires avec questions `FILE` clôturés dans les dernières 24 h |
| 03h30 | Recalcul des instantanés statistiques | Pré-calcul et cache des indicateurs lourds (médianes, écarts-types, distributions) des sondages actifs volumineux |
| 04h00 (hebdomadaire) | Nettoyage technique | Purge des `DRAFT` inactifs depuis > 60 jours, rotation/archivage des anciens journaux |

---

## 10. Espace Administration (`ROLE_ADMIN`)

Section **Administration** du dashboard, visible uniquement avec `ROLE_ADMIN`.

### 10.1 Prévisualisation des templates d'e-mails
* Liste tous les templates enregistrés : les 5+1 templates métier et les templates prédéfinis issus de `core-php`.
* Rendu HTML exact (desktop et mobile) avec jeu de données réalistes pré-rempli (ou personnalisable).
* Envoi d'un e-mail de test vers l'adresse de l'administrateur connecté.

### 10.2 Prévisualisation et inspection des logs
* Affiche les journaux applicatifs (canaux `mailer`, `cron`, `app`, `security`) dans l'interface, sans accès SSH.
* Filtres : canal, sévérité (`INFO`, `WARNING`, `ERROR`, `CRITICAL`), date, recherche textuelle (token, ID de sondage, e-mail en échec…).

---

## 11. Site vitrine public

Site marketing public, distinct de l'application métier, dédié à l'**acquisition organique (SEO)** et à la présentation du produit. Il ne contient aucune règle métier de questionnaire : les modules 1 à 4 restent inchangés. Architecture : [`specs_tech.prompt.md`, §5](./specs_tech.prompt.md#5-site-vitrine-appsforms_front).

### 11.1 Objectifs
* Expliquer la proposition de valeur et attirer du trafic via les moteurs de recherche.
* Convertir les visiteurs vers l'application (CTA vers le back-office).
* Offrir un point de contact et les pages légales / RGPD.

### 11.2 Pages

| Route | Contenu |
| :--- | :--- |
| `/` | Accueil : proposition de valeur, présentation des 3 étapes, aperçu visuel, CTA vers l'application |
| `/fonctionnalites` | Détail des 7 types de questions et de l'affichage conditionnel, du contrôle par lien unique, des relances automatiques et des statistiques consolidées |
| `/cas-usage` | Pages dédiées par cas d'usage : enquêtes internes, collecte de dossiers / pièces jointes, évaluations post-formation |
| `/tarifs` | Grille des offres et FAQ |
| `/contact` | Formulaire de contact |
| `/mentions-legales` | Mentions légales |
| `/confidentialite` | Politique de confidentialité et RGPD |

### 11.3 Interactions
Interactions légères côté navigateur, sans application React et sans Stimulus / Symfony UX / AssetMapper (voir [specs_tech §5.1.1](./specs_tech.prompt.md#511-exclusions-obligatoires-forms_front)) : navigation mobile, accordéons de FAQ, onglets de démonstration des types de questions, gestion du presse-papiers et du consentement.

### 11.4 Référencement
* Chaque page expose un titre, une meta description, une URL canonique, des balises OpenGraph / Twitter Cards.
* Données structurées JSON-LD : `SoftwareApplication` et `FAQPage`.
* `/sitemap.xml` (toutes les pages publiques, avec date de dernière modification et priorité) et `/robots.txt`.
* Le site vitrine est indexable ; l'application et l'API ne le sont jamais (`noindex, nofollow`).
