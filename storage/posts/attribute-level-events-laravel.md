---
title: "Attribute-Level Events in Laravel: Package vs. Native Approaches"
date: 2025-01-09
excerpt: "Model events are great, but what if you need to fire events only when specific attributes change? Here's two approaches—one with a package, one native."
tags: [laravel, events, eloquent, php, architecture]
slug: attribute-level-events-laravel
---

Laravel's Eloquent models fire events when things happen—`created`, `updated`, `deleted`, and so on. These events are useful for triggering side effects like sending notifications, updating caches, or logging changes.

But there's a limitation. The `updated` event fires whenever *any* attribute changes. If you only care about status changes, you end up checking inside your listener:

```php
public function handle(AppointmentUpdated $event): void
{
    // Did the status actually change?
    if (!$event->appointment->wasChanged('status')) {
        return;
    }
    
    // Now do the real work
    $this->notifyGroomer($event->appointment);
}
```

This works, but it's messy. Your listener fires for every update, wastes cycles checking conditions, and the intent isn't clear from the event name alone.

What if you could fire different events for different attribute changes? Status changes trigger `AppointmentStatusChanged`. Pet notes change triggers `AppointmentNotesUpdated`. Each listener handles exactly what it cares about.

This post shows two approaches: using a package for declarative syntax, and using native Laravel observers for zero dependencies.

## The Scenario: Pet Grooming Appointments

Consider a pet grooming salon where appointments move through various statuses:
- **Scheduled** — Appointment booked
- **Checked In** — Pet has arrived
- **In Progress** — Grooming underway
- **Completed** — Ready for pickup
- **Cancelled** — Appointment cancelled

Different status changes trigger different actions:
- Moving to "Checked In" notifies the groomer
- Moving to "Completed" sends an SMS to the pet owner
- Moving to "Cancelled" refunds the deposit

## Approach 1: Native Laravel with Observers (Recommended)

For most applications, Laravel's built-in observers with `wasChanged()` provide everything you need without adding a dependency:

```php
<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Events\Appointment\AppointmentStatusChanged;
use App\Models\Appointment;

class AppointmentObserver
{
    public function updated(Appointment $appointment): void
    {
        // Only fire status event if status actually changed
        if ($appointment->wasChanged('status')) {
            event(new AppointmentStatusChanged(
                appointment: $appointment,
                oldStatus: $appointment->getOriginal('status'),
                newStatus: $appointment->status,
            ));
        }

        // Fire notes event if notes changed
        if ($appointment->wasChanged('groomer_notes')) {
            event(new AppointmentNotesUpdated($appointment));
        }
    }
}
```

Register the observer in your `AppServiceProvider`:

```php
use App\Models\Appointment;
use App\Observers\AppointmentObserver;

public function boot(): void
{
    Appointment::observe(AppointmentObserver::class);
}
```

Create the event class:

```php
<?php

namespace App\Events\Appointment;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public ?AppointmentStatus $oldStatus,
        public AppointmentStatus $newStatus,
    ) {}
}
```

### Using Event Discovery for Listeners

Laravel 12 supports automatic event discovery. Create a listener and Laravel will find it automatically:

```php
<?php

namespace App\Listeners\Appointment;

use App\Enums\AppointmentStatus;
use App\Events\Appointment\AppointmentStatusChanged;
use App\Notifications\Appointment\PetReadyForPickupNotification;
use App\Notifications\Appointment\AppointmentCancelledNotification;

class HandleAppointmentStatusChange
{
    public function handle(AppointmentStatusChanged $event): void
    {
        $appointment = $event->appointment;

        match($event->newStatus) {
            AppointmentStatus::CHECKED_IN => $this->notifyGroomer($appointment),
            AppointmentStatus::COMPLETED => $this->notifyPetOwner($appointment),
            AppointmentStatus::CANCELLED => $this->processRefund($appointment),
            default => null,
        };
    }

    private function notifyGroomer(Appointment $appointment): void
    {
        $appointment->groomer->notify(
            new PetCheckedInNotification($appointment)
        );
    }

    private function notifyPetOwner(Appointment $appointment): void
    {
        $appointment->customer->notify(
            new PetReadyForPickupNotification($appointment)
        );
    }

    private function processRefund(Appointment $appointment): void
    {
        if ($appointment->deposit_amount > 0) {
            $appointment->customer->notify(
                new AppointmentCancelledNotification($appointment)
            );
            
            // Trigger refund process
            RefundDeposit::dispatch($appointment);
        }
    }
}
```

Event discovery works automatically when your listener's `handle` method type-hints the event class.

### Checking Multiple Attributes

The observer pattern makes it easy to check multiple attributes:

