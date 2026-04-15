# Pattern Saga (Compensation)

Dans les systèmes distribués, quand un processus multi étapes échoue en cours de route, il est souvent nécessaire d'annuler les étapes déjà complétées. C'est le **pattern saga**, et il est intégré directement dans le pipeline builder.

```php
use Vherbaut\LaravelPipelineJobs\Enums\FailStrategy;

JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(CreateShipment::class)->compensateWith(CancelShipment::class)
    ->onFailure(FailStrategy::StopAndCompensate)
    ->send(new OrderContext(order: $order))
    ->run();
```

## Table des matières

- [Stratégies d'échec](#stratégies-déchec)
- [Comment StopAndCompensate fonctionne](#comment-stopandcompensate-fonctionne)
- [Comment SkipAndContinue fonctionne](#comment-skipandcontinue-fonctionne)
- [Écrire un job de compensation](#écrire-un-job-de-compensation)
- [Inspecter l'échec](#inspecter-léchec)
- [Observabilité en cas d'échec de compensation](#observabilité-en-cas-déchec-de-compensation)

## Stratégies d'échec

Tout pipeline déclare sa gestion des échecs via `onFailure(FailStrategy)`. Le défaut est `StopImmediately`, ce qui signifie qu'un pipeline avec uniquement des `compensateWith()` et sans appel à `onFailure()` ne déclenche **pas** la compensation. Il faut l'activer explicitement.

| Stratégie | Comportement en cas d'échec d'une étape |
|-----------|------------------------------------------|
| `FailStrategy::StopImmediately` (défaut) | Relance l'échec sous forme de `StepExecutionFailed`. Aucune compensation ne s'exécute. |
| `FailStrategy::StopAndCompensate` | Exécute la chaîne de compensation en ordre inverse sur les étapes complétées, puis relance `StepExecutionFailed`. |
| `FailStrategy::SkipAndContinue` | Logue un avertissement, ignore l'étape fautive et reprend avec la suivante. Le pipeline ne lève pas d'exception. Aucune compensation ne s'exécute. |

## Comment StopAndCompensate fonctionne

1. Les étapes s'exécutent dans l'ordre : `ReserveInventory`, puis `ChargeCustomer`, puis `CreateShipment`.
2. Si `CreateShipment` lève une exception, la compensation se déclenche.
3. Seules les étapes **complétées** sont compensées, dans l'**ordre inverse** : `RefundCustomer` d'abord, puis `ReleaseInventory`.
4. `CancelShipment` n'est **pas** appelé car `CreateShipment` n'a jamais été complété.
5. L'exception d'origine est relancée sous forme de `StepExecutionFailed` une fois la chaîne terminée (best effort en synchrone, la chaîne s'arrête à la première compensation qui lève en queued).

## Comment SkipAndContinue fonctionne

`SkipAndContinue` est utile pour les pipelines tolérants où l'échec d'une étape ne doit pas interrompre toute l'exécution. Quand une étape lève :

1. L'échec est enregistré sur le manifest (`failedStepClass`, `failedStepIndex`, `failureException`).
2. Un `Log::warning('Pipeline step skipped under SkipAndContinue', [...])` est émis avec l'identifiant du pipeline, la classe de l'étape, son index et le message de l'exception.
3. Le pipeline avance à l'étape suivante et continue son exécution.
4. **Aucune compensation** ne tourne pour les étapes sautées, même si `compensateWith()` a été déclaré.
5. Si une étape ultérieure réussit, les champs d'échec sont effacés. Si une autre étape échoue, la dernière est retenue (last failure wins).

```php
JobPipeline::make()
    ->step(FetchRemoteData::class)
    ->step(ParseOptionalSection::class) // peut échouer, sera sauté
    ->step(PersistResults::class)
    ->onFailure(FailStrategy::SkipAndContinue)
    ->send(new ImportContext)
    ->run();
```

## Écrire un job de compensation

**Avec le trait (forme identique à une étape normale).** L'approche historique : le manifest est injecté via le trait `InteractsWithPipeline`, puis `handle()` est implémenté.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class RefundCustomer
{
    use InteractsWithPipeline;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineContext();
        $payments->refund($context->invoice);

        // Optionnel : inspecter pourquoi le pipeline a échoué
        $failure = $this->failureContext();
        if ($failure !== null) {
            logger()->info("Compensation déclenchée après {$failure->failedStepClass}");
        }
    }
}
```

**Avec le contrat (recommandé pour le code neuf).** Implémentez l'interface `CompensableJob`. L'exécuteur invoque `compensate()` avec le contexte et, optionnellement, un snapshot `FailureContext`.

```php
use Vherbaut\LaravelPipelineJobs\Context\FailureContext;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;
use Vherbaut\LaravelPipelineJobs\Contracts\CompensableJob;

class RefundCustomer implements CompensableJob
{
    public function __construct(private PaymentService $payments) {}

    public function compensate(PipelineContext $context, ?FailureContext $failure = null): void
    {
        $this->payments->refund($context->invoice);
    }
}
```

Les implémentations peuvent conserver la signature à un seul argument (`compensate(PipelineContext $context)`). L'exécuteur inspecte la signature via la réflexion et ne passe le `FailureContext` qu'aux implémentations qui élargissent à deux paramètres.

## Inspecter l'échec

`FailureContext` est un value object readonly qui expose :

| Propriété | Type | Description |
|-----------|------|-------------|
| `failedStepClass` | `string` | FQCN de l'étape qui a levé. |
| `failedStepIndex` | `int` | Index (base zéro) de l'étape fautive. |
| `exception` | `?\Throwable` | L'exception d'origine (non null en sync, toujours null en queued selon NFR19, car `Throwable` est exclu du payload sérialisé). |

On y accède depuis n'importe quel job de compensation.

- **Jobs contract based** : second argument optionnel de `compensate()`.
- **Jobs trait based** : `$this->failureContext()` à l'intérieur de `handle()`.

## Observabilité en cas d'échec de compensation

Quand un job de compensation lève lui même :

- **Pipelines synchrones.** Un `Log::error('Pipeline compensation failed', [...])` est émis et l'event `Vherbaut\LaravelPipelineJobs\Events\CompensationFailed` est dispatché. L'exception de la compensation est absorbée pour que les compensations restantes continuent (sémantique best effort).
- **Pipelines en file.** Le wrapper atterrit dans `failed_jobs` avec l'enregistrement Laravel standard. Après épuisement des tentatives (`$tries = 1`), le hook `failed()` émet un `Log::error('Pipeline compensation failed after retries', [...])` et dispatche le même event `CompensationFailed`. Le `Bus::chain` s'arrête sur la première compensation qui lève (divergence documentée avec le best effort synchrone).

L'event `CompensationFailed` est émis **inconditionnellement**, indépendamment de tout opt in à d'autres events pipeline. Il est conçu pour l'alerting opérationnel.

```php
use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;

Event::listen(CompensationFailedEvent::class, function (CompensationFailedEvent $event): void {
    // $event->pipelineId
    // $event->compensationClass
    // $event->failedStepClass (nullable, string en production)
    // $event->originalException (null en queued, Throwable en sync)
    // $event->compensationException (Throwable, toujours non null)
    Sentry::captureMessage("Rollback échoué : {$event->compensationClass}");
});
```

> La classe d'exception `Vherbaut\LaravelPipelineJobs\Exceptions\CompensationFailed` partage son basename avec l'event. Quand les deux sont importés dans le même fichier, utilisez un alias : `use Vherbaut\LaravelPipelineJobs\Events\CompensationFailed as CompensationFailedEvent;`.
