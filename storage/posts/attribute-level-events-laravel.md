---
title: "Attribute-Level Events in Laravel with the kleemans/attribute-events Package"
date: 2025-01-09
excerpt: "Model events are great, but what if you need to fire events only when specific attributes change? Here's a pattern that decouples your event handling beautifully."
tags: [laravel, events, eloquent, php, architecture]
slug: attribute-level-events-laravel
---

Laravel's Eloquent models fire events when things happen—`created`, `updated`, `deleted`, and so on. These events are useful for triggering side effects like sending notifications, updating caches, or logging changes.

But there's a limitation. The `updated` event fires whenever *any* attribute changes. If you only care about status changes, you end up checking inside your listener:

```php
public function handle(ClientUpdated $event)
{
    // Did the status actually change?
    if (!$event->client->wasChanged('status')) {
        return;
    }
    
    // Now do the real work
    $this->notifyTeam($event->client);
}
```

This works, but it's messy. Your listener fires for every update, wastes cycles checking conditions, and the intent isn't clear from the event name alone.

What if you could fire different events for different attribute changes? Status changes trigger `ClientStatusChanged`. Title changes trigger `ClientTitleChanged`. Each listener handles exactly what it cares about.

The `kleemans/attribute-events` package makes this possible with a clean, declarative syntax.

## Installation

```bash
composer require kleemans/attribute-events
```

Then add the trait to your model:

```php
use Kleemans\AttributeEvents;

class Client extends Model
{
    use AttributeEvents;
}
```

## Defining Attribute Events

Now you can map specific attributes to events using a wildcard syntax:

```php
<?php

namespace App\Models;

use App\Events\Client\ClientAddressUpdated;
use App\Events\Client\ClientCompanyNameUpdated;
use App\Events\Client\ClientStatusChanged;
use App\Events\Client\ClientCreated;
use App\Events\Client\ClientEdited;
use Illuminate\Database\Eloquent\Model;
use Kleemans\AttributeEvents;

class Client extends Model
{
    use AttributeEvents;

    protected $dispatchesEvents = [
        // Standard model events
        'created' => ClientCreated::class,
        'updated' => ClientEdited::class,
        
        // Attribute-specific events (the :* means "any change")
        'address:*' => ClientAddressUpdated::class,
        'company_name:*' => ClientCompanyNameUpdated::class,
        'status:*' => ClientStatusChanged::class,
    ];
}
```

The `:*` wildcard means "fire this event when this attribute changes to any value." When you update a client's status, Laravel fires both `ClientEdited` (because the model was updated) and `ClientStatusChanged` (because the status attribute specifically changed).

## Creating the Events

Each event class receives the model instance. Create them like any Laravel event:

```php
<?php

namespace App\Events\Client;

use App\Models\Client;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Client $client
    ) {}
}
```

For attribute events, you can also access the old and new values:

```php
class ClientStatusChanged
{
    use Dispatchable, SerializesModels;

    public Client $client;
    public mixed $oldValue;
    public mixed $newValue;

    public function __construct(Client $client, $oldValue = null, $newValue = null)
    {
        $this->client = $client;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
    }
}
```

The package passes these values automatically when the event fires.

## Creating Listeners

Now your listeners can be focused and specific. A status change listener doesn't need to check whether the status actually changed—it only fires when it does:

```php
<?php

namespace App\Listeners\Client;

use App\Enums\ClientStatus;
use App\Jobs\LogActivity;
use App\Models\User;
use App\Notifications\Client\ClientStatusChangedNotification;
use Illuminate\Support\Facades\Notification;

class ClientStatusChanged
{
    public function handle($event)
    {
        $client = $event->client;
        
        // Notify relevant team members
        Notification::send(
            User::whereShouldBeNotified($client->team->id, 'Client Status Changed'),
            new ClientStatusChangedNotification($client)
        );
        
        // Log the change
        // Some status changes should be visible to clients, others only to staff
        $staffOnly = match($client->status) {
            ClientStatus::AML_VERIFICATION->value,
            ClientStatus::ACTIVE->value => false, // Clients can see these
            default => true, // Internal statuses are staff-only
        };
        
        LogActivity::dispatch(
            "The client status changed to " . ClientStatus::tryFrom($client->status)->getName(),
            'App\Models\Client',
            $client->id,
            1, // System user
            $staffOnly
        );
    }
}
```

