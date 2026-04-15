# Démarrage

Ce guide couvre l'installation du package, la définition d'un premier contexte typé et l'exécution d'un pipeline simple.

## Table des matières

- [Installation](#installation)
- [Construire un pipeline simple](#construire-un-pipeline-simple)
- [Définir un contexte typé](#définir-un-contexte-typé)
- [Transmettre des données entre les étapes](#transmettre-des-données-entre-les-étapes)

## Installation

```bash
composer require vherbaut/laravel-pipeline-jobs
```

Le package auto découvre son service provider et sa facade. Aucun enregistrement manuel n'est nécessaire.

Prérequis :

| Dépendance | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, 13.x |

## Construire un pipeline simple

Le pipeline le plus simple est une liste de jobs qui s'exécutent dans l'ordre :

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    GenerateReport::class,
    SendReportEmail::class,
    ArchiveReport::class,
])->run();
```

Sans contexte (`send()` non appelé), les jobs s'exécutent simplement en séquence. C'est utile quand vos jobs communiquent via la base de données ou quand un état partagé n'est pas nécessaire.

## Définir un contexte typé

Un contexte est un simple DTO qui voyage à travers chaque étape, accumulant l'état au passage. Étendez `PipelineContext` et déclarez des propriétés typées.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class OrderContext extends PipelineContext
{
    public ?Invoice $invoice = null;
    public ?Shipment $shipment = null;
    public string $status = 'pending';

    public function __construct(
        public Order $order,
    ) {}
}
```

Les propriétés typées donnent à chaque étape une visibilité à la compilation sur ce qu'elle peut lire et écrire. Votre IDE fournit l'autocomplétion et les analyseurs statiques peuvent vérifier l'usage correct.

Les classes de contexte héritent du trait `SerializesModels` de Laravel. Les modèles Eloquent sont correctement sérialisés quand les pipelines sont mis en file d'attente, et le contexte est validé pour la sérialisabilité avant dispatch.

## Transmettre des données entre les étapes

La vraie puissance du package réside dans le contexte typé qui circule entre les étapes. Voici un exemple complet.

```php
class ImportContext extends PipelineContext
{
    public array $rows = [];
    public int $imported = 0;
    public array $errors = [];

    public function __construct(
        public string $filePath,
    ) {}
}

class ParseCsvFile
{
    public PipelineManifest $pipelineManifest;

    public function handle(): void
    {
        $context = $this->pipelineManifest->context;
        $context->rows = CsvParser::parse($context->filePath);
    }
}

class ValidateRows
{
    public PipelineManifest $pipelineManifest;

    public function handle(RowValidator $validator): void
    {
        $context = $this->pipelineManifest->context;

        foreach ($context->rows as $index => $row) {
            if (! $validator->isValid($row)) {
                $context->errors[] = "Row {$index} is invalid";
            }
        }
    }
}

class ImportValidRows
{
    public PipelineManifest $pipelineManifest;

    public function handle(): void
    {
        $context = $this->pipelineManifest->context;

        $validRows = array_filter($context->rows, fn ($row, $i) =>
            ! in_array("Row {$i} is invalid", $context->errors),
            ARRAY_FILTER_USE_BOTH
        );

        $context->imported = count($validRows);
        // persister les lignes valides...
    }
}

$result = JobPipeline::make([
    ParseCsvFile::class,
    ValidateRows::class,
    ImportValidRows::class,
])
    ->send(new ImportContext(filePath: '/tmp/data.csv'))
    ->run();

echo "Importé {$result->imported} lignes avec " . count($result->errors) . " erreurs.";
```

Chaque étape lit et écrit dans le même objet contexte. Le contexte est un objet PHP classique, votre IDE fournit donc l'autocomplétion et la vérification de types.

## Pour aller plus loin

- [Concepts clés](core-concepts.md) pour les détails de `PipelineContext`, `PipelineBuilder` et des modes d'exécution.
- [Jobs compatibles Pipeline](pipeline-aware-jobs.md) pour les deux patterns (trait vs propriété explicite) qui relient une étape au contexte.
- [Référence API](api-reference.md) pour le catalogue complet des méthodes.
