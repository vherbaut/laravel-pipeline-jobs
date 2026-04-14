# Concepts clés

Trois abstractions forment le cœur du package : le `PipelineContext` typé, le `PipelineBuilder` fluide et les deux modes d'exécution.

## Table des matières

- [Pipeline Context](#pipeline-context)
- [Pipeline Builder](#pipeline-builder)
- [Modes d'exécution](#modes-dexécution)

## Pipeline Context

La classe `PipelineContext` est le fondement du flux de données. C'est un simple DTO (Data Transfer Object) qui voyage à travers chaque étape, accumulant l'état au passage.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class MyContext extends PipelineContext
{
    public ?Model $result = null;
    public array $metadata = [];
    public string $status = 'pending';
}
```

Caractéristiques principales :

- **Propriétés typées.** Utilisez le système de types de PHP pour définir précisément quelles données circulent dans votre pipeline. Chaque étape sait ce qu'elle peut lire et écrire.
- **Support des modèles Eloquent.** La classe de base utilise le trait `SerializesModels` de Laravel, ce qui garantit la bonne sérialisation des modèles Eloquent lorsque les pipelines sont mis en file d'attente.
- **Validation de la sérialisation.** Avant de dispatcher un pipeline en file d'attente, le contexte est validé pour s'assurer que toutes les propriétés sont sérialisables. Les closures, ressources et classes anonymes sont rejetées immédiatement avec un message d'erreur clair, plutôt que d'échouer silencieusement dans le queue worker.

## Pipeline Builder

Le builder fournit une API fluide pour construire des pipelines. Deux syntaxes équivalentes sont disponibles.

**API par tableau** (concise, idéale pour les pipelines simples) :

```php
JobPipeline::make([
    StepA::class,
    StepB::class,
    StepC::class,
]);
```

**API fluide** (permet la configuration par étape, comme la compensation) :

```php
JobPipeline::make()
    ->step(StepA::class)
    ->step(StepB::class)->compensateWith(UndoStepB::class)
    ->step(StepC::class)->compensateWith(UndoStepC::class);
```

Les deux produisent la même `PipelineDefinition` immuable. Choisissez celle qui se lit le mieux pour votre cas d'usage.

L'API fluide est requise quand vous devez attacher des métadonnées à une étape individuelle, par exemple `compensateWith()` pour la [compensation saga](saga-compensation-fr.md) ou les branches conditionnelles via [`when()` / `unless()`](conditional-steps-fr.md).

## Modes d'exécution

Les pipelines supportent deux modes d'exécution.

### Synchrone (par défaut)

Les étapes s'exécutent l'une après l'autre dans le processus courant. Le contexte final est retourné directement.

```php
$result = JobPipeline::make([...])
    ->send($context)
    ->run(); // Retourne PipelineContext
```

### En file d'attente (queued)

Les étapes sont dispatchées vers le système de queue de Laravel. Chaque étape est encapsulée dans un job interne qui, une fois terminé, dispatche l'étape suivante. Les étapes s'exécutent potentiellement sur des workers différents, l'état complet du pipeline étant sérialisé dans le payload de chaque job.

```php
JobPipeline::make([...])
    ->send($context)
    ->shouldBeQueued()
    ->run(); // Retourne null (exécution asynchrone)
```

L'exécuteur queued valide la sérialisation du contexte **avant** le dispatch. Si votre contexte contient une closure ou une ressource, vous obtiendrez immédiatement une exception `ContextSerializationFailed` plutôt qu'une erreur mystérieuse dans la queue quelques minutes plus tard.

Voir [Pipelines en file d'attente](queued-pipelines-fr.md) pour un approfondissement sur la sémantique queue, les retries et l'affinité worker.
