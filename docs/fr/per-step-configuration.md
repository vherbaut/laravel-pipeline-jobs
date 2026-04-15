# Configuration par étape

L'épic 7 introduit la configuration par étape pour le ciblage de queue, l'exécution synchrone, les retries, le backoff et le timeout, ainsi que des valeurs par défaut au niveau pipeline qui s'appliquent à chaque étape sauf surcharge explicite.

Toutes les méthodes de configuration par étape se chaînent directement après `step()` (ou `addStep()`) et s'appliquent à la dernière étape ajoutée. Les valeurs par défaut au niveau pipeline peuvent être déclarées n'importe où sur le builder (avant ou après les étapes). Les valeurs par étape priment toujours sur les valeurs par défaut.

## Table des matières

- [Queue et connexion par étape](#queue-et-connexion-par-étape)
- [Forçage synchrone par étape](#forçage-synchrone-par-étape)
- [Retry, backoff, timeout par étape](#retry-backoff-timeout-par-étape)
- [Valeurs par défaut au niveau pipeline](#valeurs-par-défaut-au-niveau-pipeline)
- [Règles de priorité](#règles-de-priorité)
- [Règles de validation](#règles-de-validation)
- [Exemple complet](#exemple-complet)

## Queue et connexion par étape

Router chaque étape sur sa propre queue ou connexion avec `onQueue()` et `onConnection()`. Les deux méthodes se chaînent après un appel à `step()` et s'appliquent uniquement à la dernière étape ajoutée.

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make()
    ->step(ValidateOrder::class)->onQueue('fast')
    ->step(ChargeCustomer::class)->onQueue('payments')->onConnection('redis-payments')
    ->step(SendConfirmation::class)->onQueue('notifications')
    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->run();
```

Le wrapper `PipelineStepJob` de chaque étape est dispatché sur la queue et la connexion configurées. Les étapes sans queue ou connexion explicite retombent sur les valeurs par défaut au niveau pipeline (voir plus bas), et à défaut de valeurs par défaut, sur les queue et connexion par défaut de Laravel.

## Forçage synchrone par étape

Force une étape spécifique à s'exécuter synchrone même quand le pipeline est en queue. Utile pour des étapes qui doivent bloquer l'appelant (authentification, validation) pendant que le reste du pipeline tourne en async.

```php
JobPipeline::make()
    ->step(AuthenticateUser::class)->sync()
    ->step(LoadProfile::class)
    ->step(NotifyFriends::class)
    ->send(new UserContext(userId: $userId))
    ->shouldBeQueued()
    ->run();
```

L'étape `AuthenticateUser` s'exécute inline via `SyncExecutor::execute()`, puis les étapes restantes entrent dans la queue à partir de `LoadProfile`. Le contexte du pipeline traverse la transition de manière transparente.

Quand le pipeline n'est pas lui-même en queue, `sync()` est un no-op (toutes les étapes s'exécutent déjà synchrones).

## Retry, backoff, timeout par étape

Attacher des politiques de retry par étape pour les dépendances fragiles (APIs distantes, services avec rate-limit).

```php
JobPipeline::make()
    ->step(CallExternalApi::class)
        ->retry(3)       // jusqu'à 3 tentatives de retry après la tentative initiale
        ->backoff(10)    // attendre 10s entre les tentatives
        ->timeout(60)    // tuer la tentative après 60s
    ->step(StoreResult::class)
    ->send(new ApiContext(endpoint: $endpoint))
    ->shouldBeQueued()
    ->run();
```

- `retry(int)` : nombre de tentatives de retry après la tentative initiale. `0` signifie aucun retry. Doit être non négatif.
- `backoff(int)` : secondes d'attente entre les tentatives. `0` signifie aucune attente. Doit être non négatif.
- `timeout(int)` : temps d'exécution maximal en secondes pour le wrapper en queue. Doit être supérieur ou égal à 1.

Ces valeurs sont propagées sur les propriétés `$tries`, `$backoff` et `$timeout` du wrapper `PipelineStepJob` de l'étape, et le worker de queue Laravel les applique. En mode synchrone, `retry` et `backoff` n'ont aucun effet (l'étape s'exécute inline une seule fois) et `timeout` est ignoré car la requête PHP a sa propre limite d'exécution.

## Valeurs par défaut au niveau pipeline

Déclarer des valeurs par défaut qui s'appliquent à chaque étape sans surcharge explicite.

```php
JobPipeline::make([
    SendEmail::class,
    SendSms::class,
    LogNotification::class,
])
    ->defaultQueue('notifications')
    ->defaultConnection('redis')
    ->defaultRetry(2)
    ->defaultBackoff(5)
    ->defaultTimeout(30)
    ->send(new NotificationContext(userId: $userId))
    ->shouldBeQueued()
    ->run();
```

Les trois étapes de notification héritent du nom de queue, de la connexion, de la politique de retry et du timeout. Toute étape qui déclare ses propres `onQueue()` ou `retry()` prend la priorité.

Les valeurs par défaut peuvent être déclarées dans n'importe quel ordre par rapport aux étapes (avant, entre, après), car elles s'appliquent au moment du build à chaque étape qui n'a pas de valeur explicite. Les contraintes de validation sont identiques aux méthodes par étape (chaînes non vides, entiers non négatifs, timeout positif).

## Règles de priorité

Les valeurs par étape priment toujours sur les valeurs par défaut au niveau pipeline. L'ordre de résolution pour une étape donnée est :

1. Valeur explicite par étape (`->step(X)->retry(3)`).
2. Valeur par défaut au niveau pipeline (`->defaultRetry(1)`).
3. Valeur par défaut du package (pour retry et backoff, `null` signifiant que les valeurs par défaut de Laravel s'appliquent ; pour queue et connexion, les valeurs par défaut configurées dans Laravel).

Exemple :

```php
JobPipeline::make()
    ->defaultQueue('default-q')
    ->defaultRetry(1)
    ->step(StepA::class)                       // queue=default-q, retry=1
    ->step(StepB::class)->onQueue('priority')  // queue=priority,  retry=1
    ->step(StepC::class)->retry(5)             // queue=default-q, retry=5
    ->send($ctx)
    ->shouldBeQueued()
    ->run();
```

## Règles de validation

Toutes les méthodes de configuration par étape lèvent `InvalidPipelineDefinition` en cas d'entrée invalide :

| Méthode | Contrainte | Règle additionnelle |
|---------|-----------|---------------------|
| `onQueue(string)` | Chaîne non vide | Doit être appelée après au moins une étape |
| `onConnection(string)` | Chaîne non vide | Doit être appelée après au moins une étape |
| `sync()` | Pas d'argument | Doit être appelée après au moins une étape |
| `retry(int)` | `>= 0` | Doit être appelée après au moins une étape |
| `backoff(int)` | `>= 0` | Doit être appelée après au moins une étape |
| `timeout(int)` | `>= 1` | Doit être appelée après au moins une étape |
| `defaultQueue(string)` | Chaîne non vide | Peut être appelée avant toute étape |
| `defaultConnection(string)` | Chaîne non vide | Peut être appelée avant toute étape |
| `defaultRetry(int)` | `>= 0` | Peut être appelée avant toute étape |
| `defaultBackoff(int)` | `>= 0` | Peut être appelée avant toute étape |
| `defaultTimeout(int)` | `>= 1` | Peut être appelée avant toute étape |

La contrainte "doit être appelée après au moins une étape" existe parce que les méthodes par étape modifient la dernière étape ajoutée. Les appeler sur un builder vide lève une erreur explicite pointant vers la méthode `default*()` correspondante.

## Exemple complet

Un pipeline réaliste de traitement de commande mélangeant toutes les fonctionnalités de l'épic 7 :

```php
use Vherbaut\LaravelPipelineJobs\JobPipeline;

JobPipeline::make()
    // Valeurs par défaut au niveau pipeline : toutes les étapes vont sur la queue orders avec 2 retries.
    ->defaultQueue('orders')
    ->defaultConnection('redis')
    ->defaultRetry(2)
    ->defaultBackoff(5)
    ->defaultTimeout(30)

    // Étape 1 : validation synchrone (bloque l'appelant).
    ->step(ValidateOrder::class)->sync()

    // Étape 2 : le paiement a besoin d'une queue dédiée et d'un timeout plus serré.
    ->step(ChargeCustomer::class)
        ->onQueue('payments')
        ->onConnection('redis-payments')
        ->retry(3)
        ->backoff(10)
        ->timeout(60)

    // Étape 3 : l'inventaire utilise les valeurs par défaut du pipeline.
    ->step(ReserveInventory::class)

    // Étape 4 : l'API externe a besoin de plus de retries et d'un timeout plus long.
    ->step(CallShippingApi::class)
        ->retry(5)
        ->backoff(15)
        ->timeout(120)

    // Étape 5 : la notification utilise les valeurs par défaut du pipeline mais une queue dédiée.
    ->step(SendConfirmation::class)->onQueue('notifications')

    ->send(new OrderContext(order: $order))
    ->shouldBeQueued()
    ->run();
```

Pour le verbe d'exécution alternatif `Pipeline::dispatch([...])`, voir [Verbe Dispatch](dispatch-verb.md). Pour la sémantique de queue et les contraintes de sérialisation, voir [Pipelines en file d'attente](queued-pipelines.md).
