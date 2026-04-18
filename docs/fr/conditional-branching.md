# Branchement conditionnel

Un pipeline doit parfois choisir **un chemin parmi plusieurs** en fonction de l'état du contexte au moment de l'exécution. Les branches conditionnelles modélisent ce pattern : un sélecteur (closure) retourne une clé, et le pipeline exécute l'étape associée à cette clé. Après la branche sélectionnée, le pipeline converge sur l'étape extérieure suivante.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;
use Vherbaut\LaravelPipelineJobs\Step;

JobPipeline::make([
    ValidateOrder::class,
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType,
        [
            'b2b' => ProcessB2BOrder::class,
            'b2c' => ProcessB2COrder::class,
            'reseller' => ProcessResellerOrder::class,
        ],
    ),
    SendConfirmation::class,
])
    ->send(new OrderContext(order: $order))
    ->run();
```

Selon la valeur de `customerType`, une seule des trois branches tourne. `SendConfirmation` s'exécute ensuite quelle que soit la branche choisie (sémantique de convergence FR27).

## Table des matières

- [Deux factories équivalentes](#deux-factories-équivalentes)
- [Valeurs de branches acceptées](#valeurs-de-branches-acceptées)
- [Sémantique du sélecteur](#sémantique-du-sélecteur)
- [Exécution en queue](#exécution-en-queue)
- [Sous pipelines comme valeurs de branches](#sous-pipelines-comme-valeurs-de-branches)
- [Imbrications autorisées](#imbrications-autorisées)
- [FailStrategy et échecs du sélecteur](#failstrategy-et-échecs-du-sélecteur)
- [Compensation et map partagée](#compensation-et-map-partagée)
- [Hooks et callbacks](#hooks-et-callbacks)
- [Contraintes du payload](#contraintes-du-payload)
- [Tests](#tests)

## Deux factories équivalentes

`Step::branch(...)` est la factory privilégiée dans un tableau `make([...])` :

```php
JobPipeline::make([
    A::class,
    Step::branch(fn ($ctx) => $ctx->type, ['x' => StepX::class, 'y' => StepY::class]),
    C::class,
]);
```

`JobPipeline::branch(...)` est son alias strict, utile si le fichier importe déjà `JobPipeline` mais pas `Step`.

L'API fluide expose `->branch(...)` sur le builder :

```php
JobPipeline::make()
    ->step(A::class)
    ->branch(fn ($ctx) => $ctx->type, ['x' => StepX::class, 'y' => StepY::class])
    ->step(C::class)
    ->run();
```

Les trois formes produisent strictement le même `ConditionalBranch`. Le troisième argument optionnel `name` sert à l'observabilité et aux assertions de test.

## Valeurs de branches acceptées

Une valeur de branche peut être :

| Forme | Comportement |
|-------|--------------|
| `class-string` | Enveloppée automatiquement via `StepDefinition::fromJobClass($class)`. |
| `StepDefinition` pré construit | Conservé tel quel (préserve compensation, retry, queue, etc.). |
| `NestedPipeline` | Un sous pipeline complet s'exécute si la branche est sélectionnée. |
| `PipelineBuilder` | Auto emballé dans un `NestedPipeline`. |
| `PipelineDefinition` | Auto emballé dans un `NestedPipeline`. |

Les `ParallelStepGroup` sont **rejetés** à la construction (`InvalidPipelineDefinition::parallelInsideConditionalBranch()`). Pour combiner parallèle et branche, wrappez le parallèle dans un `NestedPipeline` et passez ce sous pipeline comme valeur de branche.

Les clés doivent être des strings non vides, non composées uniquement d'espaces. Un tableau auto indexé (clés numériques) est rejeté via `InvalidPipelineDefinition::blankBranchKey()`.

## Sémantique du sélecteur

Le sélecteur est une `Closure(PipelineContext): string`. Il est évalué **une seule fois** au moment où la branche est sur le point de s'exécuter :

- En mode synchrone : inline dans `SyncExecutor::executeConditionalBranch`.
- En mode queue : sur le wrapper de la branche, avant le dispatch du wrapper suivant. Le manifest est ensuite réécrit (pattern **rebrand then dispatch**) pour remplacer la branche par la valeur sélectionnée. Les wrappers suivants voient une étape plate ou un sous pipeline normal, jamais une branche.

Cette garantie "exactement une fois" est importante pour les sélecteurs avec effets de bord (logging, lookup cache, compteur métrique) : ils ne sont pas rejoués en cas de retry d'un sous job.

Le sélecteur reçoit le `PipelineContext` vivant (avec les mutations des étapes précédentes). Il peut lire mais doit éviter de le muter (les mutations du sélecteur persistent et peuvent rendre le comportement non prédictible).

## Exécution en queue

En mode queue, le sélecteur étant sérialisé avec le manifest, il doit être **sérialisable**. Le builder l'emballe automatiquement dans `SerializableClosure`. Cela implique :

1. Les variables capturées via `use(...)` doivent être sérialisables (pas de ressources, de connexions DB actives, de classes anonymes).
2. Un sélecteur capturant `$this` depuis une classe non sérialisable échoue à l'enqueue.

Préférez des sélecteurs purs qui n'utilisent que le `PipelineContext` :

```php
// Bien : le sélecteur lit uniquement le contexte.
Step::branch(fn (OrderContext $ctx) => $ctx->order->priority, [...]);

