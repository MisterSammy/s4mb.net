---
title: "Attribute-Level Events in Laravel: Package vs. Native Approaches"
date: 2025-12-08
excerpt: "Model events are great, but what if you need to fire events only when specific attributes change? Here's two approaches - one with a package, one native."
tags: [laravel, events, eloquent, php, architecture]
slug: attribute-level-events-laravel
---

Laravel's Eloquent models fire events when things happen - `created`, `updated`, `deleted`, and so on. These events are useful for triggering side effects like sending notifications, updating caches, or logging changes.

But there's a limitation. The `updated` event fires whenever *any* attribute changes. If you only care about status changes, you end up checking inside your listener:

```php
public function handle(OrderUpdated $event): void
{
    // Did the status actually change?
    if (!$event->order->wasChanged('status')) {
        return;
    }
    
    // Now do the real work
    $this->notifyWarehouseTeam($event->order);
}
```

This works, but it's messy. Your listener fires for every update, wastes cycles checking conditions, and the intent isn't clear from the event name alone.

What if you could fire different events for different attribute changes? Status changes trigger `OrderStatusChanged`. Packing notes change triggers `OrderNotesUpdated`. Each listener handles exactly what it cares about.

This post shows two approaches: using a package for declarative syntax, and using native Laravel observers for zero dependencies.

## The Scenario: E-Commerce Order Fulfillment

Consider an e-commerce warehouse where orders move through various fulfillment statuses:
- **Pending**  -  Order placed, awaiting processing
- **Processing**  -  Warehouse picking items
- **Packed**  -  Items packed, ready for shipment
- **Shipped**  -  Handed to carrier
- **Delivered**  -  Customer received order
- **Cancelled**  -  Order cancelled

Different status changes trigger different actions:
- Moving to "Processing" notifies the warehouse team
- Moving to "Shipped" sends tracking information to the customer
- Moving to "Delivered" requests a review from the customer
- Moving to "Cancelled" processes a refund

## Approach 1: Native Laravel with Observers (Recommended)

For most applications, Laravel's built-in observers with `wasChanged()` provide everything you need without adding a dependency:

```php
<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Events\Order\OrderStatusChanged;
use App\Models\Order;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Only fire status event if status actually changed
        if ($order->wasChanged('status')) {
            event(new OrderStatusChanged(
                order: $order,
                oldStatus: $order->getOriginal('status'),
                newStatus: $order->status,
            ));
        }

        // Fire notes event if notes changed
        if ($order->wasChanged('packing_notes')) {
            event(new OrderNotesUpdated($order));
        }
    }
}
```

Register the observer in your `AppServiceProvider`:

```php
use App\Models\Order;
use App\Observers\OrderObserver;

public function boot(): void
{
    Order::observe(OrderObserver::class);
}
```

Create the event class:

```php
<?php

namespace App\Events\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?OrderStatus $oldStatus,
        public OrderStatus $newStatus,
    ) {}
}
```

### Using Event Discovery for Listeners

Laravel 12 supports automatic event discovery. Create a listener and Laravel will find it automatically:

```php
<?php

namespace App\Listeners\Order;

use App\Enums\OrderStatus;
use App\Events\Order\OrderStatusChanged;
use App\Notifications\Order\OrderProcessingNotification;
use App\Notifications\Order\OrderShippedNotification;
use App\Notifications\Order\OrderDeliveredNotification;
use App\Notifications\Order\OrderCancelledNotification;

class HandleOrderStatusChange
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        match($event->newStatus) {
            OrderStatus::PROCESSING => $this->notifyWarehouseTeam($order),
            OrderStatus::SHIPPED => $this->notifyCustomer($order),
            OrderStatus::DELIVERED => $this->requestReview($order),
            OrderStatus::CANCELLED => $this->processRefund($order),
            default => null,
        };
    }

    private function notifyWarehouseTeam(Order $order): void
    {
        $order->warehouse->notify(
            new OrderProcessingNotification($order)
        );
    }

    private function notifyCustomer(Order $order): void
    {
        $order->customer->notify(
            new OrderShippedNotification($order)
        );
    }

    private function requestReview(Order $order): void
    {
        $order->customer->notify(
            new OrderDeliveredNotification($order)
        );
    }

    private function processRefund(Order $order): void
    {
        if ($order->order_total > 0) {
            $order->customer->notify(
                new OrderCancelledNotification($order)
            );
            
            // Trigger refund process
            ProcessRefund::dispatch($order);
        }
    }
}
```

Event discovery works automatically when your listener's `handle` method type-hints the event class.

### Checking Multiple Attributes

The observer pattern makes it easy to check multiple attributes:

```php
public function updated(Order $order): void
{
    if ($order->wasChanged('status')) {
        event(new OrderStatusChanged(
            $order,
            $order->getOriginal('status'),
            $order->status,
        ));
    }

    if ($order->wasChanged('warehouse_id')) {
        event(new OrderWarehouseChanged(
            $order,
            $order->getOriginal('warehouse_id'),
            $order->warehouse_id,
        ));
    }

    if ($order->wasChanged(['estimated_delivery', 'shipping_method'])) {
        event(new OrderShippingUpdated($order));
    }
}
```

