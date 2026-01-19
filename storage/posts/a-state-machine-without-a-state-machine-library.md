---
title: "A State Machine Without a State Machine Library"
date: 2026-01-07
excerpt: "PHP 8.1 enums with transition methods give you a production-ready state machine pattern. Type-safe, testable, and dependency-free - perfect for Laravel applications."
tags: [laravel, php, architecture, enums, workflow]
slug: a-state-machine-without-a-state-machine-library
---

State machines are everywhere in business applications. Orders move from pending to paid to shipped to delivered. Support tickets escalate from open to in-progress to resolved. Vendor applications progress through approval stages before becoming active.

The instinct when facing these requirements is to reach for a state machine package. Laravel has several good ones - `spatie/laravel-model-states`, `asantibanez/laravel-eloquent-state-machines`, and others. They're well-designed and battle-tested.

But PHP 8.1 gave us backed enums, and with them, a powerful built-in pattern for state machines. Why add a dependency when the language gives you the tools? This post shows you how to build production-ready state machines with PHP enums - a pattern that scales from straightforward workflows to complex business processes with dozens of states.

## Example: Food Truck Festival Vendor Applications

Consider a food truck festival where vendors submit applications that move through an approval process:

1. **Application Submitted**  -  Vendor applied to participate
2. **Health Inspection**  -  Health department reviews permits
3. **Insurance Review**  -  Festival organizers verify insurance
4. **Approved**  -  Ready to participate
5. **Rejected**  -  Application denied (from any stage)

This is a real-world workflow, and the enum-based approach handles it elegantly. The same pattern scales to more complex domains with many states - the principles remain the same.

## Defining States with Enums

PHP 8.1 introduced backed enums, which are perfect for representing states. Each case has an integer value (for database storage) and can have methods attached:

```php
<?php

namespace App\Enums;

enum VendorStatus: int
{
    case APPLICATION_SUBMITTED = 1;
    case HEALTH_INSPECTION = 2;
    case INSURANCE_REVIEW = 3;
    case APPROVED = 4;
    case REJECTED = 5;

    // Human-readable names for display
    public function getName(): string
    {
        return match($this) {
            self::APPLICATION_SUBMITTED => 'Application Submitted',
            self::HEALTH_INSPECTION => 'Health Inspection',
            self::INSURANCE_REVIEW => 'Insurance Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    // Tailwind classes for status badges
    public function getStyles(): string
    {
        return match($this) {
            self::APPLICATION_SUBMITTED => 'bg-gray-200',
            self::HEALTH_INSPECTION => 'bg-yellow-200',
            self::INSURANCE_REVIEW => 'bg-blue-200',
            self::APPROVED => 'bg-green-200',
            self::REJECTED => 'bg-red-200',
        };
    }
}
```

This gives you several benefits immediately:

**Type safety.** The `status` property on your model can only be one of these values. No typos, no invalid states.

**IDE support.** Autocompletion shows you all possible states when you type `VendorStatus::`.

**Single source of truth.** Display names and styling are defined once, alongside the state definitions.

**Database efficiency.** The integer backing means your `status` column is a tiny `TINYINT` rather than a string.

In your model, you store and retrieve the integer value. Use Laravel's `casts()` method to automatically convert:

```php
<?php

namespace App\Models;

use App\Enums\VendorStatus;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
        ];
    }
}
```

Now you can work with the enum directly:

```php
// Storing a status
$vendor->status = VendorStatus::HEALTH_INSPECTION;

// Reading a status (automatically cast to enum)
echo $vendor->status->getName(); // "Health Inspection"

// Comparing
if ($vendor->status === VendorStatus::APPROVED) {
    // ...
}
```

## Transition Validation

The key to a robust state machine is preventing invalid transitions. Add transition validation directly to the enum:

```php
enum VendorStatus: int
{
    case APPLICATION_SUBMITTED = 1;
    case HEALTH_INSPECTION = 2;
    case INSURANCE_REVIEW = 3;
    case APPROVED = 4;
    case REJECTED = 5;

    public function getName(): string { /* ... */ }
    public function getStyles(): string { /* ... */ }

    /**
     * Check if this state can transition to the given state.
     */
    public function canTransitionTo(self $newState): bool
    {
        return match([$this, $newState]) {
            // Application can move to health inspection
            [self::APPLICATION_SUBMITTED, self::HEALTH_INSPECTION],
            // Health inspection can move to insurance review or rejection
            [self::HEALTH_INSPECTION, self::INSURANCE_REVIEW],
            [self::HEALTH_INSPECTION, self::REJECTED],
            // Insurance review can move to approved or rejection
            [self::INSURANCE_REVIEW, self::APPROVED],
            [self::INSURANCE_REVIEW, self::REJECTED],
            // Can reject from any active state
            [self::APPLICATION_SUBMITTED, self::REJECTED],
            // Terminal states: can't transition from approved or rejected
            default => false,
        };
    }

    /**
     * Transition to a new state, throwing an exception if invalid.
     */
    public function transitionTo(self $newState): self
    {
        if (!$this->canTransitionTo($newState)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$this->getName()} to {$newState->getName()}"
            );
        }
        return $newState;
    }
}
```

Now your model can enforce valid transitions:

```php
// In your controller or service
public function advanceStatus(Vendor $vendor, VendorStatus $newStatus): void
{
    if (!$vendor->status->canTransitionTo($newStatus)) {
        abort(400, 'Invalid status transition');
    }

    $vendor->status = $newStatus;
    $vendor->save();
}
```

