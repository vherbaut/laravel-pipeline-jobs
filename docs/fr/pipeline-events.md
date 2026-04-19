# Événements de pipeline

Les pipelines peuvent émettre des événements Laravel à trois points clés du cycle de vie pour que des listeners externes (métriques, audit, alerting, observabilité multi tenant) réagissent sans toucher au pipeline lui même. L'émission est **opt in**. Quand le flag est désactivé, aucun événement n'est alloué.

## Trois événements à trois points du cycle

| Événement | Quand il se déclenche |
|-----------|-----------------------|
| `PipelineStepCompleted` | Après chaque `handle()` (ou `__invoke()`) de step terminé avec succès. |
| `PipelineStepFailed` | Immédiatement après qu'un step a levé une exception, avant les hooks `onStepFailed`. |
| `PipelineCompleted` | Une fois, à la sortie terminale du run (succès ou échec). |

Un quatrième événement, `CompensationFailed`, est toujours émis quand la chaîne de compensation elle même lève une exception. C'est de l'alerting opérationnel, pas un signal de cycle de vie, donc il n'est pas conditionné par le flag opt in.

## Activation via `dispatchEvents()`

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])
    ->dispatchEvents()
    ->send(new OrderContext(order: $order))
    ->run();
```

Appeler `dispatchEvents()` bascule un booléen sur le builder. Idempotent (un deuxième appel n'a aucun effet supplémentaire). Sans le flag, le dispatcher centralisé court circuite avant même de construire le payload. Aucun surcoût quand la fonctionnalité n'est pas utilisée.

## Écoute des événements

Enregistrez les listeners dans `EventServiceProvider` (ou via auto découverte) exactement comme n'importe quel événement Laravel.

```php
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepFailed;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;

Event::listen(PipelineStepCompleted::class, function (PipelineStepCompleted $event): void {
    Log::info('step terminé', [
        'pipeline_id' => $event->pipelineId,
        'step_index'  => $event->stepIndex,
        'step_class'  => $event->stepClass,
    ]);
});

Event::listen(PipelineStepFailed::class, function (PipelineStepFailed $event): void {
    report($event->exception);
});

Event::listen(PipelineCompleted::class, function (PipelineCompleted $event): void {
    Metric::increment('pipeline.completed', ['pipeline_id' => $event->pipelineId]);
});
```

## Payload des événements

Tous les événements portent une chaîne `pipelineId` qui corrèle les trois événements d'un même run. Utilisez la comme clé de corrélation dans les logs et métriques.

```php
final class PipelineStepCompleted
{
    public function __construct(
        public readonly string $pipelineId,
        public readonly ?PipelineContext $context,
        public readonly int $stepIndex,
        public readonly string $stepClass,
    ) {}
}
```

`PipelineStepFailed` ajoute un `Throwable $exception`. `PipelineCompleted` n'a ni `stepIndex` ni `stepClass` (il ne se déclenche qu'une fois, pas par step).

## Sémantique de l'index

Le `stepIndex` de `PipelineStepCompleted` et `PipelineStepFailed` est la **position extérieure** dans le pipeline déclaré par l'utilisateur.

- Step plat de premier niveau. L'index propre du step.
- Sous step parallèle. L'index du groupe extérieur (le sous step est désambiguïsé par `stepClass`).
- Step interne imbriqué. L'index extérieur de premier niveau portant le pipeline imbriqué.
- Step interne d'une branche. L'index du groupe de branche (seule la branche sélectionnée émet des événements).

Un step ignoré via `when()` / `unless()` n'émet aucun événement. En `SkipAndContinue`, un step en échec émet `PipelineStepFailed` mais pas `PipelineStepCompleted`.

## Parité sync, queued et recording

Les trois événements se déclenchent de façon identique selon le mode d'exécution.

- **Synchrone.** `SyncExecutor` émet après les hooks `afterEach` et après `markStepCompleted()` sur le manifest.
- **Queued.** `PipelineStepJob` émet au même point sur le worker, avant le hop vers le step suivant.
- **Recording.** `Pipeline::fake()->recording()` émet via l'observer d'enregistrement, les tests `Event::fake()` capturent les mêmes payloads.

`Pipeline::fake()` sans `->recording()` n'exécute **pas** les steps. Donc il n'émet jamais ces événements, même si `dispatchEvents()` est activé.

## Mise en garde sur les listeners queued

Les listeners enregistrés avec `ShouldQueue` reçoivent un payload qui peut contenir un `Throwable` vivant sur `PipelineStepFailed`. Le sérialiseur de Laravel pour listeners queued peut échouer ou nettoyer le throwable au routage vers la queue. Pour les listeners queued, extrayez l'essentiel (classe, message, stack trace sous forme de string) dans un listener in process qui relaie un payload assaini vers le travail queued, plutôt que de compter sur la survie du `Throwable` au transport queue.

## Interaction avec hooks et callbacks

Les événements sont orthogonaux aux hooks et callbacks livrés dans les stories précédentes.

- Hooks par étape (`beforeEach`, `afterEach`, `onStepFailed`) s'exécutent in process, sur le même worker, synchronement autour du step.
- Callbacks de niveau pipeline (`onSuccess`, `onFailure`, `onComplete`) s'exécutent in process à la sortie terminale.
- Les événements passent par le dispatcher Laravel, ils peuvent donc être queued, batchés, ou observés par n'importe quel subscriber.

Un pipeline utilisant à la fois hooks ET événements obtient les deux signaux. Les hooks se déclenchent d'abord (in process, par step), puis les événements sont dispatchés (cross process, observables par n'importe quel listener).

## Tests

Assertez les événements avec `Event::fake()`.

```php
use Illuminate\Support\Facades\Event;
use Vherbaut\LaravelPipelineJobs\Events\PipelineStepCompleted;
use Vherbaut\LaravelPipelineJobs\Events\PipelineCompleted;

Event::fake([PipelineStepCompleted::class, PipelineCompleted::class]);

JobPipeline::make([ValidateOrder::class, ChargeCustomer::class])
    ->dispatchEvents()
    ->send(new OrderContext(order: $order))
    ->run();

Event::assertDispatched(
    PipelineStepCompleted::class,
    fn (PipelineStepCompleted $event) => $event->stepClass === ChargeCustomer::class,
);
Event::assertDispatchedTimes(PipelineCompleted::class, 1);
```

Le mode recording émet aussi les événements, donc les tests combinant `Pipeline::fake()->recording()` avec `Event::fake()` exercent le même chemin de code qu'en production.