## Approach 2: Using kleemans/attribute-events Package

If you prefer a more declarative syntax and want events defined directly on the model, the `kleemans/attribute-events` package provides this:

```bash
composer require kleemans/attribute-events
```

Add the trait to your model:

```php
<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Events\Order\OrderCreated;
use App\Events\Order\OrderStatusChanged;
use App\Events\Order\OrderNotesUpdated;
use App\Events\Order\OrderWarehouseChanged;
use Illuminate\Database\Eloquent\Model;
use Kleemans\AttributeEvents;

class Order extends Model
{
    use AttributeEvents;

    protected $dispatchesEvents = [
        // Standard model events
        'created' => OrderCreated::class,
        
        // Attribute-specific events (the :* means "any change")
        'status:*' => OrderStatusChanged::class,
        'packing_notes:*' => OrderNotesUpdated::class,
        'warehouse_id:*' => OrderWarehouseChanged::class,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'estimated_delivery' => 'datetime',
        ];
    }
}
```

The `:*` wildcard means "fire this event when this attribute changes to any value." The package automatically passes old and new values to your event constructor.

### When to Use the Package

The package approach is useful when:
- You want events declared directly on the model (co-located with the attribute definitions)
- You have many attributes to track across many models
- You prefer the declarative `'attribute:*'` syntax

The native observer approach is better when:
- You want zero external dependencies
- You need complex logic (like checking multiple attributes together)
- You want all event dispatching logic in one place (the observer)

## Practical Example: Multi-Model Events

This pattern shines when you have multiple models with similar attribute tracking needs:

```php
// Using observers (native approach)
class OrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            event(new OrderStatusChanged($order, ...));
        }
    }
}

class ShipmentObserver
{
    public function updated(Shipment $shipment): void
    {
        if ($shipment->wasChanged('tracking_number')) {
            event(new ShipmentTrackingUpdated($shipment));
        }
        
        if ($shipment->wasChanged('carrier')) {
            event(new ShipmentCarrierChanged($shipment));
        }
    }
}

class CustomerObserver
{
    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged('email')) {
            event(new CustomerEmailChanged($customer, ...));
        }
        
        if ($customer->wasChanged('shipping_address')) {
            event(new CustomerShippingAddressChanged($customer, ...));
        }
    }
}
```

Register all observers in your service provider:

```php
public function boot(): void
{
    Order::observe(OrderObserver::class);
    Shipment::observe(ShipmentObserver::class);
    Customer::observe(CustomerObserver::class);
}
```

## Combining with Activity Logging

A common pattern is logging all attribute changes for audit purposes. Use the generic `updated` method for comprehensive logging, and specific events for business logic:

```php
class OrderObserver
{
    public function updated(Order $order): void
    {
        // Log all changes for audit trail
        $this->logChanges($order);
        
        // Fire specific events for business logic
        if ($order->wasChanged('status')) {
            event(new OrderStatusChanged($order, ...));
        }
    }

    private function logChanges(Order $order): void
    {
        $changes = $order->getChanges();
        
        foreach ($changes as $attribute => $newValue) {
            if (in_array($attribute, ['updated_at', 'created_at'])) {
                continue;
            }
            
            $oldValue = $order->getOriginal($attribute);
            
            activity()
                ->performedOn($order)
                ->withProperties([
                    'attribute' => $attribute,
                    'old' => $oldValue,
                    'new' => $newValue,
                ])
                ->log("Order {$attribute} changed");
        }
    }
}
```

## Benefits of This Pattern

**Clear intent.** When you see `OrderStatusChanged`, you know exactly what triggered it. No need to dig through a generic `OrderUpdated` listener.

**Focused listeners.** Each listener does one thing. Testing is simpler. Debugging is easier.

**Decoupled code.** The model doesn't know what happens when status changes. It just fires the event. Listeners handle notifications, logging, webhooks, whatever.

**Easy auditing.** Need to know every place that responds to status changes? Search for `OrderStatusChanged`. It's all in one listener.

## Caveats

**Multiple events per save.** If you change both `status` and `warehouse_id` in one save, both events fire. Your listeners should be idempotent.

**Bulk updates bypass events.** As with all Eloquent events, `Model::where(...)->update([...])` doesn't fire events. Only saves on individual model instances do.

**Observer registration.** Don't forget to register your observers in a service provider, or use the `#[ObservedBy]` attribute on your models.

## Conclusion

Attribute-level events add surgical precision to Laravel's event system. Instead of checking which attributes changed inside every listener, you declare which attributes matter and write focused handlers for each.

For most applications, **the native observer approach is recommended** - it has zero dependencies and gives you full control over the logic. The package approach is a good choice if you prefer declarative syntax and have many attributes to track.

Either way, the result is cleaner code, easier testing, and a clear audit trail of what responds to what.
