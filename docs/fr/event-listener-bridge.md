# Pont vers les Event Listeners

L'un des patterns les plus courants en Laravel est de dispatcher des jobs en réponse à des événements. Normalement, cela nécessite de créer une classe listener dédiée pour chaque combinaison événement et job. Ce package élimine ce boilerplate.

## Enregistrement en une ligne

Enregistrer un pipeline comme event listener dans votre service provider.

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

Le troisième argument est une closure qui reçoit l'événement et retourne un `PipelineContext`. Cette closure fait le pont entre les données de l'événement et le pipeline.

## Syntaxe alternative avec `toListener()`

Quand vous avez besoin de plus de contrôle (configuration custom du builder, étapes conditionnelles, compensation, hooks de cycle de vie), utilisez `toListener()`.

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

## Capture de l'état du builder

`toListener()` capture l'état du builder de manière eager au moment où la méthode est appelée. Les mutations ultérieures sur le builder n'affectent pas la closure listener déjà retournée. Si vous devez enregistrer plusieurs variantes de listener, appelez `toListener()` une fois par variante sur un builder fraîchement configuré.
