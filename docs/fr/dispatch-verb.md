# Verbe Dispatch

`Pipeline::dispatch([...])` est un verbe d'exécution alternatif à `Pipeline::make([...])->run()`. Il reprend l'idiome familier `Bus::dispatch($job)` de Laravel en auto-exécutant le pipeline quand le wrapper retourné sort de son scope. Plus besoin de se souvenir d'un `->run()` terminal.

```php
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Pipeline::dispatch([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])->send(new OrderContext(order: $order));
```

Le wrapper temporaire `PendingPipelineDispatch` est détruit à la fin de l'instruction, et son destructeur invoque la méthode `run()` du builder sous-jacent exactement une fois.

## Table des matières

- [Quand utiliser Dispatch](#quand-utiliser-dispatch)
- [Surface fluide](#surface-fluide)
- [Timing du destructeur](#timing-du-destructeur)
- [Annuler un dispatch en attente](#annuler-un-dispatch-en-attente)
- [Matrice de décision Dispatch vs Make](#matrice-de-décision-dispatch-vs-make)
- [Tests avec Pipeline::fake()](#tests-avec-pipelinefake)
- [Propagation d'exceptions](#propagation-dexceptions)
- [Dangers connus](#dangers-connus)

## Quand utiliser Dispatch

Utilisez `Pipeline::dispatch()` quand :

- Vous voulez une exécution fire-and-forget sans besoin de récupérer une valeur de retour.
- Vous préférez le style `Bus::dispatch($job)` au terminateur explicite `->run()`.
- Vous dispatchez un pipeline sur la queue depuis une action de controller ou une méthode de service.

Restez sur `Pipeline::make()->run()` quand :

- Vous avez besoin du `PipelineContext` final comme valeur de retour.
- Vous avez enregistré un callback `->return(Closure)` et vous voulez son résultat.
- Vous avez besoin d'un timing d'exécution déterministe sans dépendre du destructeur.

## Surface fluide

Chaque méthode fluide non terminale de `PipelineBuilder` est disponible sur le wrapper de dispatch. Les méthodes terminales ou de définition suivantes ne sont volontairement pas exposées :

- `run()` : le verbe dispatch déclenche l'exécution via le destructeur.
- `toListener()` : utilisez `Pipeline::listen()` pour enregistrer un event listener.
- `build()` : verbe de définition interne.
- `return()` : dispatch ignore la valeur de retour par conception.
- `getContext()` : l'inspection du contexte appartient à un builder conservé.

Toutes les méthodes de configuration par étape de l'épic 7 sont disponibles : `onQueue`, `onConnection`, `sync`, `retry`, `backoff`, `timeout`, ainsi que les valeurs par défaut au niveau pipeline. Voir [Configuration par étape](per-step-configuration.md).

```php
Pipeline::dispatch([
    ValidateOrder::class,
    ChargeCustomer::class,
])
    ->defaultQueue('orders')
    ->defaultRetry(2)
    ->step(LogSuccess::class)->onQueue('logs')  // on peut continuer à ajouter des étapes
    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->onFailure(FailStrategy::StopAndCompensate);
```

## Timing du destructeur

Le destructeur PHP se déclenche quand le compteur de référence du wrapper atteint zéro. Le timing exact dépend de la façon dont l'expression est utilisée.

**Instruction bare (recommandé).** Le wrapper temporaire est détruit à la fin de l'instruction, avant que la suivante ne commence. C'est la forme idiomatique, qui correspond à `Bus::dispatch($job);`.

```php
Pipeline::dispatch([A::class])->send($ctx);
// Le destructeur se déclenche ici. L'exécution est strictement avant l'instruction suivante.
doOtherWork();
```

**Assignation à une variable (déconseillé).** L'exécution est reportée jusqu'à ce que la variable sorte du scope.

```php
$pending = Pipeline::dispatch([A::class])->send($ctx);
doOtherWork();
// Le destructeur ne s'est PAS encore déclenché.
// Fin du scope englobant (fonction, méthode, closure) : le destructeur se déclenche.
```

**Unset explicite.** Force le destructeur à se déclencher immédiatement.

```php
$pending = Pipeline::dispatch([A::class])->send($ctx);
unset($pending);
// Le destructeur se déclenche ici.
```

Si vous capturez le wrapper dans une variable, préférez `Pipeline::make()->run()` pour un timing déterministe.

## Annuler un dispatch en attente

Appelez `cancel()` pour retirer un wrapper de son contrat d'auto-exécution. Le destructeur court-circuite et le builder sous-jacent n'est jamais exécuté.

```php
$pending = Pipeline::dispatch([ChargeCustomer::class])->send($ctx);

if ($order->wasRefunded()) {
    $pending->cancel();
    return;
}

// Le destructeur se déclenche en fin de scope et exécute le pipeline normalement.
```

`cancel()` est idempotent et peut être appelé plusieurs fois sans risque. Une fois appelé, le wrapper devient inerte.

## Matrice de décision Dispatch vs Make

| Cas d'usage | Verbe recommandé | Raison |
|-------------|------------------|--------|
| Exécution sync fire-and-forget | `Pipeline::dispatch([...])->send(...)` | Familier pour qui connaît `Bus::dispatch($job)` |
| Exécution queued fire-and-forget | `Pipeline::dispatch([...])->send(...)->shouldBeQueued()` | Même idiome pour le mode queue |
| Besoin du `PipelineContext` final | `Pipeline::make([...])->send(...)->run()` | `dispatch()` ignore la valeur de retour |
| Besoin du résultat d'un callback `->return()` | `Pipeline::make([...])->send(...)->return($cb)->run()` | `dispatch()` n'expose pas `return()` |
| Enregistrement d'un event listener | `Pipeline::listen($eventClass, [...])` | Non impacté par le verbe dispatch |
| Tests avec `Pipeline::fake()` | Les deux verbes | Le fake les enregistre de manière identique |

## Tests avec Pipeline::fake()

Sous `Pipeline::fake()`, `Pipeline::dispatch()` enregistre le pipeline exactement comme `Pipeline::make()->run()`. Toutes les assertions du fake (`assertPipelineRan`, `assertStepExecuted`, `assertContextHas`, etc.) fonctionnent de manière identique, quel que soit le verbe utilisé.

```php
Pipeline::fake();

Pipeline::dispatch([A::class, B::class])->send(new OrderContext(order: $order));

Pipeline::assertPipelineRan();
Pipeline::assertPipelineRanWith([A::class, B::class]);
```

Le mode recording (`Pipeline::fake()->recording()`) fonctionne également de manière transparente avec le verbe dispatch. Voir [Tests](testing.md).

## Propagation d'exceptions

Les exceptions levées par le `run()` du builder enveloppé propagent verbatim hors du destructeur. PHP 7+ autorise les exceptions dans les destructeurs durant l'exécution normale.

```php
try {
    Pipeline::dispatch([ValidateOrder::class])->send($ctx);
} catch (StepExecutionFailed $e) {
    // Gérer l'échec normalement.
}
```

Si une méthode fluide (par exemple `->send()` avec une closure invalide) lève une exception avant la fin du scope, le destructeur déclenche quand même `run()` sur le builder partiellement configuré. Cela peut lever une seconde exception qui masque la première. Les appelants qui ont besoin d'une visibilité déterministe sur les exceptions devraient préférer `Pipeline::make()->run()`, ou appeler `cancel()` sur le wrapper dans un bloc catch avant la fin du scope.

## Dangers connus

Le modèle d'exécution piloté par destructeur a des limitations bien connues dans des contextes d'exécution PHP non standards.

**`exit()` et `die()`.** PHP ne garantit pas l'invocation du destructeur lors d'un arrêt de process pour les variables assignées. Un pipeline assigné à `$pending` suivi d'un `exit(302)` peut être silencieusement perdu. Préférez la forme bare-statement ou `Pipeline::make()->run()` dans les handlers qui peuvent sortir tôt.

**`pcntl_fork()` (Laravel Octane, Horizon, daemons longue durée).** Si un wrapper est vivant au moment du fork, le parent ET l'enfant déclenchent chacun son destructeur, doublant le dispatch. Préférez `Pipeline::make()->run()` dans les contextes avec fork, ou appelez `cancel()` sur le wrapper avant le fork.

**Shutdown PHP avec wrappers détenus par un static ou par le container.** Le destructeur peut se déclencher après que le container Laravel ait été démonté, causant une `BindingResolutionException` à l'intérieur d'une frame de destructeur (fatale, non catchable). N'attachez pas de dispatches en attente à des propriétés statiques ou à des singletons liés au cycle de vie de la requête.

Ces dangers reflètent le comportement du `PendingDispatch` natif de Laravel. Pour une exécution déterministe, `Pipeline::make()->run()` reste disponible et préférable dans tous les cas qui sortent de l'idiome bare-statement.
