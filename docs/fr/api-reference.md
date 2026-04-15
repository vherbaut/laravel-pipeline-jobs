# Référence API

Catalogue complet des symboles publics exposés par le package.

## Table des matières

- [JobPipeline](#jobpipeline)
- [PipelineBuilder](#pipelinebuilder)
- [Trait InteractsWithPipeline](#trait-interactswithpipeline)
- [Contrat CompensableJob](#contrat-compensablejob)
- [FailureContext](#failurecontext)
- [Enum FailStrategy](#enum-failstrategy)
- [PipelineContext](#pipelinecontext)
- [PipelineManifest](#pipelinemanifest)
- [Facade Pipeline](#facade-pipeline)
- [Exceptions](#exceptions)
- [Events](#events)

## `JobPipeline`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `make(array $jobs = [])` | `PipelineBuilder` | Créer un nouveau builder de pipeline, optionnellement avec un tableau de classes de job. |
| `listen(string $event, array $jobs, ?Closure $send)` | `void` | Enregistrer un pipeline comme event listener en un seul appel. |

## `PipelineBuilder`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `step(string $jobClass)` | `static` | Ajouter une étape au pipeline. |
| `when(Closure $condition, string $jobClass)` | `static` | Ajouter une étape qui ne s'exécute que si la condition (évaluée contre le contexte en direct) retourne une valeur vraie. La condition doit être sérialisable en mode queue. |
| `unless(Closure $condition, string $jobClass)` | `static` | Ajouter une étape qui ne s'exécute que si la condition (évaluée contre le contexte en direct) retourne une valeur fausse. La condition doit être sérialisable en mode queue. |
| `compensateWith(string $jobClass)` | `static` | Assigner un job de compensation à la dernière étape ajoutée. |
| `onFailure(FailStrategy\|Closure $strategyOrCallback)` | `static` | Surcharge union. Passer une énumération `FailStrategy` pour définir la stratégie saga (`StopImmediately` défaut, `StopAndCompensate`, `SkipAndContinue`). Passer une `Closure(?PipelineContext, \Throwable): void` pour enregistrer un callback d'échec au niveau pipeline. Emplacements de stockage orthogonaux. Appeler les deux enregistre les deux. |
| `beforeEach(Closure $hook)` | `static` | Enregistrer un hook par étape (sémantique append) invoqué avant chaque étape non ignorée. Signature : `Closure(StepDefinition, ?PipelineContext): void`. Voir [lifecycle-hooks-fr.md](lifecycle-hooks.md). |
| `afterEach(Closure $hook)` | `static` | Enregistrer un hook par étape (sémantique append) invoqué après chaque étape réussie. Signature : `Closure(StepDefinition, ?PipelineContext): void`. |
| `onStepFailed(Closure $hook)` | `static` | Enregistrer un hook par étape (sémantique append) invoqué quand une étape ou un hook lève une exception. Signature : `Closure(StepDefinition, ?PipelineContext, \Throwable): void`. Se déclenche avant le branchement `FailStrategy`. |
| `onSuccess(Closure $callback)` | `static` | Enregistrer un callback au niveau pipeline (last-write-wins) déclenché une fois sur succès terminal. Signature : `Closure(?PipelineContext): void`. Se déclenche sous `SkipAndContinue` même quand des étapes intermédiaires ont échoué. |
| `onComplete(Closure $callback)` | `static` | Enregistrer un callback au niveau pipeline (last-write-wins) déclenché après `onSuccess` ou `onFailure` sur les deux branches terminales. Signature : `Closure(?PipelineContext): void`. |
| `send(PipelineContext\|Closure $context)` | `static` | Définir le contexte (instance ou closure pour résolution différée). |
| `shouldBeQueued()` | `static` | Marquer le pipeline pour une exécution asynchrone en file d'attente. |
| `return(Closure $callback)` | `static` | Enregistrer une closure qui transforme le contexte final en la valeur retournée par `run()`. Synchrone uniquement. Ignorée en mode queue. |
| `build()` | `PipelineDefinition` | Construire une définition de pipeline immuable à partir de l'état actuel du builder. |
| `run()` | `mixed` | Construire et exécuter le pipeline. Retourne le résultat de la closure `->return()` quand enregistrée, sinon le `PipelineContext` final (ou `null`). Toujours `null` en mode queue. |
| `toListener()` | `Closure` | Convertir le pipeline en closure d'event listener. |
| `getContext()` | `PipelineContext\|Closure\|null` | Récupérer le contexte actuellement configuré. |

## Trait `InteractsWithPipeline`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `pipelineContext()` | `?PipelineContext` | Le `PipelineContext` en direct quand le job s'exécute dans un pipeline avec un contexte, `null` sinon. |
| `hasPipelineContext()` | `bool` | `true` quand un contexte non null est disponible, `false` pour un dispatch standalone ou un pipeline sans `->send(...)`. |
| `failureContext()` | `?FailureContext` | Snapshot du dernier échec enregistré sur le manifest, ou `null` si aucun échec n'a été enregistré ou si le job s'exécute en dehors d'un pipeline. Accesseur parallèle à `pipelineContext()`. |

## Contrat `CompensableJob`

Interface optionnelle que les jobs de compensation peuvent implémenter en alternative au pattern trait `InteractsWithPipeline`.

| Méthode | Retour | Description |
|---------|--------|-------------|
| `compensate(PipelineContext $context, ?FailureContext $failure = null)` | `void` | Hook de rollback invoqué par l'exécuteur. Le second argument n'est fourni que si l'implémentation élargit la signature à deux paramètres (détection par réflexion). |

## `FailureContext`

Value object readonly construit depuis le manifest au moment de l'invocation.

| Propriété | Type | Description |
|-----------|------|-------------|
| `failedStepClass` | `string` | FQCN de l'étape fautive. |
| `failedStepIndex` | `int` | Index (base zéro) de l'étape fautive. |
| `exception` | `?\Throwable` | Throwable d'origine (non null en sync, toujours null en queued selon NFR19). |

| Méthode | Retour | Description |
|---------|--------|-------------|
| `FailureContext::fromManifest(PipelineManifest $manifest)` | `?self` | Construire un snapshot depuis le manifest, ou `null` si aucun échec n'a été enregistré (`failedStepClass === null`). |

## Enum `FailStrategy`

| Cas | Signification |
|-----|---------------|
| `StopImmediately` | Défaut. Relance sous forme de `StepExecutionFailed`, aucune compensation. |
| `StopAndCompensate` | Exécute la chaîne de compensation en ordre inverse, puis relance `StepExecutionFailed`. |
| `SkipAndContinue` | Logue un avertissement, saute l'étape, continue. Aucune compensation. Ne lève pas. |

## `PipelineContext`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `validateSerializable()` | `void` | Valider que toutes les propriétés peuvent être sérialisées pour le dispatch en queue. |

## `PipelineManifest`

| Propriété | Type | Description |
|-----------|------|-------------|
| `pipelineId` | `string` | Identifiant unique (UUID) pour cette exécution de pipeline. |
| `stepClasses` | `array<int, string>` | Liste ordonnée des noms de classes d'étapes. |
| `compensationMapping` | `array<string, string>` | Correspondance entre classe d'étape et classe de compensation. |
| `currentStepIndex` | `int` | Index de l'étape en cours d'exécution. |
| `completedSteps` | `array<int, string>` | Étapes qui ont été complétées avec succès. |
| `context` | `?PipelineContext` | L'objet contexte partagé. |
| `failStrategy` | `FailStrategy` | Stratégie d'échec du pipeline définie via `onFailure()`. |
| `failedStepClass` | `?string` | Nom de classe de la dernière étape fautive, ou `null` si aucun échec n'a été enregistré. |
| `failedStepIndex` | `?int` | Index (base zéro) de la dernière étape fautive, ou `null`. |
| `failureException` | `?\Throwable` | Throwable vivant de la dernière erreur (null après la frontière de sérialisation queue selon NFR19). |

## Facade `Pipeline`

La facade `Pipeline` sert de proxy vers `JobPipeline` et ajoute la méthode `fake()` pour les tests.

| Méthode | Retour | Description |
|---------|--------|-------------|
| `fake()` | `PipelineFake` | Remplacer le système de pipeline par un test double. |

## Exceptions

| Exception | Quand |
|-----------|-------|
| `InvalidPipelineDefinition` | Le pipeline n'a aucune étape, ou `compensateWith()` est appelé avant toute étape. |
| `StepExecutionFailed` | Une étape a levé une exception durant l'exécution synchrone. Encapsule l'exception originale. Quand un callback au niveau pipeline (`onFailure(Closure)` ou `onComplete`) lève sur la branche d'échec, `StepExecutionFailed::forCallbackFailure()` produit un variant qui préserve l'exception d'étape originale dans `$originalStepException`. |
| `ContextSerializationFailed` | Le contexte contient des propriétés non sérialisables (closures, ressources, classes anonymes). |
| `CompensationFailed` | Classe d'exception de base pour les échecs de rollback. Disponible pour le code utilisateur qui souhaite lever une exception typée depuis un job de compensation. |

## Events

| Event | Quand |
|-------|-------|
| `Vherbaut\LaravelPipelineJobs\Events\CompensationFailed` | Dispatché inconditionnellement quand un job de compensation lève (catch best effort en sync, hook `failed()` en queued). Porte `pipelineId`, `compensationClass`, `failedStepClass`, `originalException` (null en queued), `compensationException`. |
