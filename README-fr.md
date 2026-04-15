# Laravel Pipeline Jobs

> English documentation is available in [README.md](README.md).

Un package Laravel pour construire des **pipelines de jobs avec un contexte typé** et le **support du pattern saga**.

Le `Bus::chain()` de Laravel est pratique pour exécuter des jobs en séquence, mais il traite chaque job comme une boîte noire. Il n'existe aucun mécanisme natif pour transmettre des données entre les étapes, aucune compensation quand quelque chose échoue, et le câblage d'event listeners vers des chaînes de jobs nécessite des classes listener intermédiaires.

Ce package résout ces trois problèmes avec une API fluide et expressive qui s'intègre naturellement dans une application Laravel.

## Table des matières

- [Pourquoi ce package ?](#pourquoi-ce-package-)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Démarrage rapide](#démarrage-rapide)
- [Documentation](#documentation)
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

// Après exécution, $context->invoice, $context->shipment, etc. sont tous renseignés.
```

Si `ChargeCustomer` échoue, vous pouvez automatiquement compenser en exécutant `RefundCustomer` et les autres étapes de rollback, dans le bon ordre inverse. C'est le pattern saga, intégré directement dans le pipeline.

Fonctionnalités clés en un coup d'œil :

- **Contexte typé.** Un DTO partagé circule à travers chaque étape, avec autocomplétion IDE et support de l'analyse statique.
- **Exécution synchrone et en queue.** Un seul appel (`shouldBeQueued()`) pour basculer un pipeline entre les modes sans changer le code.
- **Compensation saga.** Rollback déclaratif avec `compensateWith()` et trois politiques `FailStrategy`.
- **Étapes conditionnelles.** Prédicats `when()` / `unless()` évalués contre le contexte en direct.
- **Hooks de cycle de vie et observabilité.** Six hooks (par étape et au niveau pipeline) pour logs, métriques et alerting.
- **Pont event listener.** Une ligne pour enregistrer un pipeline comme listener.
- **Boîte à outils de test complète.** `Pipeline::fake()`, mode recording, snapshots de contexte, assertions de compensation.

## Prérequis

| Dépendance | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, 13.x |

## Installation

```bash
composer require vherbaut/laravel-pipeline-jobs
```

Le package auto découvre son service provider et sa facade. Aucun enregistrement manuel n'est nécessaire.

## Démarrage rapide

**1. Définir un contexte typé** qui transporte les données à travers votre pipeline :

```php
use Vherbaut\LaravelPipelineJobs\Context\PipelineContext;

class OrderContext extends PipelineContext
{
    public ?Invoice $invoice = null;
    public string $status = 'pending';

    public function __construct(
        public Order $order,
    ) {}
}
```

**2. Écrire les étapes (jobs).** Ajoutez le trait `InteractsWithPipeline` pour lire le contexte :

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

**3. Exécuter le pipeline :**

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

$result = JobPipeline::make([
    ValidateOrder::class,
    ChargeCustomer::class,
    SendConfirmation::class,
])
    ->send(new OrderContext(order: $order))
    ->run();

// $result est le OrderContext final avec toutes les étapes appliquées.
```

Pour le tour complet (conception du contexte, mode queue, compensation, hooks, tests), voir [docs/fr/getting-started.md](docs/fr/getting-started.md).

## Documentation

La documentation anglaise se trouve sous [`docs/en/`](docs/en/). La documentation française se trouve sous [`docs/fr/`](docs/fr/).

| Sujet | Description | Lien |
|-------|-------------|------|
| Démarrage | Installer le package, écrire un premier contexte typé, exécuter un pipeline, transmettre des données. | [docs/fr/getting-started.md](docs/fr/getting-started.md) |
| Concepts clés | `PipelineContext`, `PipelineBuilder` (tableau vs fluide), modes d'exécution synchrone et queued. | [docs/fr/core-concepts.md](docs/fr/core-concepts.md) |
| Jobs compatibles Pipeline | Relier un job au contexte partagé via le trait `InteractsWithPipeline` ou une propriété explicite. Jobs double mode. | [docs/fr/pipeline-aware-jobs.md](docs/fr/pipeline-aware-jobs.md) |
| Valeurs de retour | Transformer le contexte final en une valeur scalaire avec `->return(Closure)`. | [docs/fr/return-values.md](docs/fr/return-values.md) |
| Étapes conditionnelles | Brancher l'exécution avec les prédicats `when()` / `unless()` évalués contre le contexte en direct. | [docs/fr/conditional-steps.md](docs/fr/conditional-steps.md) |
| Pipelines en file d'attente | Exécuter les pipelines via le système de queue de Laravel. Sérialisation, retries, affinité worker. | [docs/fr/queued-pipelines.md](docs/fr/queued-pipelines.md) |
| Pont Event Listener | Enregistrer un pipeline comme event listener avec `JobPipeline::listen()` ou `toListener()`. | [docs/fr/event-listener-bridge.md](docs/fr/event-listener-bridge.md) |
| Compensation Saga | Rollback avec `compensateWith()`, politiques `FailStrategy`, contrat `CompensableJob`, observabilité des échecs. | [docs/fr/saga-compensation.md](docs/fr/saga-compensation.md) |
| Hooks de cycle de vie | Hooks par étape (`beforeEach`, `afterEach`, `onStepFailed`) et callbacks au niveau pipeline (`onSuccess`, `onFailure(Closure)`, `onComplete`). | [docs/fr/lifecycle-hooks.md](docs/fr/lifecycle-hooks.md) |
| Configuration par étape | Router chaque étape sur sa propre queue ou connexion, forcer l'exécution synchrone, définir retry, backoff et timeout par étape, avec des valeurs par défaut au niveau pipeline. | [docs/fr/per-step-configuration.md](docs/fr/per-step-configuration.md) |
| Verbe Dispatch | Exécuter un pipeline avec `Pipeline::dispatch([...])` comme alternative à `->make()->run()`, dans le style `Bus::dispatch()`. Auto-exécution au destruct. | [docs/fr/dispatch-verb.md](docs/fr/dispatch-verb.md) |
| Tests | `Pipeline::fake()`, mode recording, assertions d'étapes et de contexte, assertions de compensation. | [docs/fr/testing.md](docs/fr/testing.md) |
| Référence API | Catalogue complet des symboles publics, méthodes, propriétés, exceptions et events. | [docs/fr/api-reference.md](docs/fr/api-reference.md) |

## Feuille de route

Les fonctionnalités suivantes sont prévues pour les prochaines versions. Les propriétés correspondantes sont déjà réservées dans le code :

- **Pipelines nommés.** `name('order-fulfillment')` pour une meilleure observabilité et traçabilité.
- **Étapes parallèles.** Pattern fan out pour les étapes qui peuvent s'exécuter simultanément.
- **Événements de pipeline.** Émettre des événements Laravel aux points clés du cycle de vie.

## Contribuer

Les contributions sont les bienvenues. Voici les commandes pour démarrer :

```bash
# Lancer la suite de tests
composer test

# Lancer l'analyse statique
composer analyse

# Formater le code
composer format
```

## Licence

Licence MIT. Voir le fichier [LICENSE](LICENSE) pour plus d'informations.