## Registering Event-Listener Pairs

Wire up your events and listeners in the EventServiceProvider:

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Model lifecycle events
        \App\Events\Client\ClientCreated::class => [
            \App\Listeners\Client\ClientCreated::class,
        ],
        \App\Events\Client\ClientEdited::class => [
            \App\Listeners\Client\ClientEdited::class,
        ],
        
        // Attribute-specific events
        \App\Events\Client\ClientStatusChanged::class => [
            \App\Listeners\Client\ClientStatusChanged::class,
        ],
        \App\Events\Client\ClientAddressUpdated::class => [
            \App\Listeners\Client\ClientAddressUpdated::class,
        ],
        \App\Events\Client\ClientCompanyNameUpdated::class => [
            \App\Listeners\Client\ClientCompanyNameUpdated::class,
        ],
    ];
}
```

## Practical Example: Multi-Model Events

This pattern shines when you have multiple models with similar attribute tracking needs. Here's how it looks for both clients and instructions:

```php
// Client.php
protected $dispatchesEvents = [
    'created' => ClientCreated::class,
    'updated' => ClientEdited::class,
    'status:*' => ClientStatusChanged::class,
    'address:*' => ClientAddressUpdated::class,
    'company_name:*' => ClientCompanyNameUpdated::class,
    'company_number:*' => ClientCompanyNumberUpdated::class,
    'contact_number:*' => ClientContactNumberUpdated::class,
    'country:*' => ClientCountryUpdated::class,
    'client_type:*' => ClientTypeUpdated::class,
];

// Instruction.php
protected $dispatchesEvents = [
    'created' => InstructionCreated::class,
    'updated' => InstructionEdited::class,
    'title:*' => InstructionTitleUpdated::class,
    'description:*' => InstructionDescriptionUpdated::class,
    'status:*' => InstructionStatusChanged::class,
];

// Verification.php
protected $dispatchesEvents = [
    'status:*' => VerificationStatusUpdated::class,
    'result:*' => VerificationResultUpdated::class,
];
```

Each model declares which attributes matter for event purposes. The listeners for each event handle the specific business logic.

## Benefits of This Pattern

**Clear intent.** When you see `ClientStatusChanged`, you know exactly what triggered it. No need to dig through a generic `ClientUpdated` listener to understand what it handles.

**Focused listeners.** Each listener does one thing. Testing is simpler. Debugging is easier.

**Decoupled code.** The model doesn't know what happens when status changes. It just fires the event. Listeners handle notifications, logging, webhooks, whatever.

**Easy auditing.** Need to know every place that responds to status changes? Search for `ClientStatusChanged`. It's all in one listener (or a small set of listeners).

## Combining with Activity Logging

A common pattern is logging all attribute changes for audit purposes. Create a generic listener that handles the `updated` event and logs any changes:

```php
class ClientEdited
{
    public function handle($event)
    {
        $client = $event->client;
        $changes = $client->getChanges();
        
        foreach ($changes as $attribute => $newValue) {
            // Skip timestamps
            if (in_array($attribute, ['updated_at', 'created_at'])) {
                continue;
            }
            
            $oldValue = $client->getOriginal($attribute);
            
            LogActivity::dispatch(
                "{$attribute} changed from '{$oldValue}' to '{$newValue}'",
                'App\Models\Client',
                $client->id,
                auth()->id() ?? 1,
                true // Staff only
            );
        }
    }
}
```

This logs all changes, while specific attribute listeners handle the important ones (like status) with custom logic.

## Caveats

**Multiple events per save.** If you change both `status` and `address` in one save, both attribute events fire plus the `updated` event. Your listeners should be idempotent.

**Bulk updates bypass events.** As with all Eloquent events, `Model::where(...)->update([...])` doesn't fire events. Only saves on individual model instances do.

**Event order.** Attribute events fire after the standard `updated` event. Don't rely on specific ordering between different attribute events.

## Conclusion

The `kleemans/attribute-events` package adds surgical precision to Laravel's event system. Instead of checking which attributes changed inside every listener, you declare which attributes matter and write focused handlers for each.

The result is cleaner code, easier testing, and a clear audit trail of what responds to what. For any application with complex models and side effects triggered by specific changes, it's a pattern worth adopting.

