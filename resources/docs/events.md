# Events

Events let one part of your app react to something that happened elsewhere, without the two knowing about each other.

## Listening

Register listeners in `app/Providers/AppServiceProvider.php`:

```php
use Core\Event;

public function boot(): void
{
    Event::listen('payment.succeeded', function ($payment) {
        Sms::send($payment->user->phone, 'We received your payment of ' . $payment->amount());
    });

    Event::listen(OrderShipped::class, SendShippingEmail::class);   // class with handle(OrderShipped $event)
}
```

## Dispatching

```php
event('order.placed', [$order]);          // named event with arguments
event(new OrderShipped($order));          // event object
```

A listener that returns `false` stops the remaining listeners.

## Events fired by the framework

| Event | Arguments |
|---|---|
| `auth.login` | `$user` |
| `auth.logout` | `$user` |
| `auth.password_reset` | `$user` |
| `user.registered` | `$user` |
| `payment.succeeded` | the `App\Models\Payment` record |
| `model.created: App\Models\Post` (and `creating`, `updated`, `deleted`, ...) | the model |

For per-model hooks, [model events](models#model-events) (`static::creating(...)`) are usually simpler.
