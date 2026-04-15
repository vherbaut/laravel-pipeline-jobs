# Valeurs de retour

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

## Notes comportementales

- **Mode synchrone uniquement.** La closure s'exécute exclusivement en mode synchrone. Les exécutions en queue retournent toujours `null` car l'exécution est reportée aux workers et la closure n'est jamais invoquée.
- **Argument null.** Quand aucun contexte n'a été envoyé via `->send()`, la closure est quand même appelée avec `null` en argument. Votre closure est responsable de la gestion du cas null.
- **Dernière écriture prioritaire.** Appeler `->return()` plusieurs fois écrase silencieusement la closure précédente. Seul le dernier enregistrement est appliqué.
- **Les exceptions se propagent telles quelles.** Si votre closure lève une exception, l'exception remonte de `->run()` inchangée. Elle n'est PAS encapsulée dans `StepExecutionFailed` car la closure s'exécute après l'exécuteur, pas en tant qu'étape.

## Sans et avec `->return()`

Sans :

```php
$context = JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->run();

return $context->total; // Déréférencement manuel, le type de retour s'élargit à ?PipelineContext
```

Avec :

```php
return JobPipeline::make([CreateOrder::class, CalculateTotal::class])
    ->send(new OrderContext(items: $items))
    ->return(fn (OrderContext $ctx) => $ctx->total)
    ->run();
```
