# Groupes d'étapes parallèles

Certaines étapes d'un pipeline peuvent tourner en même temps sans dépendre les unes des autres. Les groupes parallèles modélisent ce pattern **fan out / fan in** : N sous étapes se lancent simultanément, et le pipeline attend que toutes aient terminé avant de passer à l'étape suivante.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    ValidateOrder::class,
    JobPipeline::parallel([
        GenerateInvoicePdf::class,
        NotifyWarehouse::class,
        SendAnalyticsEvent::class,
    ]),
    FinalizeOrder::class,
])
    ->send(new OrderContext(order: $order))
    ->run();
```

Les trois sous étapes (génération PDF, notification entrepôt, événement analytics) partent en parallèle après `ValidateOrder`. `FinalizeOrder` ne démarre qu'une fois les trois terminées.

## Table des matières

- [Deux façons de construire un groupe](#deux-façons-de-construire-un-groupe)
- [Exécution synchrone vs queue](#exécution-synchrone-vs-queue)
- [Fusion du contexte](#fusion-du-contexte)
- [Configuration par sous étape](#configuration-par-sous-étape)
- [Contraintes d'imbrication](#contraintes-dimbrication)
- [Compensation et hooks](#compensation-et-hooks)
- [Impact sur la taille du payload](#impact-sur-la-taille-du-payload)
- [Tests](#tests)

## Deux façons de construire un groupe

**API tableau** (lecture descendante) :

```php
JobPipeline::make([
    FirstStep::class,
    JobPipeline::parallel([SubA::class, SubB::class, SubC::class]),
    LastStep::class,
])->run();
```

**API fluide** (chaînage) :

```php
JobPipeline::make()
    ->step(FirstStep::class)
    ->parallel([SubA::class, SubB::class, SubC::class])
    ->step(LastStep::class)
    ->run();
```

Les deux formes produisent strictement le même `PipelineDefinition`. Le choix relève du style.

Un `ParallelStepGroup` occupe **une seule position extérieure** dans le pipeline. `stepCount()` le compte pour 1. `flatStepCount()` l'expanse au nombre de sous étapes (utile pour anticiper le volume de jobs en queue).

## Exécution synchrone vs queue

**Mode synchrone.** Les sous étapes tournent séquentiellement dans le même process PHP (aucun parallélisme réel, mais la sémantique reste la même : le contexte après le groupe est l'agrégation de tous les runs). Convient pour les tests, les commandes artisan et les workflows pilotés par API.

**Mode queue.** Le groupe se dispatch via `Bus::batch()` de Laravel. Chaque sous étape devient un job indépendant traité par un worker, potentiellement sur plusieurs threads en parallèle. Le `finally()` du batch déclenche le dispatch de l'étape suivante.

```php
JobPipeline::make([
    ValidateOrder::class,
    JobPipeline::parallel([GenerateInvoicePdf::class, NotifyWarehouse::class]),
    FinalizeOrder::class,
])
    ->shouldBeQueued()
    ->send($context)
    ->run();
```

Le mode queue nécessite la table `job_batches`. Si votre projet ne l'a pas encore :

```bash
php artisan queue:batches-table
php artisan migrate
```

Les pipelines purement synchrones peuvent ignorer cette étape.

## Fusion du contexte

Chaque sous étape d'un groupe reçoit une **copie profonde** du contexte au moment du fan out. Elles peuvent donc muter leur propre snapshot sans se marcher dessus. Une fois toutes terminées, les snapshots sont **fusionnés** dans le contexte extérieur par `ParallelContextMerger`.

Règles de fusion (priorité descendante) :

1. **Valeurs scalaires et objets** : la dernière sous étape à écrire gagne (ordre de complétion du batch).
2. **Tableaux** : fusionnés via `array_merge` récursif (les clés numériques sont concaténées, les clés string écrasées).
3. **`null` vs valeur** : une valeur non nulle l'emporte toujours sur `null`.

Les sous étapes doivent donc éviter de toucher aux **mêmes propriétés** du contexte. Si deux sous étapes écrivent `$context->total`, le résultat dépend de l'ordre non déterministe de complétion. Pour isoler les écritures, préférez des propriétés dédiées par sous étape (par exemple `$context->invoicePdfPath` et `$context->warehouseNotificationId`) que l'étape suivante consomme ensuite.

## Configuration par sous étape

Chaque sous étape peut recevoir sa propre configuration via un `StepDefinition` pré construit :

```php
use Vherbaut\LaravelPipelineJobs\Step;

