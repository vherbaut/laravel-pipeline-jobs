# Imbrication de pipelines

Certains workflows partagent des sous séquences d'étapes (validation, enrichissement, notifications). Plutôt que de dupliquer ces séquences dans chaque pipeline, on les factorise dans un **sous pipeline** et on les inclut comme une position unique du pipeline extérieur.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$enrichmentPipeline = JobPipeline::make([
    LoadCustomerProfile::class,
    EnrichWithLoyaltyPoints::class,
    EnrichWithRecentOrders::class,
]);

JobPipeline::make([
    ValidateRequest::class,
    JobPipeline::nest($enrichmentPipeline, name: 'enrichment'),
    FormatResponse::class,
])
    ->send(new RequestContext(userId: $id))
    ->run();
```

Le sous pipeline `enrichment` s'exécute séquentiellement dans le pipeline extérieur, partage le même `PipelineContext`, et occupe **une seule position extérieure** (au sens de `stepCount()`).

## Table des matières

- [Construire un sous pipeline](#construire-un-sous-pipeline)
- [Emballage automatique](#emballage-automatique)
- [Partage du contexte](#partage-du-contexte)
- [Exécution en queue et cursor](#exécution-en-queue-et-cursor)
- [Configuration et valeurs par défaut](#configuration-et-valeurs-par-défaut)
- [FailStrategy, hooks et callbacks](#failstrategy-hooks-et-callbacks)
- [Compensation](#compensation)
- [Contraintes d'imbrication](#contraintes-dimbrication)
- [Tests](#tests)

## Construire un sous pipeline

`JobPipeline::nest(...)` produit un value object `NestedPipeline`. Il accepte trois formes d'entrée :

```php
// Depuis un builder déjà construit
$sub = JobPipeline::make([StepA::class, StepB::class]);
JobPipeline::nest($sub);

// Depuis une PipelineDefinition (snapshot immuable)
$definition = JobPipeline::make([StepA::class, StepB::class])->build();
JobPipeline::nest($definition);

// Avec un nom pour l'observabilité
JobPipeline::nest($sub, name: 'enrichment');
```

Le nom facultatif apparaît dans les logs et les assertions de test.

## Emballage automatique

Pour simplifier l'écriture, le builder **emballe automatiquement** les `PipelineBuilder` et `PipelineDefinition` qu'il trouve dans le tableau ou dans les appels fluides. Les deux formes ci dessous sont équivalentes :

```php
// Explicite
JobPipeline::make([
    StepA::class,
    JobPipeline::nest(JobPipeline::make([SubA::class, SubB::class])),
    StepC::class,
]);

// Auto emballé
JobPipeline::make([
    StepA::class,
    JobPipeline::make([SubA::class, SubB::class]),
    StepC::class,
]);
```

L'auto emballage s'applique uniquement à `PipelineBuilder` et `PipelineDefinition`. Un `NestedPipeline` déjà construit est utilisé tel quel.

## Partage du contexte

Toutes les étapes du sous pipeline lisent et mutent **le même `PipelineContext`** que le pipeline extérieur. Il n'y a ni clone ni isolation : un enrichissement réalisé par une sous étape est immédiatement visible par les étapes extérieures qui suivent.

```php
class EnrichWithLoyaltyPoints
{
    use InteractsWithPipeline;

    public function handle(LoyaltyService $service): void
    {
        $ctx = $this->pipelineContext();
        $ctx->loyaltyPoints = $service->pointsFor($ctx->userId);
    }
}