```php
public function updated(Appointment $appointment): void
{
    if ($appointment->wasChanged('status')) {
        event(new AppointmentStatusChanged(
            $appointment,
            $appointment->getOriginal('status'),
            $appointment->status,
        ));
    }

    if ($appointment->wasChanged('groomer_id')) {
        event(new AppointmentGroomerChanged(
            $appointment,
            $appointment->getOriginal('groomer_id'),
            $appointment->groomer_id,
        ));
    }

    if ($appointment->wasChanged(['scheduled_at', 'duration_minutes'])) {
        event(new AppointmentRescheduled($appointment));
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

use App\Enums\AppointmentStatus;
use App\Events\Appointment\AppointmentCreated;
use App\Events\Appointment\AppointmentStatusChanged;
use App\Events\Appointment\AppointmentNotesUpdated;
use App\Events\Appointment\AppointmentGroomerChanged;
use Illuminate\Database\Eloquent\Model;
use Kleemans\AttributeEvents;

class Appointment extends Model
{
    use AttributeEvents;

    protected $dispatchesEvents = [
        // Standard model events
        'created' => AppointmentCreated::class,
        
        // Attribute-specific events (the :* means "any change")
        'status:*' => AppointmentStatusChanged::class,
        'groomer_notes:*' => AppointmentNotesUpdated::class,
        'groomer_id:*' => AppointmentGroomerChanged::class,
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_at' => 'datetime',
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
class AppointmentObserver
{
    public function updated(Appointment $appointment): void
    {
        if ($appointment->wasChanged('status')) {
            event(new AppointmentStatusChanged($appointment, ...));
        }
    }
}

class PetObserver
{
    public function updated(Pet $pet): void
    {
        if ($pet->wasChanged('medical_notes')) {
            event(new PetMedicalNotesUpdated($pet));
        }
        
        if ($pet->wasChanged('temperament')) {
            event(new PetTemperamentUpdated($pet));
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
        
        if ($customer->wasChanged('phone')) {
            event(new CustomerPhoneChanged($customer, ...));
        }
    }
}
```

Register all observers in your service provider:

```php
public function boot(): void
{
    Appointment::observe(AppointmentObserver::class);
    Pet::observe(PetObserver::class);
    Customer::observe(CustomerObserver::class);
}
```

## Combining with Activity Logging

A common pattern is logging all attribute changes for audit purposes. Use the generic `updated` method for comprehensive logging, and specific events for business logic:

```php
class AppointmentObserver
{
    public function updated(Appointment $appointment): void
    {
        // Log all changes for audit trail
        $this->logChanges($appointment);
        
        // Fire specific events for business logic
        if ($appointment->wasChanged('status')) {
            event(new AppointmentStatusChanged($appointment, ...));
        }
    }

    private function logChanges(Appointment $appointment): void
    {
        $changes = $appointment->getChanges();
        
        foreach ($changes as $attribute => $newValue) {
            if (in_array($attribute, ['updated_at', 'created_at'])) {
                continue;
            }
            
            $oldValue = $appointment->getOriginal($attribute);
            
            activity()
                ->performedOn($appointment)
                ->withProperties([
                    'attribute' => $attribute,
                    'old' => $oldValue,
                    'new' => $newValue,
                ])
                ->log("Appointment {$attribute} changed");
        }
    }
}
```

## Benefits of This Pattern

**Clear intent.** When you see `AppointmentStatusChanged`, you know exactly what triggered it. No need to dig through a generic `AppointmentUpdated` listener.

**Focused listeners.** Each listener does one thing. Testing is simpler. Debugging is easier.

**Decoupled code.** The model doesn't know what happens when status changes. It just fires the event. Listeners handle notifications, logging, webhooks, whatever.

**Easy auditing.** Need to know every place that responds to status changes? Search for `AppointmentStatusChanged`. It's all in one listener.

## Caveats

**Multiple events per save.** If you change both `status` and `groomer_id` in one save, both events fire. Your listeners should be idempotent.

**Bulk updates bypass events.** As with all Eloquent events, `Model::where(...)->update([...])` doesn't fire events. Only saves on individual model instances do.

**Observer registration.** Don't forget to register your observers in a service provider, or use the `#[ObservedBy]` attribute on your models.

## Conclusion

Attribute-level events add surgical precision to Laravel's event system. Instead of checking which attributes changed inside every listener, you declare which attributes matter and write focused handlers for each.

For most applications, **the native observer approach is recommended**—it has zero dependencies and gives you full control over the logic. The package approach is a good choice if you prefer declarative syntax and have many attributes to track.

Either way, the result is cleaner code, easier testing, and a clear audit trail of what responds to what.
