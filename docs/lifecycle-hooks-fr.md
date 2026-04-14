# Hooks de cycle de vie et observabilité

Laravel Pipeline Jobs expose six hooks de cycle de vie qui permettent d'observer les pipelines sans toucher aux classes de jobs elles mêmes. Ils se répartissent en deux catégories.

| Catégorie | Méthodes | Déclenchement |
|-----------|----------|---------------|
| Hooks par étape | `beforeEach()`, `afterEach()`, `onStepFailed()` | À chaque étape non ignorée |
| Callbacks au niveau pipeline | `onSuccess()`, `onFailure(Closure)`, `onComplete()` | Une fois par issue de pipeline |

Les six hooks fonctionnent de manière identique en mode synchrone, en file d'attente et en mode recording. Les closures de hooks qui franchissent la frontière de la queue sont encapsulées dans `SerializableClosure`, donc toutes les variables capturées doivent être sérialisables.

## Table des matières

- [Hooks par étape](#hooks-par-étape)
  - [beforeEach](#beforeeach)
  - [afterEach](#aftereach)
  - [onStepFailed](#onstepfailed)
  - [Sémantique append](#sémantique-append)
  - [Gestion des erreurs dans les hooks par étape](#gestion-des-erreurs-dans-les-hooks-par-étape)
- [Callbacks au niveau pipeline](#callbacks-au-niveau-pipeline)
  - [onSuccess](#onsuccess)
  - [onFailure (Closure)](#onfailure-closure)
  - [onComplete](#oncomplete)
  - [Sémantique last-write-wins](#sémantique-last-write-wins)
  - [Gestion des erreurs dans les callbacks pipeline](#gestion-des-erreurs-dans-les-callbacks-pipeline)
- [Référence de l'ordre de déclenchement](#référence-de-lordre-de-déclenchement)
- [Interaction avec FailStrategy::SkipAndContinue](#interaction-avec-failstrategyskipandcontinue)
- [Notes sur le mode en file d'attente](#notes-sur-le-mode-en-file-dattente)
- [Tester les hooks](#tester-les-hooks)
- [Exemple complet](#exemple-complet)

## Hooks par étape

Les hooks par étape reçoivent un snapshot minimal de `StepDefinition` et le `PipelineContext` vivant (qui peut être `null` quand aucun contexte n'a été transmis via `->send()`).

### beforeEach

Se déclenche immédiatement avant l'exécution du `handle()` de chaque étape non ignorée.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->beforeEach(function (StepDefinition $step, ?PipelineContext $context): void {
        Log::info("Démarrage de {$step->jobClass}");
    })
    ->send(new OrderContext(order: $order))
    ->run();
```

Les hooks ne se déclenchent pas pour les étapes ignorées via `when()` / `unless()`. La vérification d'exclusion précède le déclenchement.

### afterEach

Se déclenche après le retour réussi du `handle()` de l'étape, avant que le manifest ne marque l'étape comme complétée.

```php
JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->afterEach(function (StepDefinition $step, ?PipelineContext $context): void {
        Log::info("Terminé : {$step->jobClass}");
    })
    ->run();
```

Les mutations effectuées par `handle()` sont visibles sur l'argument context. Le hook ne se déclenche pas pour les étapes qui ont levé une exception. Le branchement `onStepFailed` s'applique à la place.

### onStepFailed

Se déclenche quand une étape lève une exception, y compris les exceptions provenant de `beforeEach` ou `afterEach`.

```php
JobPipeline::make([ChargeCustomer::class])
    ->onStepFailed(function (StepDefinition $step, ?PipelineContext $context, \Throwable $exception): void {
        Log::error("L'étape {$step->jobClass} a échoué", ['exception' => $exception]);
    })
    ->run();
```

`onStepFailed` se déclenche AVANT l'application du branchement `FailStrategy`. Le hook s'exécute donc quelle que soit la stratégie (`StopImmediately`, `StopAndCompensate` ou `SkipAndContinue`).

### Sémantique append

Les hooks par étape sont en mode append. Enregistrer le même type de hook plusieurs fois exécute toutes les closures dans l'ordre d'enregistrement.

```php
JobPipeline::make([ProcessOrder::class])
    ->beforeEach(fn ($step) => Log::info("[metrics] {$step->jobClass}"))
    ->beforeEach(fn ($step) => Tracer::start($step->jobClass))
    ->run();
```

Les deux closures se déclenchent par étape, dans l'ordre où elles ont été enregistrées. C'est un choix ergonomique volontaire. Plusieurs observateurs (logs, metrics, tracing) sont un besoin courant.

### Gestion des erreurs dans les hooks par étape

| Hook levant une exception | Effet |
|---------------------------|-------|
| `beforeEach` ou `afterEach` | Traité comme un échec d'étape. L'étape n'est pas marquée complétée. Les hooks `onStepFailed` se déclenchent avec l'exception du hook. Le branchement `FailStrategy` s'applique ensuite. |
| `onStepFailed` | L'exception se propage et remplace l'exception d'étape originale. Le branchement `FailStrategy` est court circuité pour l'échec courant. Les hooks `onStepFailed` suivants dans le tableau ne se déclenchent pas. |

Aucune exception de hook n'est silencieusement avalée.

## Callbacks au niveau pipeline

Les callbacks au niveau pipeline se déclenchent une seule fois par issue du pipeline, pas par étape.

### onSuccess

Se déclenche quand le pipeline atteint sa branche terminale de succès.

```php
JobPipeline::make([ProcessOrder::class, SendReceipt::class])
    ->onSuccess(function (?PipelineContext $context): void {
        Notification::send($user, new OrderProcessed($context->order));
    })
    ->run();
```

"Succès" signifie "le pipeline a terminé son flux prévu sans échec terminal", pas "toutes les étapes ont réussi". Sous `FailStrategy::SkipAndContinue` le pipeline atteint la branche de succès même si des étapes intermédiaires ont échoué, donc `onSuccess` se déclenche quand même.

### onFailure (Closure)

La méthode `onFailure()` accepte soit une énumération `FailStrategy` (le setter de stratégie saga préexistant), soit une `Closure` (un callback d'échec au niveau pipeline). Ce sont des emplacements de stockage orthogonaux. Vous pouvez appeler les deux.

```php
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

JobPipeline::make([ReserveInventory::class, ChargeCustomer::class])
    ->onFailure(FailStrategy::StopAndCompensate)
    ->onFailure(function (?PipelineContext $context, \Throwable $exception): void {
        Alert::send("Échec du pipeline : {$exception->getMessage()}");
    })
    ->run();
```

La forme closure se déclenche une fois sur échec terminal du pipeline sous `StopImmediately` ou `StopAndCompensate`. Elle se déclenche APRÈS les hooks `onStepFailed` par étape, APRÈS la compensation (sync : la chaîne a entièrement tourné, queued : la chaîne a été dispatchée), et AVANT la relance terminale.

Sous `FailStrategy::SkipAndContinue` la closure ne se déclenche PAS, car il n'y a pas de levée terminale. Utilisez `onStepFailed` par étape pour l'observabilité d'échec dans les pipelines tolérants.

### onComplete

Se déclenche APRÈS `onSuccess` sur la branche de succès et APRÈS `onFailure` sur la branche d'échec.

```php
JobPipeline::make([ProcessOrder::class])
    ->onComplete(function (?PipelineContext $context): void {
        Metrics::record('pipeline.completed');
    })
    ->run();
```

`onComplete` s'exécute sur les deux branches terminales (succès ou échec), sauf si un callback précédent a levé une exception.

### Sémantique last-write-wins

Les callbacks au niveau pipeline sont en last-write-wins. Enregistrer deux fois le même type de callback rejette le premier.

```php
JobPipeline::make([ProcessOrder::class])
    ->onSuccess($premierCallback)  // rejeté
    ->onSuccess($secondCallback)   // conservé
    ->run();
```

Cela contraste avec les hooks par étape qui sont en append. La raison : un pipeline a UNE issue terminale, donc une notification / une métrique / une alerte est la forme ergonomique. Plusieurs observateurs au niveau pipeline créeraient des doublons silencieux.

### Gestion des erreurs dans les callbacks pipeline

| Callback levant une exception | Comportement sync | Comportement queued |
|-------------------------------|-------------------|----------------------|
| `onSuccess` | L'exception se propage telle quelle. `onComplete` est ignoré. | Le wrapper job est marqué en échec. `onComplete` est ignoré. |
| `onFailure(Closure)` | Encapsulé dans `StepExecutionFailed` via `StepExecutionFailed::forCallbackFailure()`. L'exception d'étape originale est préservée dans `$originalStepException`, et l'exception du callback est attachée via `$previous`. `onComplete` est ignoré. | Le wrapper job est marqué en échec avec le même wrapper. `onComplete` est ignoré. |
| `onComplete` sur branche succès | L'exception remonte telle quelle. | Le wrapper job est marqué en échec. |
| `onComplete` sur branche échec | Encapsulé dans `StepExecutionFailed::forCallbackFailure()`. Remplace la relance initialement prévue. L'exception d'étape originale est préservée dans `$originalStepException`. | Le wrapper job est marqué en échec. |

L'encapsulation `forCallbackFailure` préserve l'observabilité : les lecteurs peuvent inspecter à la fois le fault du callback (via `getPrevious()`) et le fault de l'étape originale (via `$originalStepException`).

## Référence de l'ordre de déclenchement

Branche succès (sync ou queued, toute stratégie) :

1. Étape N : les hooks `beforeEach` se déclenchent (ordre d'enregistrement)
2. Étape N : `handle()` s'exécute
3. Étape N : les hooks `afterEach` se déclenchent (ordre d'enregistrement)
4. (Répéter pour les étapes restantes)
5. Le callback `onSuccess` se déclenche
6. Le callback `onComplete` se déclenche
7. L'exécuteur retourne

Branche échec sous `StopImmediately` :

1. L'étape N lève une exception
2. Les hooks `onStepFailed` se déclenchent (ordre d'enregistrement)
3. Le callback `onFailure(Closure)` se déclenche
4. Le callback `onComplete` se déclenche
5. `StepExecutionFailed` est relancé (sync) ou l'exception brute est relancée (queued)

Branche échec sous `StopAndCompensate` :

1. L'étape N lève une exception
2. Les hooks `onStepFailed` se déclenchent
3. La chaîne de compensation tourne (sync) ou est dispatchée (queued)
4. Le callback `onFailure(Closure)` se déclenche (sync : post compensation, queued : post dispatch mais pré exécution)
5. Le callback `onComplete` se déclenche
6. `StepExecutionFailed` est relancé

Branche échec sous `SkipAndContinue` :

1. L'étape N lève une exception
2. Les hooks `onStepFailed` se déclenchent
3. Warning loggé, avancement à l'étape suivante
4. (Pas de levée terminale, le pipeline atteint finalement la branche de succès)
5. Le callback `onSuccess` se déclenche
6. Le callback `onComplete` se déclenche

## Interaction avec FailStrategy::SkipAndContinue

`SkipAndContinue` convertit les échecs d'étape en continuations. Le pipeline se termine toujours via la branche de succès.

- Les hooks `onStepFailed` se déclenchent sur chaque étape en échec.
- `onSuccess` se déclenche à la fin, même quand des étapes intermédiaires ont échoué.
- `onFailure(Closure)` ne se déclenche PAS (pas de levée terminale).
- `onComplete` se déclenche après `onSuccess`.

Si vous avez besoin d'une observabilité au niveau pipeline du style "une étape a t elle échoué sous SkipAndContinue ?", suivez cet état vous même dans le contexte via un hook `onStepFailed`.

## Notes sur le mode en file d'attente

Toutes les closures de hooks et callbacks sont encapsulées dans `SerializableClosure` quand le pipeline tourne en queued. Les closures non sérialisables produisent l'exception `SerializableClosure` standard au moment du dispatch (`PipelineBuilder::run()` ou `::toListener()`).

Les callbacks au niveau pipeline se déclenchent sur le worker qui traite l'étape terminale. Le callback `onFailure(Closure)` sous `StopAndCompensate` en mode queued tourne AVANT l'exécution des jobs de compensation. Ces derniers tournent sur leurs propres workers ensuite. Planifiez les effets de bord de vos callbacks en conséquence.

Les variables capturées via `use` doivent aussi être sérialisables. Évitez de capturer des ressources, classes anonymes ou handles de base de données actifs dans les closures de hooks. Chargez ce dont vous avez besoin depuis l'argument `PipelineContext` à la place.

## Tester les hooks

Les hooks se déclenchent de manière identique sous `Pipeline::fake()->recording()`. Le `FakePipelineBuilder` expose les six mêmes méthodes en délégués pass through.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

it('déclenche le callback de succès', function () {
    $fake = Pipeline::fake()->recording();
    $successDeclenche = false;

    JobPipeline::make([ProcessOrder::class])
        ->onSuccess(function () use (&$successDeclenche) {
            $successDeclenche = true;
        })
        ->send(new OrderContext(order: $order))
        ->run();

    expect($successDeclenche)->toBeTrue();
});
```

En mode `Pipeline::fake()` non recording, les hooks NE sont PAS invoqués car aucune étape ne tourne. Le fake capture uniquement la définition.

Aucune assertion dédiée aux hooks n'est livrée avec le package aujourd'hui (`assertHookRegistered()`, `assertHookFired()`). Utilisez des flags booléens locaux ou des closures espion, comme montré ci dessus.

## Exemple complet

Un pipeline complet câblant chaque type de hook, illustrant l'ordre de déclenchement.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\StepDefinition;

JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(SendConfirmation::class)

    // Hooks par étape (sémantique append).
    ->beforeEach(fn (StepDefinition $s, ?PipelineContext $c) => Log::info("→ {$s->jobClass}"))
    ->afterEach(fn (StepDefinition $s, ?PipelineContext $c) => Log::info("✓ {$s->jobClass}"))
    ->onStepFailed(fn (StepDefinition $s, ?PipelineContext $c, \Throwable $e) => Sentry::captureException($e))

    // Callbacks au niveau pipeline (last-write-wins).
    ->onSuccess(fn (?PipelineContext $c) => Notification::send($user, new OrderCompleted($c->order)))
    ->onFailure(FailStrategy::StopAndCompensate)
    ->onFailure(fn (?PipelineContext $c, \Throwable $e) => Alert::send("Échec du pipeline de commande"))
    ->onComplete(fn (?PipelineContext $c) => Metrics::record('orders.pipeline.completed'))

    ->send(new OrderContext(order: $order))
    ->run();
```
