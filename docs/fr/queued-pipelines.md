# Pipelines en file d'attente

Pour dispatcher un pipeline vers la queue, ajoutez `shouldBeQueued()`.

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

## Fonctionnement interne

1. Le contexte est validé pour la sérialisabilité (échec rapide).
2. La première étape est encapsulée dans un `PipelineStepJob` et dispatchée vers la queue.
3. Quand la première étape se termine, le `PipelineStepJob` suivant est dispatché automatiquement.
4. Cela continue jusqu'à ce que toutes les étapes soient exécutées.

Chaque job en queue transporte le manifest complet du pipeline (contexte, liste des étapes, progression). N'importe quel worker peut donc prendre en charge n'importe quelle étape, sans état externe à gérer.

## Retries

Les étapes des pipelines en queue utilisent `tries = 1` par défaut. Cela empêche la ré exécution d'étapes déjà complétées après un crash de worker. Si vous avez besoin de retries, implémentez la logique de retry dans chaque job individuel.

## Sérialisation

Tout ce qui voyage avec le pipeline doit être sérialisable.

- **Propriétés du contexte.** Validées avant dispatch. Closures, ressources et classes anonymes déclenchent une exception `ContextSerializationFailed` immédiate.
- **Closures de condition** (via `when()` / `unless()`). Encapsulées dans `SerializableClosure`. Les variables capturées via `use` doivent aussi être sérialisables.
- **Closures de hooks et callbacks de cycle de vie.** Même contrainte. Voir [Hooks de cycle de vie](lifecycle-hooks.md#notes-sur-le-mode-en-file-dattente).

Quand le prédicat ou le callback a besoin d'un état externe, chargez cet état dans une étape antérieure et lisez le depuis le contexte plutôt que de le capturer via `use`.

## Valeur de retour en mode queue

Les exécutions en queue retournent toujours `null` car l'exécution est reportée aux workers. Toute closure `->return()` est ignorée en mode queue. Voir [Valeurs de retour](return-values.md).

## Compensation en mode queue

Sous `FailStrategy::StopAndCompensate`, les jobs de compensation sont dispatchés en `Bus::chain` lors d'un échec. La chaîne s'arrête à la première compensation qui lève (divergence documentée avec le best effort synchrone). Voir [Pattern Saga](saga-compensation.md).