// À éviter : capture de $this non sérialisable.
// Step::branch(fn ($ctx) => $this->resolver->decide($ctx), [...]);
```

Quand la décision dépend d'un état externe, chargez cet état dans une étape antérieure, stockez le sur le contexte, et lisez le depuis le sélecteur.

## Sous pipelines comme valeurs de branches

Une valeur de branche peut être un sous pipeline complet. Le mécanisme de cursor imbriqué (voir [pipeline-nesting.md](pipeline-nesting.md)) prend le relais :

```php
Step::branch(
    fn (OrderContext $ctx) => $ctx->order->shippingMode,
    [
        'express' => JobPipeline::make([
            ReservePriorityCarrier::class,
            NotifyExpressWarehouse::class,
            ScheduleSameDayPickup::class,
        ]),
        'standard' => JobPipeline::make([
            ReserveStandardCarrier::class,
            NotifyWarehouse::class,
        ]),
    ],
);
```

Si `shippingMode === 'express'`, les trois étapes du sous pipeline express tournent séquentiellement et partagent le contexte extérieur. L'étape extérieure suivant la branche s'exécute ensuite.

## Imbrications autorisées

| Composition | Support |
|-------------|---------|
| Branche à la racine | ✅ |
| Branche dans un sous pipeline (branch inside nested) | ✅ |
| Sous pipeline comme valeur de branche (nested as branch value) | ✅ |
| Branche imbriquée dans une autre branche (via sous pipeline) | ✅ récursif |
| Parallèle comme valeur de branche | ❌ wrappez dans un `NestedPipeline` |
| Branche dans un groupe parallèle | ❌ rejetée au build time |

La restriction parallèle / branche vient de la sémantique "une seule branche gagne" qui contredit le deep clone par sous étape de `Bus::batch()`.

## FailStrategy et échecs du sélecteur

Les trois stratégies du pipeline extérieur s'appliquent à toutes les défaillances de la branche, y compris celles du sélecteur (throw, retour non string, clé inconnue) :

| Stratégie | Comportement sur échec sélecteur | Comportement sur échec de la branche sélectionnée |
|-----------|----------------------------------|---------------------------------------------------|
| `StopImmediately` (défaut) | Relance `StepExecutionFailed` englobant la cause. Les callbacks `onFailure`/`onComplete` se déclenchent. | Idem, avec l'étape fautive comme contexte. |
| `StopAndCompensate` | Déclenche la chaîne de compensation sur les étapes **antérieures** complétées, puis relance. | Idem, plus la sous étape fautive si elle avait eu le temps de compléter. |
| `SkipAndContinue` | Logue un warning, avance au delà de la branche, continue avec l'étape extérieure suivante. | Idem. |

```php
JobPipeline::make([
    Step::make(ReserveStock::class)->withCompensation(ReleaseStock::class),
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType, // peut lever
        ['b2b' => B2BPath::class, 'b2c' => B2CPath::class],
    ),
    SendConfirmation::class,
])
    ->onFailure(FailStrategy::StopAndCompensate)
    ->send($context)
    ->run();

