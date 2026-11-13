# Spécifications Fonctionnelles — Plateforme de Questionnaires & Sondages par Invitation

> **Contexte d'utilisation :** référence fonctionnelle complète du produit. Toute implémentation (API Symfony ou interface React) doit respecter strictement les règles métier, états, flux et permissions décrits ci-dessous.
> Document compagnon : [`specs_tech.prompt.md`](./specs_tech.prompt.md).

## Table des matières

1. [Vision du produit et rôles](#1-vision-du-produit-et-rôles)
2. [Cycle de vie d'un questionnaire](#2-cycle-de-vie-dun-questionnaire)
3. [Module 1 — Conception du questionnaire](#3-module-1--conception-du-questionnaire-builder)
4. [Les 5 types de questions](#4-les-5-types-de-questions)
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

Dès `VALIDATED`, il est impossible d'ajouter, supprimer, réordonner ou modifier le type et les règles de validation d'une question. Cela garantit l'intégrité mathématique des statistiques consolidées.

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
| `label` | texte | Obligatoire |
| `helpTitle` | texte, max 80 car. | Optionnel. Titre de l'encart d'aide (ex. : « À savoir »). Ignoré si `helpText` est vide |
| `helpText` | texte | Optionnel. Texte d'aide / précision explicative (voir « Aide contextuelle » ci-dessous) |
| `required` | booléen | `true` par défaut |
| `position` | entier | ≥ 1, séquentiel, démarre à `1` |

**Aide contextuelle**

Une question peut afficher une aide pour préciser son sens (ex. : « Qu'est-ce que vous pensez de la … ? » suivi d'une explication). Il n'existe **qu'un seul mode d'affichage** : un encart (box) d'information, affiché en permanence sous l'intitulé de la question.

| Élément | Règle |
| :--- | :--- |
| Icône | Icône « info » (ⓘ), toujours présente |
| Titre | `helpTitle`, optionnel, en gras |
| Texte | `helpText`, texte simple (paragraphes, retours à la ligne), pas de HTML arbitraire |
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
* Le questionnaire passe alors en `VALIDATED` et le créateur est redirigé vers la configuration de la diffusion.

### 3.4 Sections et formulaire en étapes (stepper)

Comme dans Google Forms, le créateur peut **découper son questionnaire en sections**. Cette fonctionnalité est **optionnelle** : sans sections, le formulaire reste une page unique.

| Élément | Règle |
| :--- | :--- |
| Activation | Action « Ajouter une section » dans le builder. Le formulaire ne devient un parcours par étapes (**stepper**) **que s'il compte au moins 2 sections**. |
| Section | `title` (obligatoire, 3 à 150 caractères), `description` (optionnelle), `position` (entier ≥ 1) |
| Contenu | Chaque question appartient à exactement une section. Au moins **1 question** par section. |
| Réorganisation | Drag-and-drop des sections entre elles et des questions au sein d'une section ou **d'une section à l'autre**. |
| Passage à 1 seule section | Si le créateur supprime des sections jusqu'à n'en garder qu'une, le formulaire redevient une page unique. |
| Validation du questionnaire | En plus des règles du §3.3 : chaque section contient au moins 1 question. |
| Immutabilité | Les sections sont figées dès `VALIDATED` (ajout, suppression, réordonnancement, contenu), comme les questions. |

Le rendu côté participant est décrit au §6.4.

---

## 4. Les 5 types de questions

Le système gère exactement cinq types : `TEXT`, `NUMBER`, `CHOICE`, `DATE`, `FILE`.

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
| 4 | Accès valide (`SENT` ou `OPENED`) | Formulaire affiché, questions ordonnées par `position`, avec leur encart d'aide contextuelle éventuel (voir §3.2). À la première ouverture : statut `OPENED` + `openedAt` horodaté. |

### 6.2 Soumission atomique et anti-doublon
* L'ensemble des réponses est validé et enregistré en **une seule opération atomique**.
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

### 6.4 Parcours de saisie : barre de progression et stepper

**Barre de progression (tous les formulaires).** Pour renforcer le sentiment d'avancement, une barre de progression est **toujours affichée** en haut du formulaire participant, avec un pourcentage lisible (ex. : « 40 % »).

| Formulaire | Calcul de la progression |
| :--- | :--- |
| Sans sections (page unique) | Questions renseignées / nombre total de questions, mise à jour en direct à chaque réponse |
| Avec sections (stepper) | Étape courante / nombre d'étapes (ex. : étape 2 sur 4 = 50 %) ; atteint 100 % à la dernière étape renseignée |

Accessibilité : `role="progressbar"` avec `aria-valuenow`, `aria-valuemin`, `aria-valuemax`.

**Stepper (uniquement si le questionnaire compte au moins 2 sections, voir §3.4).**
* Le formulaire devient un **tunnel** : une section par étape, avec un indicateur d'étapes (titres des sections, étape courante mise en évidence).
* Boutons « Précédent » / « Suivant ». Le bouton « Suivant » valide d'abord les questions de l'étape (obligatoires, formats, contraintes) et bloque tant que des erreurs subsistent ; « Précédent » n'exige aucune validation.
* Les réponses saisies sont **conservées lors de la navigation** entre étapes.
* Le bouton « Envoyer » n'apparaît qu'à la **dernière étape**. La soumission reste **atomique** (§6.2) : rien n'est enregistré côté serveur avant l'envoi final, et l'unicité du jeton est inchangée.
* La description de la section est affichée en tête d'étape.

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

**Analyse question par question :** rendu adapté à chacun des 5 types ([§4](#4-les-5-types-de-questions)).

**Recherche plein texte**
* Recherche par mot-clé, triée par pertinence, sur : les questionnaires (titre, description), les questions (intitulé, aide), les destinataires (adresse e-mail) et les verbatims des questions `TEXT`.
* Disponible dans le dashboard (via l'API) et, pour les contenus publics éventuels, sur le site vitrine. Détails techniques : [specs_tech §3.5](./specs_tech.prompt.md#35-recherche-plein-texte-index-fulltext--extension-dql).

**Exports**
* CSV/Excel des réponses brutes (une ligne par destinataire, avec télémétrie).
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
| `/fonctionnalites` | Détail des 5 types de questions, du contrôle par lien unique, des relances automatiques et des statistiques consolidées |
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