// Après le sous pipeline, $ctx->loyaltyPoints est disponible dans FormatResponse.
```

Ce partage contraste avec les groupes [parallèles](parallel-steps.md) qui isolent chaque sous étape dans un clone profond.

## Exécution en queue et cursor

En mode synchrone, les sous étapes tournent inline dans le même process (une simple boucle `foreach`).

En mode queue, chaque sous étape devient un job wrapper indépendant. Un **cursor imbriqué** (`nestedCursor`) est porté par le manifest pour identifier la position courante dans l'arbre :

- Cursor `[]` : position extérieure (racine).
- Cursor `[3]` : position 3 du pipeline extérieur, qui est un sous pipeline.
- Cursor `[3, 1]` : sous étape 1 du sous pipeline à la position 3.
- Cursor `[3, 1, 0]` : profondeur arbitraire (imbrication multi niveaux).

Le cursor est automatiquement avancé par `advanceCursorOrOuter()` après chaque sous étape complétée. Quand le cursor dépasse la dernière sous étape d'un niveau, il remonte d'un cran et passe à la position extérieure suivante. Aucune plomberie manuelle n'est nécessaire côté utilisateur : le dispatch se fait via le même `PipelineStepJob` pour tous les niveaux.

## Configuration et valeurs par défaut

Les valeurs par défaut du pipeline extérieur (`defaultQueue()`, `defaultConnection()`, `defaultRetry()`, `defaultBackoff()`, `defaultTimeout()`) s'appliquent à chaque étape extérieure **sauf** lorsqu'elles sont remplacées par un `Step::make(...)->onQueue(...)`. Les sous étapes d'un `NestedPipeline` utilisent les valeurs par défaut de leur **propre** `PipelineDefinition` intérieure, **pas** celles du pipeline extérieur.

Ceci permet de composer un pipeline réutilisable avec sa propre politique de queue / retry sans que le pipeline extérieur la contamine :

```php
$validationPipeline = JobPipeline::make([ValidateA::class, ValidateB::class])
    ->defaultQueue('validation')
    ->defaultRetry(2);

JobPipeline::make([
    JobPipeline::nest($validationPipeline), // ValidateA/B tournent sur la queue 'validation' avec retry 2
    ExecuteMain::class, // utilise les valeurs par défaut du pipeline extérieur
])
    ->defaultQueue('default')
    ->run();
```

## FailStrategy, hooks et callbacks

La `FailStrategy` du pipeline **extérieur** gouverne tout l'arbre. Une `FailStrategy` déclarée sur une `PipelineDefinition` enveloppée via `nest()` est structurellement présente mais **ignorée** à l'exécution.

Les hooks de cycle de vie (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) du pipeline extérieur se déclenchent **pour chaque sous étape** de chaque sous pipeline, exactement comme pour les étapes extérieures. Les hooks déclarés sur un sous pipeline enveloppé sont ignorés.

Les callbacks au niveau pipeline (`onSuccessCallback`, `onFailureCallback`, `onCompleteCallback`) ne se déclenchent qu'à la **terminaison du pipeline extérieur**, une seule fois.

## Compensation

Chaque sous étape interne peut déclarer sa compensation via `Step::make(Sub::class)->compensateWith(Rollback::class)`. Quand une sous étape échoue sous `StopAndCompensate`, la chaîne de compensation remonte l'intégralité des étapes complétées (plates ou internes) dans l'ordre inverse.

La map de compensation (`compensationMapping`) est construite en traversant récursivement le pipeline extérieur, y compris les sous pipelines. Le saga invariant est préservé : toute étape qui s'est exécutée a sa compensation déclarée au build time, disponible dans la map flat.

## Contraintes d'imbrication

Les sous pipelines acceptent toutes les constructions du builder : étapes plates, conditions, groupes parallèles, autres sous pipelines, branches conditionnelles. L'imbrication est récursive sans limite codée en dur.

Les seules interdictions viennent des groupes parallèles (voir [parallel-steps.md](parallel-steps.md)) qui rejettent les `NestedPipeline` et `ConditionalBranch` dans leurs sous étapes.

## Tests

`PipelineFake` enregistre les sous pipelines dans la définition recordée. L'assertion dédiée est `assertNestedPipelineExecuted(array $expectedInnerClasses, ?string $name = null, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    ValidateRequest::class,
    JobPipeline::nest(
        JobPipeline::make([LoadProfile::class, EnrichData::class]),
        name: 'enrichment',
    ),
    FormatResponse::class,
])->send($context)->run();

Pipeline::assertNestedPipelineExecuted(
    [LoadProfile::class, EnrichData::class],
    name: 'enrichment',
);
```

L'assertion vérifie qu'un sous pipeline avec le nom et les classes attendues a été enregistré. Pour inspecter l'ordre réel d'exécution et le contenu du contexte, utilisez le mode recording (`Pipeline::fake()->recording()`) qui exécute les étapes et capture `executedSteps` / `contextSnapshots`.