// Si le sélecteur throw : ReleaseStock est déclenchée (saga préservée),
// puis StepExecutionFailed est relancée.
```

Aucun hook `onStepFailed` ne se déclenche pour les échecs du sélecteur (le sélecteur est infrastructure, pas une étape utilisateur). Les callbacks au niveau pipeline (`onFailure`, `onComplete`) se déclenchent normalement.

## Compensation et map partagée

La map de compensation (`compensationMapping`) est construite au build time par fusion de **toutes les branches**. Au runtime, seule une branche tourne, mais la map inclut les compensations de toutes les alternatives.

Cas edge à connaître : si deux branches déclarent la **même classe** de job avec des compensations **différentes**, `array_merge` applique la sémantique "dernière déclarée gagne". Si la première branche tourne et échoue sous `StopAndCompensate`, la compensation invoquée est celle de la **dernière** branche déclarée, pas celle de la branche exécutée.

```php
// À éviter : FooJob avec deux compensations différentes selon la branche.
Step::branch(fn ($ctx) => $ctx->path, [
    'a' => Step::make(FooJob::class)->withCompensation(CompensateA::class),
    'b' => Step::make(FooJob::class)->withCompensation(CompensateB::class),
]);
// Si la branche 'a' tourne et échoue, CompensateB est invoquée (map["FooJob"] = CompensateB::class).
```

La parade est d'utiliser des classes de job **distinctes** par branche (chacune avec sa compensation appropriée) ou d'accepter la sémantique documentée.

## Hooks et callbacks

Les hooks du pipeline extérieur (`beforeEachHooks`, `afterEachHooks`, `onStepFailedHooks`) se déclenchent pour **l'étape réellement exécutée** de la branche sélectionnée (ou pour chaque sous étape si la valeur est un sous pipeline). Les hooks déclarés dans un sous pipeline qui sert de valeur de branche sont ignorés (règle d'héritage cohérente avec [pipeline-nesting.md](pipeline-nesting.md)).

Les callbacks de terminaison (`onSuccessCallback`, `onFailureCallback`, `onCompleteCallback`) se déclenchent **une seule fois** à la fin du pipeline extérieur.

## Contraintes du payload

Le manifest transporte la description **complète** de toutes les branches (sélecteur emballé en `SerializableClosure` plus chaque valeur de branche avec sa propre configuration et ses conditions). C'est un coût proportionnel à la taille cumulée, indépendant de la branche finalement exécutée.

Taille approximative (par branche) :

```
size(selector_closure) + sum_k(size(branch_value_k) + size(branch_config_k) + size(branch_condition_k))
```

Pour des pipelines en queue proches de la limite SQS (256 Ko, NFR11), réduisez le nombre de branches ou extrayez les plus complexes en sous pipelines enveloppés que vous chargez dynamiquement en amont.

## Tests

`PipelineFake` enregistre les branches dans la définition recordée. L'assertion dédiée est `assertConditionalBranchExecuted(array $expectedKeys, ?string $name = null, ?int $pipelineIndex = null)` :

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([
    ValidateOrder::class,
    Step::branch(
        fn (OrderContext $ctx) => $ctx->order->customerType,
        ['b2b' => B2BPath::class, 'b2c' => B2CPath::class],
        name: 'customer-routing',
    ),
])->send($context)->run();

Pipeline::assertConditionalBranchExecuted(['b2b', 'b2c'], name: 'customer-routing');
```

L'assertion vérifie que les clés déclarées (dans l'ordre d'insertion) correspondent à `$expectedKeys`. Pour une exécution réelle (quelle branche a tourné, avec quel contexte), utilisez `Pipeline::fake()->recording()` qui réplique la sémantique de `SyncExecutor` (sélecteur évalué, branche exécutée, snapshots capturés) sans toucher au `Bus::batch()` de la queue.
