# Étapes conditionnelles

Certaines étapes ne doivent s'exécuter que dans des conditions spécifiques à l'exécution (un feature flag, une valeur de contexte définie par une étape antérieure, une préférence utilisateur). Le builder expose `when()` et `unless()` pour cela.

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

## Évaluation à l'exécution

Les conditions sont évaluées contre le `PipelineContext` en direct, juste avant que l'étape ne s'exécute, en mode synchrone comme en mode queue. Les étapes précédentes peuvent muter le contexte et les conditions ultérieures voient l'état à jour.

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

## Pipelines en queue

Les closures de condition doivent être sérialisables car elles voyagent avec le manifest. Le builder les encapsule automatiquement dans `SerializableClosure`, donc toutes les variables capturées via `use` doivent aussi être sérialisables. Évitez de capturer des ressources, des classes anonymes ou des connexions DB actives dans une condition. Quand le prédicat dépend d'un état externe, chargez cet état dans une étape antérieure et lisez le depuis le contexte.

## Composer avec la compensation

Une étape conditionnelle peut également enregistrer un job de compensation.

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

## Interaction avec les hooks de cycle de vie

Les [hooks de cycle de vie par étape](lifecycle-hooks-fr.md) ne se déclenchent pas pour les étapes sautées via `when()` / `unless()`. La vérification d'exclusion précède tout déclenchement de hook.
