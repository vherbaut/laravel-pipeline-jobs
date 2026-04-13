# Laravel Pipeline Jobs

Un package Laravel pour construire des **pipelines de jobs avec un contexte typé** et le **support du pattern saga**.

Le `Bus::chain()` de Laravel est pratique pour exécuter des jobs en séquence, mais il traite chaque job comme une boîte noire. Il n'existe aucun mécanisme natif pour transmettre des données entre les étapes, aucune compensation quand quelque chose échoue, et le câblage d'event listeners vers des chaînes de jobs nécessite des classes listener intermédiaires.

Ce package résout ces trois problèmes avec une API fluide et expressive qui s'intègre naturellement dans une application Laravel.

## Table des matières

- [Pourquoi ce package ?](#pourquoi-ce-package-)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Démarrage rapide](#démarrage-rapide)
- [Concepts clés](#concepts-clés)
  - [Pipeline Context](#pipeline-context)
  - [Pipeline Builder](#pipeline-builder)
  - [Modes d'exécution](#modes-dexécution)
- [Utilisation](#utilisation)
  - [Construire un pipeline simple](#construire-un-pipeline-simple)
  - [Transmettre des données entre les étapes](#transmettre-des-données-entre-les-étapes)
  - [Jobs compatibles Pipeline](#jobs-compatibles-pipeline)
  - [Extraire des valeurs de retour](#extraire-des-valeurs-de-retour)
  - [Étapes conditionnelles](#étapes-conditionnelles)
  - [Pipelines en file d'attente](#pipelines-en-file-dattente)
  - [Pont vers les Event Listeners](#pont-vers-les-event-listeners)
  - [Pattern Saga (Compensation)](#pattern-saga-compensation)
- [Tests](#tests)
  - [Mode Fake](#mode-fake)
  - [Mode Recording](#mode-recording)
  - [Assertions disponibles](#assertions-disponibles)
  - [Snapshots de contexte](#snapshots-de-contexte)
  - [Assertions de compensation](#assertions-de-compensation)
- [Référence API](#référence-api)
- [Feuille de route](#feuille-de-route)
- [Contribuer](#contribuer)
- [Licence](#licence)

## Pourquoi ce package ?

Prenons un flux classique de traitement de commande. Vous devez valider la commande, débiter le client, réserver le stock et envoyer un email de confirmation. Chaque étape dépend des données produites par la précédente.

Avec `Bus::chain()`, il faudrait persister les résultats intermédiaires en base de données ou en cache, puis les récupérer dans chaque job suivant. C'est beaucoup de plomberie pour ce qui devrait être un simple flux de données.

Avec Laravel Pipeline Jobs, chaque étape reçoit et enrichit un objet contexte partagé et typé :

```php
$context = new OrderContext(order: $order);

JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
])
    ->send($context)
    ->run();

// Après exécution, $context->invoice, $context->shipment, etc. sont tous remplis.
```

Si `ChargeCustomer` échoue, vous pouvez automatiquement compenser en exécutant `RefundCustomer` ainsi que les autres étapes de rollback, dans le bon ordre inverse. C'est le pattern saga, intégré directement dans le pipeline.

## Prérequis

| Dépendance | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, 13.x |

## Installation

```bash
composer require vherbaut/laravel-pipeline-jobs
```

Le package auto découvre son service provider et sa facade. Aucune configuration manuelle n'est nécessaire.

## Démarrage rapide

**1. Créer une classe de contexte** qui transporte les données à travers le pipeline :

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

**2. Créer les étapes (jobs).** Ajoutez le trait `InteractsWithPipeline` et lisez le contexte via `pipelineContext()` :

```php
use Vherbaut\LaravelPipelineJobs\Concerns\InteractsWithPipeline;

class ChargeCustomer
{
    use InteractsWithPipeline;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineContext();

        $context->invoice = $payments->charge($context->order);
        $context->status = 'charged';
    }
}
```

Remarquez que `PaymentService` est injecté via le conteneur Laravel, comme pour n'importe quel job classique. L'exécuteur du pipeline injecte le contexte en direct sur le job automatiquement, via le trait. Si vous préférez une dépendance explicite, déclarez plutôt une propriété `public PipelineManifest $pipelineManifest;` à la place du trait. Les deux patterns sont pleinement supportés (voir la section [Jobs compatibles Pipeline](#jobs-compatibles-pipeline)).

**3. Exécuter le pipeline :**

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$context = new OrderContext(order: $order);

$result = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    ReserveInventory::class,
    SendConfirmation::class,
])
    ->send($context)
    ->run();

// $result est le OrderContext final avec toutes les étapes appliquées.
```

C'est tout. Quatre lignes de code remplacent des dizaines de lignes de boilerplate, de jonglage avec le cache et de coordination manuelle.

## Concepts clés

### Pipeline Context

La classe `PipelineContext` est le fondement du flux de données dans vos pipelines. C'est un simple DTO (Data Transfer Object) qui voyage à travers chaque étape en accumulant l'état au fur et à mesure.

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class MyContext extends PipelineContext
{
    public ?Model $result = null;
    public array $metadata = [];
    public string $status = 'pending';
}
```

**Caractéristiques principales :**

- **Propriétés typées.** Utilisez le système de types de PHP pour définir précisément quelles données circulent dans votre pipeline. Chaque étape sait ce qu'elle peut lire et écrire.
- **Support des modèles Eloquent.** La classe de base utilise le trait `SerializesModels` de Laravel, ce qui garantit la bonne sérialisation des modèles Eloquent lorsque les pipelines sont mis en file d'attente.
- **Validation de la sérialisation.** Avant de dispatcher un pipeline en file d'attente, le contexte est validé pour s'assurer que toutes les propriétés sont sérialisables. Les closures, ressources et classes anonymes sont rejetées immédiatement avec un message d'erreur clair, plutôt que d'échouer silencieusement dans le queue worker.

### Pipeline Builder

Le builder fournit une API fluide pour construire des pipelines. Deux syntaxes équivalentes sont disponibles :

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

### Modes d'exécution

Les pipelines supportent deux modes d'exécution :

**Synchrone** (par défaut). Les étapes s'exécutent l'une après l'autre dans le processus courant. Le contexte final est retourné directement :

```php
$result = JobPipeline::make([...])
    ->send($context)
    ->run(); // Retourne PipelineContext
```

**En file d'attente (queued)**. Les étapes sont dispatchées vers le système de queue de Laravel. Chaque étape est encapsulée dans un job interne qui, une fois terminé, dispatche l'étape suivante. Les étapes s'exécutent potentiellement sur des workers différents, l'état complet du pipeline étant sérialisé dans le payload de chaque job :

```php
JobPipeline::make([...])
    ->send($context)
    ->shouldBeQueued()
    ->run(); // Retourne null (exécution asynchrone)
```

L'exécuteur queued valide la sérialisation du contexte **avant** le dispatch. Si votre contexte contient une closure ou une ressource, vous obtiendrez immédiatement une exception `ContextSerializationFailed` plutôt qu'une erreur mystérieuse dans la queue quelques minutes plus tard.

## Utilisation

### Construire un pipeline simple

Le pipeline le plus simple est une liste de jobs qui s'exécutent dans l'ordre :

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make([
    GenerateReport::class,
    SendReportEmail::class,
    ArchiveReport::class,
])->run();
```

Sans contexte (`send()` non appelé), les jobs s'exécutent simplement en séquence. C'est utile quand vos jobs communiquent via la base de données ou n'ont pas besoin d'état partagé.

### Transmettre des données entre les étapes

La vraie puissance de ce package réside dans le contexte typé qui circule entre les étapes. Voici un exemple complet :

```php
// 1. Définir le contexte
class ImportContext extends PipelineContext
{
    public array $rows = [];
    public int $imported = 0;
    public array $errors = [];

    public function __construct(
        public string $filePath,
    ) {}
}

// 2. Définir les étapes
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
        // ... persister les lignes valides
    }
}

// 3. Exécuter le pipeline
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

### Jobs compatibles Pipeline

Chaque étape d'un pipeline doit lire ou écrire dans le contexte partagé. Le package propose deux manières équivalentes de le faire, et vous pouvez les mélanger librement dans votre codebase.

**1. Le trait `InteractsWithPipeline` (recommandé).** Ajoutez le trait à n'importe quel job et vous obtenez deux accesseurs gratuitement :

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

**2. Propriété explicite.** Si vous préférez une dépendance complètement visible (par exemple pour annoter un type contexte personnalisé), déclarez le manifest comme propriété publique :

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

**Jobs en double mode.** Le trait brille quand vous voulez qu'un même job s'exécute à la fois en standalone (via `Bus::dispatch`) et dans un pipeline. Utilisez `hasPipelineContext()` pour brancher :

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

**Comment ça fonctionne en interne.** Les exécuteurs du pipeline (`SyncExecutor`, `PipelineStepJob`, `RecordingExecutor`) recherchent une propriété `pipelineManifest` sur chaque étape qu'ils exécutent, via `property_exists()` et `ReflectionProperty::setValue()`. Le trait déclare cette propriété pour vous. Quand un job s'exécute hors d'un pipeline, aucun exécuteur ne touche la propriété, elle reste donc à sa valeur `null` par défaut et les deux accesseurs retournent leurs valeurs "hors pipeline".

### Extraire des valeurs de retour

Par défaut, `->run()` retourne le `PipelineContext` complet après exécution synchrone. Quand vous ne vous intéressez qu'à un seul champ (un total, un identifiant de facture, un résultat calculé), la méthode `->return()` vous permet de déclarer une closure qui transforme le contexte final en la valeur que vous voulez réellement.

```php
$total = JobPipeline::make([
    CreateOrder::class,
    ApplyDiscount::class,
    CalculateTotal::class,
])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();

// $total est le scalaire calculé par CalculateTotal. Plus besoin de déréférencer manuellement.
```

**Notes comportementales :**

- **Mode synchrone uniquement.** La closure s'exécute exclusivement en mode synchrone. Les exécutions en queue retournent toujours `null` car l'exécution est reportée aux workers et la closure n'est jamais invoquée.
- **Argument null.** Quand aucun contexte n'a été envoyé via `->send()`, la closure est quand même appelée avec `null` en argument. Votre closure est responsable de la gestion du cas null.
- **Dernière écriture prioritaire.** Appeler `->return()` plusieurs fois écrase silencieusement la closure précédente. Seul le dernier enregistrement est appliqué.
- **Les exceptions se propagent telles quelles.** Si votre closure lève une exception, l'exception remonte de `->run()` inchangée. Elle n'est PAS encapsulée dans `StepExecutionFailed` car la closure s'exécute après l'exécuteur, pas en tant qu'étape.

**Sans `->return()` :**

```php
$context = JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->run();

return $context->total; // Déréférencement manuel, le type de retour s'élargit à ?PipelineContext
```

**Avec `->return()` :**

```php
return JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();
```

### Étapes conditionnelles

Certaines étapes ne doivent s'exécuter que dans des conditions spécifiques à l'exécution (un feature flag, une valeur de contexte définie par une étape antérieure, une préférence utilisateur). Le builder expose `when()` et `unless()` pour cela :

```php
JobPipeline::make()
    ->step(ValidateOrder::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->requiresApproval,
        NotifyManager::class,
    )
    ->step(ChargeCustomer::class)
    ->unless(
        fn (OrderContext $ctx) => $ctx->order->isDigital,
        ShipPackage::class,
    )
    ->send(new OrderContext(order: $order))
    ->run();
```

- `when(Closure $condition, string $jobClass)` ajoute une étape qui ne s'exécute que si la closure retourne une valeur vraie.
- `unless(Closure $condition, string $jobClass)` est l'inverse. L'étape ne s'exécute que si la closure retourne une valeur fausse.

**Évaluation à l'exécution.** Les conditions sont évaluées contre le `PipelineContext` en direct, juste avant que l'étape ne s'exécute, en mode synchrone comme en mode queue. Les étapes précédentes peuvent muter le contexte et les conditions ultérieures voient l'état à jour :

```php
JobPipeline::make()
    ->step(LoadOrder::class) // remplit $ctx->order
    ->when(
        fn (OrderContext $ctx) => $ctx->order->status === 'pending',
        SendReminderEmail::class, // ne s'exécute que si la commande chargée est en attente
    )
    ->send(new OrderContext(orderId: $id))
    ->run();
```

**Pipelines en queue.** Les closures de condition doivent être sérialisables car elles voyagent avec le manifest. Le builder les encapsule automatiquement dans `SerializableClosure`, donc toutes les variables capturées via `use` doivent aussi être sérialisables. Évitez de capturer des ressources, des classes anonymes ou des connexions DB actives dans une condition. Quand le prédicat dépend d'un état externe, chargez cet état dans une étape antérieure et lisez le depuis le contexte.

**Composer avec la compensation.** Une étape conditionnelle peut également enregistrer un job de compensation :

```php
JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->when(
        fn (OrderContext $ctx) => $ctx->order->total > 100,
        ChargeCustomer::class,
    )->compensateWith(RefundCustomer::class)
    ->send(new OrderContext(order: $order))
    ->run();
```

Si l'étape conditionnelle est sautée (la closure a retourné faux), sa compensation ne s'exécute jamais, même si une étape ultérieure échoue. La compensation ne s'applique qu'aux étapes qui se sont réellement exécutées.

### Pipelines en file d'attente

Pour dispatcher un pipeline vers la queue, ajoutez `shouldBeQueued()` :

```php
JobPipeline::make([
    ProcessVideo::class,
    GenerateThumbnails::class,
    NotifyUser::class,
])
    ->send(new VideoContext(video: $video))
    ->shouldBeQueued()
    ->run();
```

**Comment ça fonctionne en interne :**

1. Le contexte est validé pour la sérialisabilité (échec rapide).
2. La première étape est encapsulée dans un `PipelineStepJob` et dispatchée vers la queue.
3. Quand la première étape se termine, le `PipelineStepJob` suivant est dispatché automatiquement.
4. Cela continue jusqu'à ce que toutes les étapes soient exécutées.

Chaque job en queue transporte le manifest complet du pipeline (contexte, liste des étapes, progression). N'importe quel worker peut donc prendre en charge n'importe quelle étape, sans état externe à gérer.

**Note importante :** les étapes des pipelines en queue utilisent `tries = 1` par défaut. Cela empêche la ré exécution d'étapes déjà complétées après un crash de worker. Si vous avez besoin de retries, implémentez la logique de retry dans chaque job individuel.

### Pont vers les Event Listeners

L'un des patterns les plus courants en Laravel est de dispatcher des jobs en réponse à des événements. Normalement, cela nécessite de créer une classe listener dédiée pour chaque combinaison événement/job. Ce package élimine ce boilerplate.

**Enregistrement en une ligne** dans votre service provider :

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        JobPipeline::listen(
            OrderPlaced::class,
            [ProcessOrder::class, SendReceipt::class, UpdateAnalytics::class],
            fn (OrderPlaced $event) => new OrderContext(order: $event->order),
        );
    }
}
```

Le troisième argument est une closure qui reçoit l'événement et retourne un `PipelineContext`. C'est ainsi que vous faites le pont entre les données de l'événement et le pipeline.

**Syntaxe alternative** avec `toListener()` quand vous avez besoin de plus de contrôle :

```php
$listener = JobPipeline::make([
    ProcessOrder::class,
    SendReceipt::class,
])
    ->send(fn (OrderPlaced $event) => new OrderContext(order: $event->order))
    ->toListener();

Event::listen(OrderPlaced::class, $listener);
```

Les deux approches sont équivalentes. La forme closure (`send(fn ($event) => ...)`) est préférée car elle diffère la création du contexte au moment où l'événement est réellement émis, plutôt que de le créer prématurément.

### Pattern Saga (Compensation)

Dans les systèmes distribués, quand un processus multi étapes échoue en cours de route, il est souvent nécessaire d'annuler les étapes déjà complétées. C'est le **pattern saga**, et il est intégré directement dans le pipeline builder.

```php
JobPipeline::make()
    ->step(ReserveInventory::class)->compensateWith(ReleaseInventory::class)
    ->step(ChargeCustomer::class)->compensateWith(RefundCustomer::class)
    ->step(CreateShipment::class)->compensateWith(CancelShipment::class)
    ->send(new OrderContext(order: $order))
    ->run();
```

**Comment fonctionne la compensation :**

1. Les étapes s'exécutent dans l'ordre : `ReserveInventory`, puis `ChargeCustomer`, puis `CreateShipment`.
2. Si `CreateShipment` lève une exception, la compensation se déclenche.
3. Seules les étapes **complétées** sont compensées, dans l'**ordre inverse** : `RefundCustomer` d'abord, puis `ReleaseInventory`.
4. `CancelShipment` n'est **pas** appelé car `CreateShipment` n'a jamais été complété.

Les jobs de compensation reçoivent le même manifest de pipeline (avec le contexte) que les étapes normales. Ils ont donc accès à toutes les données accumulées par les étapes qui ont réussi, ce dont ils ont besoin pour effectuer le rollback.

**Un job de compensation ressemble exactement à une étape normale :**

```php
class RefundCustomer
{
    public PipelineManifest $pipelineManifest;

    public function handle(PaymentService $payments): void
    {
        $context = $this->pipelineManifest->context;
        $payments->refund($context->invoice);
    }
}
```

Si un job de compensation lève lui même une exception, elle est absorbée (loguée mais pas relancée). Les jobs de compensation restants continuent de s'exécuter. Cela garantit qu'un échec dans une étape de rollback n'empêche pas les autres rollbacks de s'exécuter.

## Tests

Ce package fournit une boîte à outils de test complète qui suit les mêmes patterns que `Bus::fake()` et `Queue::fake()` de Laravel. Vous disposez d'un test double, de méthodes d'assertion et d'un enregistrement de l'exécution, le tout accessible via la facade `Pipeline`.

### Mode Fake

Le mode fake intercepte toutes les exécutions de pipeline sans réellement exécuter les étapes. C'est utile quand vous voulez vérifier qu'un pipeline a été dispatché avec la bonne configuration, sans vous soucier de l'exécution des étapes.

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

### Mode Recording

Le mode recording va plus loin : il exécute réellement les étapes du pipeline (de manière synchrone) tout en capturant une trace d'exécution complète. Cela vous permet de vérifier non seulement qu'un pipeline a été dispatché, mais que chaque étape s'est exécutée correctement et a modifié le contexte comme prévu.

Activez le mode recording en appelant `recording()` sur le fake :

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

    // Vérifier l'exécution des étapes
    $fake->assertStepExecuted(ValidateOrder::class);
    $fake->assertStepExecuted(ChargeCustomer::class);
    $fake->assertStepExecuted(CreateShipment::class);

    // Vérifier l'ordre d'exécution
    $fake->assertStepsExecutedInOrder([
        ValidateOrder::class,
        ChargeCustomer::class,
        CreateShipment::class,
    ]);

    // Vérifier l'état final du contexte
    $fake->assertContextHas('status', 'shipped');
});
```

### Assertions disponibles

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

### Snapshots de contexte

L'une des fonctionnalités de test les plus puissantes est la possibilité d'inspecter le contexte à n'importe quel moment de l'exécution. Après chaque étape, l'exécuteur recording effectue un clone profond du contexte. Vous pouvez récupérer ces snapshots pour vérifier l'état intermédiaire :

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

C'est inestimable pour vérifier que chaque étape fait sa part correctement, indépendamment des autres étapes.

### Assertions de compensation

Pour tester les patterns saga, vous devez vérifier que les bons jobs de compensation s'exécutent (ou ne s'exécutent pas) quand des échecs surviennent :

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

    // La compensation a été déclenchée
    $fake->assertCompensationWasTriggered();

    // Les étapes complétées ont été compensées dans l'ordre inverse
    $fake->assertCompensationRan(RefundCustomer::class);
    $fake->assertCompensationRan(ReleaseInventory::class);

    // La compensation de l'étape échouée n'a PAS été exécutée (elle n'a jamais été complétée)
    $fake->assertCompensationNotRan(UndoFailingStep::class);

    // Vérifier l'ordre de compensation
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

## Référence API

### `JobPipeline`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `make(array $jobs = [])` | `PipelineBuilder` | Créer un nouveau builder de pipeline, optionnellement avec un tableau de classes de job. |
| `listen(string $event, array $jobs, ?Closure $send)` | `void` | Enregistrer un pipeline comme event listener en un seul appel. |

### `PipelineBuilder`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `step(string $jobClass)` | `static` | Ajouter une étape au pipeline. |
| `when(Closure $condition, string $jobClass)` | `static` | Ajouter une étape qui ne s'exécute que si la condition (évaluée contre le contexte en direct) retourne une valeur vraie. La condition doit être sérialisable en mode queue. |
| `unless(Closure $condition, string $jobClass)` | `static` | Ajouter une étape qui ne s'exécute que si la condition (évaluée contre le contexte en direct) retourne une valeur fausse. La condition doit être sérialisable en mode queue. |
| `compensateWith(string $jobClass)` | `static` | Assigner un job de compensation à la dernière étape ajoutée. |
| `send(PipelineContext\|Closure $context)` | `static` | Définir le contexte (instance ou closure pour résolution différée). |
| `shouldBeQueued()` | `static` | Marquer le pipeline pour une exécution asynchrone en file d'attente. |
| `return(Closure $callback)` | `static` | Enregistrer une closure qui transforme le contexte final en la valeur retournée par `run()`. Synchrone uniquement. Ignorée en mode queue. |
| `build()` | `PipelineDefinition` | Construire une définition de pipeline immuable à partir de l'état actuel du builder. |
| `run()` | `mixed` | Construire et exécuter le pipeline. Retourne le résultat de la closure `->return()` quand enregistrée, sinon le `PipelineContext` final (ou `null`). Toujours `null` en mode queue. |
| `toListener()` | `Closure` | Convertir le pipeline en closure d'event listener. |
| `getContext()` | `PipelineContext\|Closure\|null` | Récupérer le contexte actuellement configuré. |

### Trait `InteractsWithPipeline`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `pipelineContext()` | `?PipelineContext` | Le `PipelineContext` en direct quand le job s'exécute dans un pipeline avec un contexte, `null` sinon. |
| `hasPipelineContext()` | `bool` | `true` quand un contexte non null est disponible, `false` pour un dispatch standalone ou un pipeline sans `->send(...)`. |

### `PipelineContext`

| Méthode | Retour | Description |
|---------|--------|-------------|
| `validateSerializable()` | `void` | Valider que toutes les propriétés peuvent être sérialisées pour le dispatch en queue. |

### `PipelineManifest`

| Propriété | Type | Description |
|-----------|------|-------------|
| `pipelineId` | `string` | Identifiant unique (UUID) pour cette exécution de pipeline. |
| `stepClasses` | `array<int, string>` | Liste ordonnée des noms de classes d'étapes. |
| `compensationMapping` | `array<string, string>` | Correspondance entre classe d'étape et classe de compensation. |
| `currentStepIndex` | `int` | Index de l'étape en cours d'exécution. |
| `completedSteps` | `array<int, string>` | Étapes qui ont été complétées avec succès. |
| `context` | `?PipelineContext` | L'objet contexte partagé. |

### Facade `Pipeline`

La facade `Pipeline` sert de proxy vers `JobPipeline` et ajoute la méthode `fake()` pour les tests :

| Méthode | Retour | Description |
|---------|--------|-------------|
| `fake()` | `PipelineFake` | Remplacer le système de pipeline par un test double. |

### Exceptions

| Exception | Quand |
|-----------|-------|
| `InvalidPipelineDefinition` | Le pipeline n'a aucune étape, ou `compensateWith()` est appelé avant toute étape. |
| `StepExecutionFailed` | Une étape a levé une exception durant l'exécution synchrone. Encapsule l'exception originale. |
| `ContextSerializationFailed` | Le contexte contient des propriétés non sérialisables (closures, ressources, classes anonymes). |

## Feuille de route

Les fonctionnalités suivantes sont prévues pour les prochaines versions. Les propriétés correspondantes sont déjà réservées dans le code :

- **Configuration de queue par étape.** Définir le nom de la queue, la connexion, le nombre de retries, le backoff et le timeout par étape.
- **Hooks de cycle de vie du pipeline.** `beforeEach()`, `afterEach()`, `onStepFailed()`, `onSuccess()`, `onFailure()`, `onComplete()`.
- **Pipelines nommés.** `name('order-fulfillment')` pour une meilleure observabilité et traçabilité.
- **Étapes parallèles.** Pattern fan out pour les étapes qui peuvent s'exécuter simultanément.
- **Événements de pipeline.** Émettre des événements Laravel aux points clés du cycle de vie.

## Contribuer

Les contributions sont les bienvenues ! Voici les commandes pour démarrer :

```bash
# Lancer la suite de tests
composer test

# Lancer l'analyse statique
composer analyse

# Formater le code
composer format
```

## Licence

Licence MIT. Consultez le fichier [LICENSE](LICENSE) pour plus d'informations.
