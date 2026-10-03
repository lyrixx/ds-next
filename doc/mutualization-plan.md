# Mutualiser docker-starter dans les projets dérivés

Aujourd'hui, chaque modification de docker-starter doit être reportée à la main
dans les projets qui en dérivent (qotd, monologue, des projets clients…).
Ce document propose un système pour rendre ces mises à jour beaucoup plus
simples.

## Constat

Nombre de lignes qui diffèrent du starter (`c75ae7e`), par fichier :

| Fichier | monologue | qotd | projet A | projet B |
|---|---|---|---|---|
| `.castor/worktree.php` | 0 | 0 | 0 | 0 |
| `.castor/postgres.php` | 0 | 2 | absent | 2 |
| `.castor/docker.php` | 12 | 38 | 56 | 1 |
| `.castor/context.php` | 1 | 58 | 52 | 61 |
| `.castor/qa.php` | 20 | 27 | 26 | 18 |
| `docker-compose.yml` | 8 | 33 | 58 | 56 |
| `php/Dockerfile` | 56 | 197 | 126 | 73 |
| `.github/workflows/ci.yml` | 116 | 219 | 130 | 80 |

Les fichiers se rangent en trois catégories :

1. **Code générique, quasi identique partout** : les fichiers `.castor/*.php`.
   Les écarts viennent surtout de valeurs codées en dur (`application/` contre
   la racine, port 8000 contre 8080, `?string $service` contre `'builder'`,
   les commentaires), plus des variations de `context.php` entre projets
   (surcharge du registry par la variable d'environnement `REGISTRY`, fichier
   `docker-compose.prod.override.yml` optionnel…). Ce n'est pas de la vraie
   personnalisation.
2. **Config qu'on adapte au projet** : Dockerfile, compose, CI. Elles varient
   pour de bon (extensions PHP, services, étapes de CI) et on ne peut pas les
   importer comme du code.
3. **Fichiers propres au projet** : `castor.php` et `README.md`. On ne doit
   jamais les synchroniser.

Le point le plus coûteux : le starter met l'app dans `application/`, alors que
monologue, qotd et le projet A l'ont à la racine. Chaque backport demande donc
de réécrire les chemins à la main.

## Plan

### Étape 0 : rendre le starter paramétrable (dans docker-starter)

Le but est de supprimer les écarts qui ne correspondent à aucun vrai besoin :

- Ajouter une variable `app_dir` (`'application'` par défaut, `''` pour la
  racine), utilisée partout : `qa.php`, `install()`, compose (build context de
  prod, `env_file`), `Dockerfile` via un `ARG`, et la config nginx.
- Lire les autres valeurs codées en dur depuis le contexte : port HTTP par
  défaut, surcharge du registry, fichier d'override de la stack de prod.

  > Surcharge du registry abandonnée : seul `castor.php` lit la variable
  > d'environnement `REGISTRY`. Un projet qui code son registry en dur garde la
  > surcharge sur la même ligne :
  > `strtolower($_SERVER['REGISTRY'] ?? 'ghcr.io/acme/app')`.
- Résultat attendu : les `.castor/*.php` retombent à 0 ligne de diff dans les
  4 projets. C'est aussi ce qui rend l'étape 2 faisable.

### Étape 1 : un outil de synchro avec merge 3-way (`castor starter:sync`)

> Implémentée dans `.castor/starter/sync.php`. Écarts avec ce qui suit : le
> merge se fait fichier par fichier avec `git merge-file` (pas de
> `git apply --3way`, qui exige les blobs du starter dans le dépôt du projet),
> et `app_dir` vient de la variable Castor, pas de `.docker-starter.json`.
> L'`exclude` de `.docker-starter.json` ne contient que les exclusions propres
> au projet : celles par défaut viennent de la version cible du starter
> (`.castor/starter/sync-default-exclude.json`), pour qu'un fichier qui n'est
> plus exclu reçoive tous les changements manqués.

C'est l'étape qui enlève le plus de travail, parce qu'elle couvre tous les
fichiers, Dockerfile et CI compris.

- Chaque projet contient un fichier `.docker-starter.json` :
  `{ "ref": "<sha ou tag du starter>", "app_dir": "", "exclude": ["castor.php", "README.md", ...] }`.
- `castor starter:sync [--to=v4.x]` :
  1. récupère le starter aux deux refs (ancienne et nouvelle) dans un dossier
     temporaire ;
  2. calcule `git diff old..new` sur les chemins gérés, en retirant les
     exclusions ;
  3. réécrit `application/` vers `app_dir` dans le patch ;
  4. l'applique avec `git apply --3way` : les modifs locales sont conservées,
     et les conflits apparaissent en marqueurs standard ;
  5. met à jour `ref` dans le fichier.
- La tâche vit dans le starter lui-même, donc elle se synchronise avec le
  reste.
- Initialisation, une seule fois par projet : choisir la ref du dernier
  backport. Pour monologue, `5c7407b` ≈ starter `ddec230`/`c75ae7e`.
- Autre option : Copier (`copier update` fait exactement ça). Mais il faut
  Python et transformer le starter en template Jinja, alors qu'il doit rester
  utilisable tel quel. Option écartée.

### Étape 2 : les `.castor/*.php` communs deviennent un package Castor

- Le starter reçoit un `composer.json` et un point d'entrée qui importe
  `docker`, `context`, `qa`, `postgres` et `worktree`.
- Dans chaque projet, un `castor.composer.json` requiert
  `jolicode/docker-starter`, et le `castor.php` appelle
  `import('composer://jolicode/docker-starter', file: '...')`. Pour mettre à
  jour : `castor composer update`.
- Point à corriger avant : `root_dir => \dirname(__DIR__)` devient faux quand
  le code est dans `.castor/vendor/`. Il faut passer par la racine du projet
  Castor.
- Limites :
  - les remote imports sont marqués **expérimentaux** dans la doc Castor ;
  - Dependabot ne lit pas `castor.composer.json` ;
  - on perd la possibilité de bidouiller un fichier dans un projet (il faut
    passer par des variables de contexte ou des listeners Castor).

  C'est pour ça que ce n'est pas la première étape : la synchro fait déjà
  l'essentiel, ce package sert surtout à dédupliquer.

### Étape 3 : la CI

L'écart y est trop grand pour un seul workflow réutilisable :

- mettre les étapes communes dans des **composite actions** du starter :
  installation de Castor, cache buildx, `castor start -c ci`. On les appelle
  avec `uses: jolicode/docker-starter/.github/actions/setup@v4` ;
- garder la structure des jobs dans chaque projet.

L'écart de `ci.yml` devrait alors se limiter à ce qui est vraiment propre à
chaque projet.

### Étape 4 : automatisation

- Taguer les releases du starter (il y a déjà un `CHANGELOG.md`).
- Dans chaque projet, un workflow déclenché par un cron hebdomadaire ou un
  `repository_dispatch` envoyé par le starter à chaque release. Il lance
  `castor starter:sync` (et `castor composer update` si l'étape 2 est faite),
  puis ouvre une PR avec `peter-evans/create-pull-request`, en draft s'il y a
  des conflits.
- Le travail se résume alors à relire et merger les PR.

### Déploiement

1. Étape 0 dans docker-starter.
2. Étape 1 dans le starter, testée sur **monologue**, où l'écart est le plus
   faible.
3. Initialisation de qotd et des projets A et B. C'est un réalignement
   manuel, une seule fois par projet.
4. Étapes 3 et 4, puis l'étape 2 si l'usage le justifie.
