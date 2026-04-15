# Getting Started

This guide walks through installing the package, defining your first typed context, and running a simple pipeline.

## Table of Contents

- [Installation](#installation)
- [Building a Simple Pipeline](#building-a-simple-pipeline)
- [Defining a Typed Context](#defining-a-typed-context)
- [Passing Data Between Steps](#passing-data-between-steps)

## Installation

```bash
composer require vherbaut/laravel-pipeline-jobs
```

The package auto discovers its service provider and facade. No manual registration is needed.

Requirements:

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, 13.x |

## Building a Simple Pipeline

The simplest pipeline is a list of jobs that execute in order:

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    GenerateReport::class,
    SendReportEmail::class,
    ArchiveReport::class,
])->run();
```

Without a context (`send()` not called), jobs simply execute in sequence. This is useful when your jobs communicate through the database or when shared state is unnecessary.

## Defining a Typed Context

A context is a simple DTO that travels through each step, accumulating state. Extend `PipelineContext` and declare typed properties.

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

Typed properties give every step compile time visibility into what can be read and written. Your IDE provides autocompletion, and static analyzers can enforce correct usage.

Context classes inherit Laravel's `SerializesModels` trait. Eloquent models are properly serialized when pipelines are queued, and the context is validated for serializability before dispatch.

## Passing Data Between Steps

The real power of the package is the typed context that flows between steps. Here is a complete example.

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
        // persist valid rows...
    }
}

$result = JobPipeline::make([
    ParseCsvFile::class,
    ValidateRows::class,
    ImportValidRows::class,
])
    ->send(new ImportContext(filePath: '/tmp/data.csv'))
    ->run();

echo "Imported {$result->imported} rows with " . count($result->errors) . " errors.";
```

Each step reads from and writes to the same context object. The context is a plain PHP object, so your IDE provides full autocompletion and type checking.

## Next Steps

- [Core Concepts](core-concepts.md) for the details of `PipelineContext`, `PipelineBuilder`, and execution modes.
- [Pipeline Aware Jobs](pipeline-aware-jobs.md) for the two patterns (trait vs explicit property) to wire a step to the context.
- [API Reference](api-reference.md) for the complete method catalog.