JobPipeline::parallel([
    Step::make(GenerateInvoicePdf::class)->onQueue('pdf')->timeout(120),
    Step::make(NotifyWarehouse::class)->onQueue('notifications')->retry(3)->backoff(5),
    SendAnalyticsEvent::class, // défaut pipeline applicable
]);
```

La configuration par défaut du pipeline (`defaultQueue()`, `defaultRetry()`, etc.) s'applique aux sous étapes qui ne surchargent rien. Les mutateurs `compensateWith()`, `onQueue()`, `onConnection()`, `sync()`, `retry()`, `backoff()`, `timeout()` **ne peuvent pas** être appelés directement après `->parallel(...)` sur le builder : ils ciblent une étape unique, or un groupe parallèle agrège plusieurs sous étapes avec des configurations potentiellement distinctes. Appliquez les à chaque sous étape individuelle via `Step::make(...)->mutator(...)`.

## Contraintes d'imbrication

Un groupe parallèle accepte uniquement des **sous étapes plates** (class string ou `StepDefinition`). Les compositions de groupe suivantes sont rejetées à la construction :

| Tentative | Exception |
|-----------|-----------|
| Un `ParallelStepGroup` imbriqué dans un autre | `InvalidPipelineDefinition::nestedParallelGroup()` |
| Un `NestedPipeline` (sous pipeline) dans un groupe parallèle | `InvalidPipelineDefinition::nestedPipelineInsideParallelGroup()` |
| Un `ConditionalBranch` dans un groupe parallèle | `InvalidPipelineDefinition::conditionalBranchInsideParallelGroup()` |

La raison est le deep clone du manifest par sous étape : ces mécanismes (parallèle, nested, branch) posent chacun des invariants de cursor et de sélection qui se cassent si on les démultiplie par N.

Pour combiner parallèle et nested / branch, **enveloppez le parallèle à l'extérieur** ou **utilisez un sous pipeline** :

```php
// Correct : parallèle autour d'un sous pipeline
JobPipeline::parallel([
    JobPipeline::nest([StepA::class, StepB::class]),
    StepC::class,
]);
```

## Compensation et hooks

Chaque sous étape peut déclarer sa propre compensation via `Step::make(SubStep::class)->compensateWith(Rollback::class)`. Quand le pipeline tourne sous `FailStrategy::StopAndCompensate` et qu'une sous étape échoue :

1. Les sous étapes du **même groupe** déjà complétées sont enregistrées dans `$manifest->completedSteps`.
2. Après l'échec, la chaîne de compensation remonte en ordre inverse toutes les étapes extérieures complétées **ainsi que** les sous étapes du groupe en cours qui ont fini.
3. Les sous étapes qui n'ont pas eu le temps de démarrer ne sont pas compensées.

Les hooks de cycle de vie (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) déclarés au niveau du pipeline extérieur se déclenchent **pour chaque sous étape** du groupe. Le `StepDefinition` passé au hook est construit à la volée via `StepDefinition::fromJobClass($subClass)`, donc il ne contient pas de compensation ni de configuration.

## Impact sur la taille du payload

Le dispatch `Bus::batch()` crée N jobs wrapper qui transportent chacun leur **propre copie** du manifest. Pour un groupe de N sous étapes, le payload du batch fait donc N fois la taille du manifest. Ce coût s'applique uniquement pendant la fenêtre de vie du batch (jusqu'à ce que le `finally()` dispatche l'étape suivante).

NFR11 fixe la limite à 256 Ko par job SQS. Pour rester sous cette limite, gardez la taille du contexte modérée et préférez charger les gros blobs (fichiers, documents) via une référence (ID en base) plutôt que par valeur dans le contexte.

## Tests

`PipelineFake` enregistre les groupes parallèles dans la définition recordée. L'assertion dédiée est `assertParallelGroupExecuted(array $expectedClasses, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    FirstStep::class,
    JobPipeline::parallel([SubA::class, SubB::class]),
])->send($context)->run();

Pipeline::assertParallelGroupExecuted([SubA::class, SubB::class]);
```

L'assertion vérifie que la définition recordée contient **au moins un** `ParallelStepGroup` dont les classes (dans l'ordre d'insertion) correspondent à `$expectedClasses`. Pour des assertions d'exécution réelles (qu'est ce qui a vraiment tourné, dans quel ordre), basculez en mode recording via `Pipeline::fake()->recording()` et inspectez `executedSteps` / `contextSnapshots`.
