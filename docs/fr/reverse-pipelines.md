# Pipelines inversés

`PipelineBuilder::reverse()` produit un nouveau pipeline dont **l'ordre des positions extérieures est inversé**. Utile pour les workflows de type undo, les tests miroir (run forward suivi d'un run reverse), et les orchestrations de rollback qui ne peuvent pas utiliser le contrat `compensateWith()` du saga (voir [Compensation Saga](saga-compensation.md)).

## Exemple rapide

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$forward = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
]);

$forward->send(new OrderContext(order: $order))->run();

// Plus tard, exécuter les mêmes steps en ordre inverse.
$forward->reverse()->send(new OrderContext(order: $order))->run();
// Ordre effectif : SendConfirmation, ReserveInventory, ChargeCustomer, ValidateOrder.
```

`reverse()` retourne une **nouvelle** instance de `PipelineBuilder`. Le builder original n'est pas modifié, vous pouvez conserver les deux variantes côte à côte.

## Ce qui est inversé

Seules les **positions extérieures** du pipeline sont inversées. Les structures internes (groupes parallèles, pipelines imbriqués, branches conditionnelles) sont préservées **telles quelles**.

```php
$builder = JobPipeline::make()
    ->step(StepA::class)
    ->parallel([SubA::class, SubB::class])
    ->nest(JobPipeline::make([InnerA::class, InnerB::class]))
    ->step(StepZ::class);

$builder->reverse();
// Ordre extérieur effectif : StepZ, NestedPipeline([InnerA, InnerB]), ParallelGroup([SubA, SubB]), StepA.
// Les sous steps du groupe parallèle restent [SubA, SubB].
// Les steps internes du pipeline imbriqué restent [InnerA, InnerB].
```

Si vous voulez inverser aussi l'intérieur, appelez `->reverse()` sur le builder interne avant de l'imbriquer.

## Ce qui est préservé

Tous les champs de niveau pipeline sont copiés tels quels sur le builder inversé.

- Contexte de `send()` (instance ou closure).
- Flag `shouldBeQueued()`.
- Flag `dispatchEvents()`.
- Callback `return()`.
- `FailStrategy` (`StopImmediately`, `StopAndCompensate`, `SkipAndContinue`).
- Queue, connexion, retry, backoff, timeout par défaut.
- Tous les hooks de cycle de vie (`beforeEach`, `afterEach`, `onStepFailed`).
- Callbacks de niveau pipeline (`onSuccess`, `onFailure`, `onComplete`).
- Gates d'admission (`rateLimit`, `maxConcurrent`).

La configuration par step portée par chaque `StepDefinition` (queue, retry, `when`, `compensateWith`, etc.) voyage aussi avec le step. Un step portant un prédicat `when()` évalue toujours ce prédicat au runtime contre le contexte vivant, à sa **nouvelle** position dans le pipeline inversé.

## Interaction avec la compensation

La compensation suit l'ordre d'exécution, pas l'ordre de déclaration. Dans un pipeline inversé, la chaîne de compensation remonte les steps **réellement exécutés**. Donc un pipeline inversé qui échoue au step 3 compense les steps 1 et 2 de l'exécution inversée (qui sont les deux derniers steps de la déclaration originale).

C'est cohérent avec l'implémentation du pattern saga et ne nécessite aucun traitement spécial.

## Tests

`FakePipelineBuilder::reverse()` reproduit le builder réel, donc `Pipeline::fake()` fonctionne avec les pipelines inversés de façon transparente.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::fake();

Pipeline::make([StepA::class, StepB::class, StepC::class])
    ->reverse()
    ->run();

Pipeline::assertPipelineRanWith([StepC::class, StepB::class, StepA::class]);
```

En `Pipeline::fake()->recording()`, l'exécution réelle suit l'ordre inversé et `assertStepsExecutedInOrder([...])` accepte la liste des classes dans l'ordre inversé.

## Quand préférer `compensateWith()`

`reverse()` exécute les **mêmes classes de step** dans l'ordre inverse. Ça n'invoque pas une classe de rollback dédiée par step. Quand chaque step forward a une opération d'undo distincte (refund, release, notify), utilisez `compensateWith()` sur chaque step pour que les échecs déclenchent la chaîne de compensation automatiquement. Voir [Compensation Saga](saga-compensation.md) pour ce contrat.

Utilisez `reverse()` quand la logique forward et la logique undo vivent dans la même classe (steps idempotents avec un flag de direction, symétrie setup/teardown en test, migration roll forward puis roll back), ou quand vous avez besoin d'inspecter la définition inversée séparément d'un rollback piloté par échec.
