# Interfaces alternatives de step

Les steps de pipeline peuvent prendre trois formes. Le pipeline détecte la forme au runtime par réflexion et dispatche l'appel en conséquence. Ça permet de réutiliser des jobs middleware style (convention Laravel Pipeline, `lorisleiva/laravel-actions`, `Spatie\QueueableAction`) dans un pipeline sans les réécrire.

## Les trois formes supportées

### 1. `handle()` classique

La forme historique des jobs queued Laravel. C'est le contrat legacy, et le comportement par défaut quand `handle()` prend zéro ou un paramètre résolvable par le container.

```php
class ValidateOrder
{
    public function handle(OrderValidator $validator): void
    {
        // forme job classique
    }
}
```

### 2. Middleware `handle($passable, Closure $next)`

La forme middleware de `Illuminate\Pipeline`. Détectée quand `handle()` a **deux paramètres ou plus** et que le **second paramètre est typé exactement `Closure`**.

```php
use Closure;
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class AuditEveryStep
{
    public function handle(?PipelineContext $passable, Closure $next): mixed
    {
        logger()->info('before step', ['context' => $passable]);
        $result = $next($passable);
        logger()->info('after step', ['context' => $passable]);

        return $result;
    }
}
```

Le pipeline lie `$passable` au **contexte vivant** et passe une **closure identité** comme `$next`. Appeler `$next($passable)` retourne `$passable` inchangé. Que vous appeliez `$next()` ou non, le pipeline avance toujours au step suivant au retour (l'ordre du pipeline est piloté par le manifest, pas par un chaînage middleware).

### 3. Action `__invoke()`

La forme invokable Action. Détectée quand la classe **n'a pas de méthode `handle()`** et définit `__invoke()`.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class SendWelcomeEmail
{
    public function __invoke(?PipelineContext $context): void
    {
        // forme action invokable
    }
}
```

Le pipeline lie le contexte au paramètre nommé `$context`. Vous pouvez aussi mixer des dépendances résolues par le container avec le binding du contexte.

```php
class SendWelcomeEmail
{
    public function __invoke(MailService $mail, ?PipelineContext $context): void
    {
        $mail->send($context->user);
    }
}
```

## Précédence de détection

Quand une classe définit **à la fois** `handle()` et `__invoke()`, `handle()` gagne. Ça garde `__invoke()` inoffensif sur les classes en forme par défaut, et protège les utilisateurs d'`InteractsWithPipeline` qui ajouteraient `__invoke()` plus tard.

La détection ne regarde que le second paramètre de `handle()`. Les union types (`Closure|string`) et les intersection types retombent sur la forme par défaut.

## Contrat de nommage des paramètres

Le pipeline lie le contexte résolu par **nom de paramètre** via le container Laravel.

| Forme | Nom de paramètre attendu |
|-------|--------------------------|
| Middleware | `$passable` (et `$next` pour la closure) |
| Action | `$context` |

Un utilisateur qui nomme le premier paramètre middleware autrement (par exemple `$request`, `$ctx`, `$input`) **ne recevra pas** le contexte vivant du pipeline, sauf si le paramètre est typé `?PipelineContext`. Dans ce cas le container Laravel résout par type, ce qui peut retourner une instance différente de celle portée par le pipeline. Le chemin sûr est de s'en tenir aux noms documentés **ou** d'utiliser le trait `InteractsWithPipeline` pour un accès indépendant du nommage.

## Compatibilité avec `InteractsWithPipeline`

Le trait fonctionne sur les trois formes. L'injection du manifest tourne avant le dispatch, donc `$this->pipelineContext()` retourne la même instance que le step soit `handle()`, middleware, ou Action.

```php
use Closure;
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class MiddlewareWithTrait
{
    use InteractsWithPipeline;

    public function handle(mixed $passable, Closure $next): mixed
    {
        // $this->pipelineContext() === $passable quand le contexte est non null
        return $next($passable);
    }
}
```

Pour les formes Action, le trait est le chemin le plus propre pour accéder au contexte sans déclarer un paramètre.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class ActionWithTrait
{
    use InteractsWithPipeline;

    public function __invoke(): void
    {
        $this->pipelineContext()->someProperty = 'value';
    }
}
```

## Note sur la compensation

Les chemins de compensation (`CompensableJob::compensate()` et le fallback `handle()`) suivent toujours le contrat de compensation classique. Les **classes de compensation** en forme middleware ou Action sont hors scope, et peuvent surfacer en `BindingResolutionException` quand le container ne peut pas résoudre la signature middleware. Quand votre logique de compensation vit dans une classe middleware ou Action, enveloppez la dans une méthode `handle(): void` classique, ou implémentez l'interface `CompensableJob`.

## Classes de step invalides

Une classe ne définissant **ni** `handle()` **ni** `__invoke()` lève `InvalidPipelineDefinition::stepClassMissingInvocationMethod()` la première fois que le pipeline tente de l'invoquer. La validation est paresseuse (au runtime) plutôt qu'eager (au build) parce que `StepDefinition::fromJobClass()` accepte n'importe quelle string aujourd'hui.

```php
use Vherbaut\LaravelPipelineJobs\Exceptions\InvalidPipelineDefinition;
use Vherbaut\LaravelPipelineJobs\Exceptions\StepExecutionFailed;

try {
    JobPipeline::make([MissingInvocationClass::class])->run();
} catch (StepExecutionFailed $wrapper) {
    // L'InvalidPipelineDefinition original est le $wrapper->getPrevious().
    $original = $wrapper->getPrevious();
    assert($original instanceof InvalidPipelineDefinition);
}
```

## Pipelines mixtes

Vous pouvez mixer les trois formes librement dans le même pipeline.

```php
JobPipeline::make([
    ValidateOrder::class,        // handle() classique
    AuditEveryStep::class,       // Middleware handle($passable, Closure $next)
    SendWelcomeEmail::class,     // Action __invoke(?PipelineContext $context)
])
    ->send(new OrderContext(order: $order))
    ->run();
```

L'ordre est préservé (ordre de déclaration du tableau de steps ou du chaînage `->step()`). Chaque step déclenche les hooks, callbacks de pipeline et événements habituels de façon identique, indépendamment de sa forme.

## Configuration par step

La queue, connexion, retry, backoff, timeout et `when()` / `unless()` par step s'appliquent au **wrapper** (`PipelineStepJob`), pas à la classe de step elle même. Les classes middleware et Action héritent donc de la configuration par step sans changement.

```php
JobPipeline::make()
    ->step(MiddlewareStep::class)
    ->onQueue('high')
    ->retry(3)
    ->backoff(5)
    ->step(ActionStep::class)
    ->when(fn (?OrderContext $ctx) => $ctx->status === 'pending')
    ->run();
```

## Mise en cache de la détection

Le pipeline mémoïse la forme détectée par classe dans un cache scopé au processus. Les workers queue traitant de nombreuses instances de la même classe ne paient le coût de la réflexion qu'une fois par processus. Les tests qui créent des classes fixtures anonymes doivent appeler `StepInvocationDispatcher::clearCache()` en `beforeEach` pour éviter la contamination entre tests.

```php
use Vherbaut\LaravelPipelineJobs\Execution\Shared\StepInvocationDispatcher;

beforeEach(function (): void {
    StepInvocationDispatcher::clearCache();
});
```
