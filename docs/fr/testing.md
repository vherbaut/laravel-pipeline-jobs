# Tests

Ce package fournit une boîte à outils de test complète qui suit les mêmes patterns que `Bus::fake()` et `Queue::fake()` de Laravel. Vous disposez d'un test double, de méthodes d'assertion et d'un enregistrement de l'exécution, le tout accessible via la facade `Pipeline`.

## Table des matières

- [Mode Fake](#mode-fake)
- [Mode Recording](#mode-recording)
- [Assertions disponibles](#assertions-disponibles)
- [Snapshots de contexte](#snapshots-de-contexte)
- [Assertions de compensation](#assertions-de-compensation)

## Mode Fake

Le mode fake intercepte toutes les exécutions de pipeline sans réellement exécuter les étapes. Utile quand vous voulez vérifier qu'un pipeline a été dispatché avec la bonne configuration, sans vous soucier de l'exécution des étapes.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

it('dispatche le pipeline de commande', function () {
    $fake = Pipeline::fake();

    // Exécuter le code applicatif qui déclenche un pipeline...
    $service = new OrderService();
    $service->processOrder($order);

    // Vérifier que le pipeline a été dispatché
    $fake->assertPipelineRan();
    $fake->assertPipelineRanWith([
        ProcessOrder::class,
        SendReceipt::class,
    ]);
});
```

## Mode Recording

Le mode recording va plus loin : il exécute réellement les étapes du pipeline (de manière synchrone) tout en capturant une trace d'exécution complète. Vous vérifiez non seulement qu'un pipeline a été dispatché, mais que chaque étape s'est exécutée correctement et a modifié le contexte comme prévu.

Activez le mode recording en appelant `recording()` sur le fake.

```php
it('exécute toutes les étapes et met à jour le contexte', function () {
    $fake = Pipeline::fake()->recording();

    JobPipeline::make([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ])
        ->send(new OrderContext(order: $order))
        ->run();

    $fake->assertStepExecuted(ValidateOrder::class);
    $fake->assertStepExecuted(ChargeCustomer::class);
    $fake->assertStepExecuted(CreateShipment::class);

    $fake->assertStepsExecutedInOrder([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ]);

    $fake->assertContextHas('status', 'shipped');
});
```

## Assertions disponibles

**Assertions au niveau du pipeline** (fonctionnent en mode fake et recording) :

| Méthode | Description |
|---------|-------------|
| `assertPipelineRan(?Closure $callback)` | Au moins un pipeline a été dispatché. Callback optionnel pour filtrer. |
| `assertPipelineRanWith(array $jobs)` | Un pipeline a été dispatché avec exactement ces classes de job. |
| `assertNoPipelinesRan()` | Aucun pipeline n'a été dispatché. |
| `assertPipelineRanTimes(int $count)` | Exactement N pipelines ont été dispatchés. |

**Assertions d'exécution des étapes** (mode recording uniquement) :

| Méthode | Description |
|---------|-------------|
| `assertStepExecuted(string $jobClass)` | Cette étape a été exécutée. |
| `assertStepNotExecuted(string $jobClass)` | Cette étape n'a pas été exécutée. |
| `assertStepsExecutedInOrder(array $jobs)` | Les étapes se sont exécutées exactement dans cet ordre. |

**Assertions de contexte** (mode recording uniquement) :

| Méthode | Description |
|---------|-------------|
| `assertContextHas(string $property, mixed $value)` | La propriété du contexte a cette valeur après exécution. |
| `assertContext(Closure $callback)` | Assertion personnalisée sur le contexte final. |
| `getRecordedContext()` | Récupérer l'objet contexte enregistré. |
| `getContextAfterStep(string $jobClass)` | Récupérer le snapshot du contexte pris après une étape spécifique. |

Toutes les méthodes d'assertion acceptent un paramètre optionnel `?int $pipelineIndex`. Quand il vaut `null` (par défaut), les assertions s'appliquent au dernier pipeline enregistré. Passez un index pour cibler un pipeline spécifique quand plusieurs pipelines ont été dispatchés dans un même test.

## Snapshots de contexte

L'une des fonctionnalités de test les plus puissantes est la possibilité d'inspecter le contexte à n'importe quel moment de l'exécution. Après chaque étape, l'exécuteur recording effectue un clone profond du contexte. Récupérez ces snapshots pour vérifier l'état intermédiaire.

```php
it('débite le client avant de créer l\'expédition', function () {
    $fake = Pipeline::fake()->recording();

    JobPipeline::make([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ])
        ->send(new OrderContext(order: $order))
        ->run();

    // Après ChargeCustomer, invoice doit être défini mais pas shipment
    $afterCharge = $fake->getContextAfterStep(ChargeCustomer::class);
    expect($afterCharge->invoice)->not->toBeNull();
    expect($afterCharge->shipment)->toBeNull();

    // Après CreateShipment, les deux doivent être définis
    $afterShipment = $fake->getContextAfterStep(CreateShipment::class);
    expect($afterShipment->shipment)->not->toBeNull();
});
```

Inestimable pour vérifier que chaque étape fait sa part correctement, indépendamment des autres étapes.

## Assertions de compensation

Pour tester les patterns saga, vérifiez que les bons jobs de compensation s'exécutent (ou ne s'exécutent pas) quand des échecs surviennent.

```php
it('compense les étapes complétées en cas d\'échec', function () {
    $fake = Pipeline::fake()->recording();

    try {
        JobPipeline::make()
            ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
            ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
            ->step(FailingStep::class)->compensateWith(UndoFailingStep::class)
            ->send(new OrderContext(order: $order))
            ->run();
    } catch (StepExecutionFailed) {
        // Attendu
    }

    $fake->assertCompensationWasTriggered();

    // Les étapes complétées ont été compensées dans l'ordre inverse
    $fake->assertCompensationRan(RefundCustomer::class);
    $fake->assertCompensationRan(ReleaseInventory::class);

    // La compensation de l'étape échouée n'a PAS été exécutée (elle n'a jamais été complétée)
    $fake->assertCompensationNotRan(UndoFailingStep::class);

    $fake->assertCompensationExecutedInOrder([
        RefundCustomer::class,
        ReleaseInventory::class,
    ]);
});
```

**Assertions de compensation** (mode recording uniquement) :

| Méthode | Description |
|---------|-------------|
| `assertCompensationWasTriggered()` | La compensation a été déclenchée durant l'exécution. |
| `assertCompensationNotTriggered()` | Aucune compensation n'a été déclenchée. |
| `assertCompensationRan(string $jobClass)` | Ce job de compensation spécifique a été exécuté. |
| `assertCompensationNotRan(string $jobClass)` | Ce job de compensation spécifique n'a pas été exécuté. |
| `assertCompensationExecutedInOrder(array $jobs)` | Les jobs de compensation se sont exécutés exactement dans cet ordre. |

## Tester les hooks de cycle de vie

Les hooks de cycle de vie se déclenchent de manière identique sous `Pipeline::fake()->recording()`. Aucune assertion dédiée aux hooks n'est livrée aujourd'hui. Utilisez des flags booléens locaux ou des closures espion. Voir [Hooks de cycle de vie](lifecycle-hooks.md#tester-les-hooks) pour des exemples.
