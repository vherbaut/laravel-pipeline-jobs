# Jobs compatibles Pipeline

Chaque étape d'un pipeline doit lire ou écrire dans le contexte partagé. Le package propose deux manières équivalentes de le faire, et vous pouvez les mélanger librement dans votre codebase.

## Table des matières

- [Le trait InteractsWithPipeline](#le-trait-interactswithpipeline)
- [Propriété explicite](#propriété-explicite)
- [Jobs en double mode](#jobs-en-double-mode)
- [Fonctionnement interne](#fonctionnement-interne)

## Le trait InteractsWithPipeline

Recommandé dans la plupart des cas. Ajoutez le trait à n'importe quel job et vous obtenez deux accesseurs gratuitement.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SendWelcomeEmail
{
    use InteractsWithPipeline;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineContext()->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

| Accesseur | Retour | Description |
|-----------|--------|-------------|
| `pipelineContext()` | `?PipelineContext` | Le contexte en direct quand le job s'exécute dans un pipeline, `null` sinon. |
| `hasPipelineContext()` | `bool` | Indique si un contexte non null est actuellement disponible. |
| `failureContext()` | `?FailureContext` | Snapshot du dernier échec enregistré sur le manifest, ou `null` si aucun échec n'a été enregistré ou si le job s'exécute hors d'un pipeline. |

## Propriété explicite

Si vous préférez une dépendance complètement visible (par exemple pour annoter un type contexte personnalisé), déclarez le manifest comme propriété publique.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineManifest;

class SendWelcomeEmail
{
    public PipelineManifest $pipelineManifest;

    public function handle(Mailer $mailer): void
    {
        $user = $this->pipelineManifest->context->user;

        $mailer->send(new WelcomeMail($user));
    }
}
```

Les deux patterns produisent un comportement identique à l'exécution. Le trait réduit le boilerplate, la propriété explicite est plus visible. Choisissez ce que votre équipe préfère.

## Jobs en double mode

Le trait brille quand vous voulez qu'un même job s'exécute à la fois en standalone (via `Bus::dispatch`) et dans un pipeline. Utilisez `hasPipelineContext()` pour brancher.

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class SyncProduct
{
    use InteractsWithPipeline;

    public function __construct(
        public readonly int $productId,
    ) {}

    public function handle(ProductSyncService $sync): void
    {
        if ($this->hasPipelineContext()) {
            // Mode pipeline : récupérer le produit depuis le contexte partagé.
            $sync->push($this->pipelineContext()->product);

            return;
        }

        // Mode standalone : charger le produit depuis le stockage.
        $sync->push(Product::findOrFail($this->productId));
    }
}
```

## Fonctionnement interne

Les exécuteurs du pipeline (`SyncExecutor`, `PipelineStepJob`, `RecordingExecutor`) recherchent une propriété `pipelineManifest` sur chaque étape qu'ils exécutent, via `property_exists()` et `ReflectionProperty::setValue()`. Le trait déclare cette propriété pour vous.

Quand un job s'exécute hors d'un pipeline, aucun exécuteur ne touche la propriété, elle reste donc à sa valeur `null` par défaut et les deux accesseurs retournent leurs valeurs "hors pipeline". L'accesseur `failureContext()` du trait se comporte de la même manière : il lit les champs d'échec du manifest s'ils sont renseignés, sinon il retourne `null`.