Or use the enum's transition method:

```php
try {
    $vendor->status = $vendor->status->transitionTo(VendorStatus::APPROVED);
    $vendor->save();
} catch (\InvalidArgumentException $e) {
    // Handle invalid transition
}
```

## Checking Permissions by State

You can add permission checks directly to the enum as well:

```php
enum VendorStatus: int
{
    // ... cases ...

    /**
     * Can a festival coordinator perform actions on vendors in this state?
     */
    public function canCoordinatorManage(): bool
    {
        return match($this) {
            self::APPLICATION_SUBMITTED,
            self::HEALTH_INSPECTION,
            self::INSURANCE_REVIEW => true,
            self::APPROVED,
            self::REJECTED => false, // Terminal states
        };
    }

    /**
     * Can a vendor view their application in this state?
     */
    public function canVendorView(): bool
    {
        return true; // Vendors can always view their application
    }
}
```

In your controllers:

```php
public function update(Vendor $vendor)
{
    // Check authorization (permissions/roles)
    if (!auth()->user()->hasPermission('vendor:update')) {
        abort(403);
    }

    // Check state-based permissions
    if (!$vendor->status->canCoordinatorManage()) {
        abort(400, 'Cannot modify vendor in current status');
    }

    // Proceed with update
}
```

## Automatic State Transitions

Some state changes should happen automatically when related records are created. For example, when a health inspection passes, the vendor should automatically advance to the insurance review stage.

In a Livewire component that handles health inspection results:

```php
public function submitHealthInspection(): void
{
    $vendor = Vendor::findOrFail($this->vendor_id);
    
    if (!auth()->user()->hasPermission('vendor:health-inspection')) {
        abort(403);
    }

    $inspection = HealthInspection::create([
        'vendor_id' => $vendor->id,
        'inspector_id' => auth()->id(),
        'passed' => $this->passed,
    ]);

    // Automatic state transition based on outcome
    if ($this->passed) {
        $vendor->status = $vendor->status->transitionTo(VendorStatus::INSURANCE_REVIEW);
    } else {
        $vendor->status = $vendor->status->transitionTo(VendorStatus::REJECTED);
    }

    $vendor->save();
    
    $this->dispatch('vendor-updated');
}
```

The state machine logic lives right where the action happens. When an inspection completes, the system immediately determines the next state based on the outcome.

## Why This Pattern Works

The enum-based approach gives you several powerful advantages:

**Type safety at the language level.** The `status` property can only be one of the defined enum cases. No typos, no invalid states - the compiler enforces correctness. Your IDE autocompletes states, and static analysis tools catch invalid transitions before runtime.

**Zero dependencies.** You're using native PHP 8.1+ features. No package updates to manage, no breaking changes from external maintainers, no conflicts with other dependencies. The pattern is part of your codebase.

**Testability.** Enums are simple PHP classes. Testing transition logic is straightforward - you're testing pure functions, not complex package abstractions. Each `canTransitionTo()` call is easily unit tested.

**Maintainability.** All state logic lives in one place. When you need to add a new state or transition, you modify the enum. Refactoring is simple - find all usages of the enum, update the transition logic, and you're done. The pattern scales naturally - as your workflow grows to 20, 50, or even 100 states, you're still working with the same enum pattern.

**Performance.** No overhead from package abstractions. Enum comparisons are fast integer comparisons. No serialization layers, no complex state objects - just enum cases backed by integers in your database.

**Flexibility.** Need guards? Add them as methods on the enum. Need permission checks? Add them as methods. Need state-specific behavior? Match on the enum. The pattern doesn't box you in - it gives you a solid foundation to build on.

For larger enums, organize related transitions with helper methods, extract shared logic into traits, or split into separate enums per bounded context. The pattern scales with your domain.

## When Packages Offer Specific Features

The enum approach handles state machines of any size. However, dedicated packages like `spatie/laravel-model-states` or `asantibanez/laravel-eloquent-state-machines` offer specific features you might need:

- **Automatic transition history** - Built-in audit logging of state changes with timestamps and user tracking
- **Guard classes** - Structured guard objects for complex validation logic
- **Event hooks** - Automatic event dispatching on state transitions
- **Visualization tools** - Generated diagrams of your state machine
- **Testing utilities** - Helper methods for testing state transitions

If you need these features, a package makes sense. But many applications don't. You can add transition history with a separate `state_transitions` table and observers. You can dispatch events manually in your controllers or services. You can test enum methods directly with Pest or PHPUnit.

The decision should be feature-driven, not state-count-driven. An enum with 50 states is perfectly manageable. If you need automatic audit logging or complex guard abstractions, consider a package. Otherwise, enums give you everything you need.

## Conclusion

PHP enums with transition validation are a production-ready pattern for state machines in Laravel. They provide type safety, IDE support, testability, and enforce valid transitions - all without external dependencies. The pattern scales naturally from straightforward workflows to complex business processes with dozens or even hundreds of states.

Make enums your default choice. They're maintainable, performant, and flexible. When you need specific package features like automatic transition history or complex guard abstractions, evaluate packages on those merits. But don't assume you'll outgrow enums as your state machine grows - the pattern scales with your domain.

The best state machine is the one that solves your problem with the least complexity. For most Laravel applications, that's PHP enums.
