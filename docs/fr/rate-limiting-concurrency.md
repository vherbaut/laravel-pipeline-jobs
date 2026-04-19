# Rate limiting et contrôle de concurrence

Un pipeline peut porter deux gates d'admission orthogonales évaluées **avant l'exécution du premier step**.

- `rateLimit(key, max, perSeconds)` borne combien de fois le pipeline peut être exécuté dans une fenêtre glissante.
- `maxConcurrent(key, limit)` borne combien d'instances du pipeline peuvent tourner **simultanément**.

Quand une des deux gates rejette l'admission, le pipeline lève une exception **avant** qu'un step ne s'exécute, qu'un hook ne se déclenche, qu'un événement ne soit dispatché. Le contrat est atomique, un pipeline rejeté est un no op sur toutes les surfaces observables.

## Rate limiting

`rateLimit()` s'intègre avec la facade `RateLimiter` de Laravel.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    FetchRemoteData::class,
    ProcessData::class,
])
    ->rateLimit('remote-api', max: 60, perSeconds: 60)
    ->send(new DataContext())
    ->run();
```

Le pipeline ci dessus peut s'exécuter au plus 60 fois par 60 secondes sur la clé `'remote-api'`. Quand le quota est épuisé, `run()` (ou `toListener()`) lève `PipelineThrottled` avec le délai retry after retourné par `RateLimiter::availableIn()`.

```php
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;

try {
    $pipeline->run();
} catch (PipelineThrottled $throttled) {
    return response()->json([
        'error'       => 'too-many-pipelines',
        'retry_after' => $throttled->retryAfterSeconds,
    ], 429);
}
```

## Concurrence maximale

`maxConcurrent()` utilise un compteur atomique backé par le Cache pour limiter les pipelines in flight simultanés.

```php
JobPipeline::make([
    ExpensiveReport::class,
])
    ->maxConcurrent('expensive-reports', limit: 4)
    ->send(new ReportContext($definition))
    ->run();
```

Seules quatre instances du pipeline report peuvent tourner simultanément sur la même clé. La cinquième tentative lève `PipelineConcurrencyLimitExceeded`. À l'admission, le compteur est incrémenté. À la sortie terminale (succès, échec, ou fin de compensation), le slot est libéré.

### Driver de cache requis

`maxConcurrent()` repose sur `Cache::increment()` atomique entre workers. Drivers qui garantissent l'atomicité :

- Redis.
- Memcached.
- Database.

Drivers qui ne la garantissent **pas** (et qui ne doivent donc **pas** être utilisés avec `maxConcurrent()` en production) :

- `file`.
- `array`.

Le compteur est namespacé sous `pipeline:concurrent:<key>` avec un TTL de sécurité de `max(3600, limit * 60)` secondes pour qu'un slot jamais libéré par un worker crashé soit finalement récupéré.

## Clés dynamiques via Closure

Les deux méthodes acceptent une clé string OU une `Closure(?PipelineContext): string` pour résoudre la clé au runtime.

```php
JobPipeline::make([SendReport::class])
    ->rateLimit(
        fn (?ReportContext $ctx) => 'report:tenant:'.$ctx->tenantId,
        max: 10,
        perSeconds: 60,
    )
    ->maxConcurrent(
        fn (?ReportContext $ctx) => 'report:tenant:'.$ctx->tenantId.':inflight',
        limit: 2,
    )
    ->send(new ReportContext($definition, $tenantId))
    ->run();
```

Les closures se déclenchent **exactement une fois par tentative d'admission**, après que `send()` a résolu le contexte. Elles doivent retourner une chaîne non vide. Tout autre type lève `InvalidPipelineDefinition` à l'admission. Les throws de la closure propagent verbatim au caller.

## Composition

Les deux gates peuvent être posées sur le même pipeline.

```php
JobPipeline::make([SendEmail::class])
    ->rateLimit('email', max: 1_000, perSeconds: 3_600)   // 1 000 emails / heure
    ->maxConcurrent('email', limit: 10)                    // 10 workers max
    ->run();
```

L'ordre d'évaluation est déterministe, **le rate limit passe en premier**. Une tentative rate limited lève `PipelineThrottled` sans consommer de slot de concurrence. Ce n'est que lorsque le rate limit passe que `maxConcurrent` incrémente le compteur.

## Dernier appel gagne

Appeler `rateLimit()` ou `maxConcurrent()` plusieurs fois sur le même builder écrase la politique précédente. Le builder ne garde qu'une `RateLimitPolicy` et une `ConcurrencyPolicy`.

## Validation au build

Les deux méthodes valident les arguments au build et lèvent `InvalidPipelineDefinition` sur une entrée invalide.

- Clé littérale vide.
- `max < 1` (rate limit).
- `perSeconds < 1` (rate limit).
- `limit < 1` (max concurrent).

Les closures sont validées au runtime (admission), pas au build, parce que la clé résolue est une donnée runtime.

## Aucun surcoût quand inutilisé

Quand aucune des deux méthodes n'est appelée, l'executor ne résout jamais les facades `RateLimiter` ou `Cache`. Pas de probe statique, pas de registre global, pas de guard par appel. Les gates sont strictement opt in.

## Interaction avec événements et hooks

Une admission throttled ou rejetée s'exécute **avant** les hooks et événements. Donc :

- Les hooks `beforeEach`, `afterEach`, `onStepFailed` ne se déclenchent pas.
- Les callbacks `onSuccess`, `onFailure`, `onComplete` ne se déclenchent pas.
- Les événements `PipelineStepCompleted`, `PipelineStepFailed`, `PipelineCompleted` ne sont pas dispatchés.

Le caller observe l'échec exclusivement via le `PipelineThrottled` ou `PipelineConcurrencyLimitExceeded` levé. Instrumentez le rejet d'admission au site d'appel si vous voulez le tracer.

## Tests

`Pipeline::fake()` en mode par défaut traite les deux gates comme **inertes**. Le pipeline admet inconditionnellement, aucun appel au Cache ou au RateLimiter n'est fait. Utile pour écrire des assertions sur la définition enregistrée sans nécessiter un Redis réel dans le runtime de test.

`Pipeline::fake()->recording()` honore les deux gates exactement comme en production. Utile pour exercer l'épuisement du quota ou la limite de concurrence dans des tests d'intégration.

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Vherbaut\LaravelPipelineJobs\Exceptions\PipelineThrottled;
use Vherbaut\LaravelPipelineJobs\Facades\Pipeline;

Cache::flush();
RateLimiter::clear('k');
Pipeline::fake()->recording();

Pipeline::make([StepA::class])->rateLimit('k', max: 1, perSeconds: 60)->run();

expect(static fn () => Pipeline::make([StepA::class])->rateLimit('k', max: 1, perSeconds: 60)->run())
    ->toThrow(PipelineThrottled::class);
```

## Exceptions en un coup d'œil

| Exception | Levée quand |
|-----------|-------------|
| `PipelineThrottled` | Quota `rateLimit()` épuisé à l'admission. Porte `retryAfterSeconds`. |
| `PipelineConcurrencyLimitExceeded` | Limite `maxConcurrent()` atteinte à l'admission. |
| `InvalidPipelineDefinition` | Au build : arguments invalides. À l'admission : closure retourne autre chose qu'une string. |
