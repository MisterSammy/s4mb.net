---
title: "Building a State Machine Without a State Machine Library"
date: 2025-01-09
excerpt: "For simple workflows, PHP enums with transition methods are all you need. But for complex state machines with many states and guards, consider a dedicated package."
tags: [laravel, php, architecture, enums, workflow]
slug: building-state-machines-without-a-library
---

State machines are everywhere in business applications. Orders move from pending to paid to shipped to delivered. Support tickets escalate from open to in-progress to resolved. Vendor applications progress through approval stages before becoming active.

The instinct when facing these requirements is to reach for a state machine package. Laravel has several good ones - `spatie/laravel-model-states`, `asantibanez/laravel-eloquent-state-machines`, and others. They're well-designed and battle-tested.

But sometimes a package is overkill. If your workflow is **truly simple** - 3-5 states, linear progression, predictable transitions - you can build something elegant with just PHP 8.1 enums. This post shows you when that makes sense, and when to reach for a package instead.

## The Simple Case: Food Truck Festival Vendor Applications

Consider a food truck festival where vendors submit applications that move through a straightforward approval process:

1. **Application Submitted**  -  Vendor applied to participate
2. **Health Inspection**  -  Health department reviews permits
3. **Insurance Review** .  Festival organizers verify insurance
4. **Approved**  -  Ready to participate
5. **Rejected**  -  Application denied (from any stage)

This workflow has a few key properties that make it a good candidate for a simple enum-based approach:
- Small number of states (5 total)
- Mostly linear progression
- Predictable transitions
- No complex guards or async checks

For this use case, an enum with transition validation is sufficient:

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

The key to a simple state machine is preventing invalid transitions. Add transition validation directly to the enum:

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

## When This Simple Pattern Works Well

This enum-based approach works well when:

**States are few (3-8).** Beyond that, the `match` statements become unwieldy.

**Transitions are predictable.** The workflow follows a mostly linear path with some branches.

**No complex guards.** Transitions don't depend on multiple conditions, async checks, or external service calls.

**No transition history needed.** You don't need to audit who changed states when (though you can still log this separately).

**Minimal dependencies.** You want to avoid external packages for simple workflows.

## When to Reach for a Package

Now consider a more complex workflow - a vendor application system for a large multi-day festival:

- **10+ states:** Application → Initial Review → Background Check → Health Permit → Insurance → Fire Safety → Capacity Review → Payment → Final Approval → Active → Suspended → Rejected (from multiple stages)
- **Role-based transitions:** Only festival coordinators can approve, but vendors can submit documents
- **Time-based guards:** Applications auto-reject if not completed within 30 days
- **Transition history:** Need to audit all state changes for compliance
- **Side effects:** State changes trigger webhooks, notifications, and external API calls

For this complexity, a dedicated state machine package like `spatie/laravel-model-states` is the right choice:

```php
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class VendorApplicationState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(ApplicationSubmitted::class)
            ->allowTransition(ApplicationSubmitted::class, HealthInspection::class)
            ->allowTransition(HealthInspection::class, InsuranceReview::class, [
                HealthInspectionPassedGuard::class,
            ])
            ->allowTransition(InsuranceReview::class, Approved::class, [
                InsuranceVerifiedGuard::class,
                PaymentCompletedGuard::class,
            ]);
    }
}
```

Packages provide:
- **Transition history** with automatic audit logging
- **Guard classes** for complex validation logic
- **Event hooks** for side effects (notifications, webhooks)
- **Better tooling** for debugging and visualization
- **Documentation** and established patterns

## Conclusion

For truly simple workflows with 3-8 states and predictable transitions, PHP enums with transition validation are elegant and sufficient. They provide type safety, IDE support, and enforce valid transitions - all without external dependencies.

But as complexity grows - more states, complex guards, transition history, multiple roles - a dedicated state machine package becomes the pragmatic choice. The enum approach works until it doesn't, and you'll know when you've outgrown it.

Start simple. If your workflow stays simple, the enum approach will serve you well. If it grows complex, you'll appreciate having a battle-tested package to handle the edge cases.
