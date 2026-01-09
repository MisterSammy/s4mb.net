---
title: "Building a State Machine Without a State Machine Library"
date: 2025-01-09
excerpt: "You don't always need a package. Sometimes PHP enums and helper classes are all you need to model complex workflows with multiple states, transitions, and role-based actions."
tags: [laravel, php, architecture, enums, workflow]
slug: building-state-machines-without-a-library
---

State machines are everywhere in business applications. Orders move from pending to paid to shipped to delivered. Support tickets escalate from open to in-progress to resolved. User accounts progress through verification steps before becoming active.

The instinct when facing these requirements is to reach for a state machine package. Laravel has several good ones—`spatie/laravel-model-states`, `asantibanez/laravel-eloquent-state-machines`, and others. They're well-designed and battle-tested.

But sometimes a package is overkill. If your states are relatively simple, your transitions are predictable, and you want to keep your dependencies lean, you can build something elegant with just PHP 8.1 enums and a few helper classes.

This post walks through a pattern I used for a client onboarding platform where entities moved through multiple verification stages. The system needed to track where each client was in the process, determine what actions were valid at each stage, and show different UI guidance to different user roles.

## The Problem

Consider a client onboarding workflow with these states:

1. **Conflict Checks** — Initial screening for conflicts of interest
2. **Risk Assessment** — Evaluating the client's risk profile
3. **AML Verification** — Anti-money laundering identity checks
4. **Active** — Fully onboarded and ready to work with

Plus several rejection states when things go wrong:

5. **Rejected (Conflict Checks)** — Failed the conflict screening
6. **Rejected (Risk Assessment)** — Too risky to proceed
7. **Rejected (AML Verification)** — Identity verification failed
8. **Rejected (Denied)** — Manually denied by staff

Each state has rules about what actions are valid. You can't create instructions for a client still in conflict checks. You can't skip verification unless you're an approving director. The UI needs to show different guidance depending on whether you're staff or the client themselves.

A state machine package could handle this, but let's see how far we get with native PHP.

## Defining States with Enums

PHP 8.1 introduced backed enums, which are perfect for representing states. Each case has an integer value (for database storage) and can have methods attached:

```php
<?php

namespace App\Enums;

enum ClientStatus: int
{
    case CONFLICT_CHECKS = 1;
    case RISK_ASSESSMENT = 2;
    case AML_VERIFICATION = 3;
    case ACTIVE = 4;
    case REJECTED_CONFLICT_CHECKS = 5;
    case REJECTED_RISK_ASSESSMENT = 6;
    case REJECTED_AML_VERIFICATION = 7;
    case REJECTED_DENIED = 8;

    // Human-readable names for display
    public function getName(): string
    {
        return match($this) {
            self::CONFLICT_CHECKS => 'Conflict Checks',
            self::RISK_ASSESSMENT => 'Risk Assessment',
            self::AML_VERIFICATION => 'AML Verification',
            self::ACTIVE => 'Active',
            self::REJECTED_CONFLICT_CHECKS => 'Rejected (Conflict Checks)',
            self::REJECTED_RISK_ASSESSMENT => 'Rejected (Risk Assessment)',
            self::REJECTED_AML_VERIFICATION => 'Rejected (AML Verification)',
            self::REJECTED_DENIED => 'Rejected (Denied)',
        };
    }

    // Tailwind classes for status badges
    public function getStyles(): string
    {
        return match($this) {
            self::CONFLICT_CHECKS => 'bg-red-200',
            self::RISK_ASSESSMENT => 'bg-yellow-200',
            self::AML_VERIFICATION => 'bg-green-200',
            self::ACTIVE => 'bg-blue-200',
            self::REJECTED_CONFLICT_CHECKS => 'bg-purple-200',
            self::REJECTED_RISK_ASSESSMENT => 'bg-orange-200',
            self::REJECTED_AML_VERIFICATION => 'bg-pink-200',
            self::REJECTED_DENIED => 'bg-orange-200',
        };
    }
}
```

This gives you several benefits immediately:

**Type safety.** The `status` property on your model can only be one of these values. No typos, no invalid states.

**IDE support.** Autocompletion shows you all possible states when you type `ClientStatus::`.

**Single source of truth.** Display names and styling are defined once, alongside the state definitions.

**Database efficiency.** The integer backing means your `status` column is a tiny `TINYINT` rather than a string.

In your model, you store and retrieve the integer value:

```php
// Storing a status
$client->status = ClientStatus::RISK_ASSESSMENT->value;

// Reading a status (convert int back to enum)
$status = ClientStatus::tryFrom($client->status);
echo $status->getName(); // "Risk Assessment"
```

The `tryFrom()` method returns `null` if the integer doesn't match any case, which is safer than `from()` which throws an exception.

## The Status Evaluator Pattern

Now comes the interesting part: determining what actions are valid for each state. You could put this logic in the model, but that leads to bloated models with dozens of methods. Instead, create a dedicated evaluator class:

```php
<?php

namespace App\Helpers;

use App\Enums\ClientStatus;
use Illuminate\Support\Facades\Auth;

class ClientStatusEvaluator
{
    public static function canDeleteClient($status): bool
    {
        // Can only delete clients in rejected states
        return match ($status) {
            ClientStatus::REJECTED_CONFLICT_CHECKS->value,
            ClientStatus::REJECTED_RISK_ASSESSMENT->value,
            ClientStatus::REJECTED_AML_VERIFICATION->value => true,
            default => false,
        };
    }

    public static function canSkipVerification($status): bool
    {
        // Only approving directors can skip, and only during AML stage
        if (!Auth::user()->isApprovingDirector) {
            return false;
        }
        
        return $status === ClientStatus::AML_VERIFICATION->value;
    }

    public static function canSetActiveClient($status): bool
    {
        // Can only activate from AML verification stage
        return $status === ClientStatus::AML_VERIFICATION->value;
    }

    public static function canCreateInstructions($status): bool
    {
        // Can create instructions in any state except initial conflict checks
        return $status !== ClientStatus::CONFLICT_CHECKS->value;
    }

    public static function canCreateVerification($status): bool
    {
        return $status === ClientStatus::AML_VERIFICATION->value;
    }

    public static function canReadVerification($status): bool
    {
        return match ($status) {
            ClientStatus::AML_VERIFICATION->value,
            ClientStatus::ACTIVE->value,
            ClientStatus::REJECTED_CONFLICT_CHECKS->value,
            ClientStatus::REJECTED_RISK_ASSESSMENT->value,
            ClientStatus::REJECTED_AML_VERIFICATION->value => true,
            default => false,
        };
    }
}
```

The pattern is simple: static methods that take a status value and return a boolean. Some methods check only the status, others also check the current user's role.

In your controllers and Livewire components, use the evaluator before performing actions:

```php
public function setActive(Client $client)
{
    if (!Auth::user()->hasPermission('client:set-active')) {
        abort(403);
    }
    
    if (!ClientStatusEvaluator::canSetActiveClient($client->status)) {
        abort(400, 'Cannot activate client from current status');
    }

    $client->status = ClientStatus::ACTIVE->value;
    $client->save();

    return redirect()->back();
}
```

This keeps your authorization logic (`hasPermission`) separate from your state transition logic (`canSetActiveClient`). Both must pass for the action to proceed.

## Automatic State Transitions

Some state changes should happen automatically when related records are created. For example, when a conflict check is submitted and passes, the client should automatically advance to the risk assessment stage.

In a Livewire component that handles conflict check creation:

```php
public function submit()
{
    $client = Client::findOrFail($this->client_id);
    
    if (!Auth::user()->hasPermission('client-conflict-check:create')) {
        abort(403);
    }

    $conflictCheck = ClientConflictCheck::create([
        'client_id' => $client->id,
        'user_id' => Auth::user()->id,
        'status' => $this->form->getState()['status']
    ]);

    // Automatic state transition based on outcome
    if ($conflictCheck->status === 'conflicts_found') {
        $client->status = ClientStatus::REJECTED_CONFLICT_CHECKS->value;
    } else {
        $client->status = ClientStatus::RISK_ASSESSMENT->value;
    }

    $client->save();
    
    $this->emit('refreshClient');
    $this->closeModal();
}
```

The state machine logic lives right where the action happens. When a conflict check is created, the system immediately determines the next state based on the outcome. No need to remember to update the client status separately.

## The Next Step Helper

Different users need different guidance at each stage. Staff members need to know what action to take next. Clients need to understand what's happening with their application.

A helper class with a large configuration array handles this elegantly:

```php
<?php

namespace App\Helpers;

class NextStepHelper
{
    const CLIENT_SETUP_MESSAGE = "We're getting your account set up. You can leave a message on the Discussion tab and your adviser will be in touch.";

    const VALUES = [
        'App\Models\Client' => [
            'adviser' => [
                1 => [ // CONFLICT_CHECKS
                    'message' => "Nice one, you've added a new Client! Next step, do a conflict check:",
                    'link' => '<button onclick="...">Create Conflict Check</button>',
                ],
                2 => [ // RISK_ASSESSMENT
                    'message' => "There were no conflicts flagged. Now complete a Risk Assessment:",
                    'link' => '<button onclick="...">Add Risk Assessment</button>',
                ],
                3 => [ // AML_VERIFICATION
                    'message' => "Request AML Verification for each relevant person. When complete, click proceed.",
                    'link' => '<button onclick="...">Set Client Active</button>',
                ],
                4 => [ // ACTIVE
                    'message' => "The client is fully onboarded. Create an instruction to start work:",
                    'link' => '<button onclick="...">Add Instruction</button>',
                ],
                // ... rejection states with recovery guidance
            ],
            'account-holder' => [
                1 => [ // CONFLICT_CHECKS
                    'message' => self::CLIENT_SETUP_MESSAGE,
                    'link' => '',
                ],
                2 => [ // RISK_ASSESSMENT  
                    'message' => self::CLIENT_SETUP_MESSAGE,
                    'link' => '',
                ],
                3 => [ // AML_VERIFICATION
                    'message' => "We need to verify your identity. Click here to start:",
                    'link' => '<a href="/verify-user"><button>Complete Verification</button></a>',
                ],
                4 => [ // ACTIVE
                    'message' => "Your account is active. Check the Instructions tab to see work in progress.",
                    'link' => '',
                ],
            ],
        ],
    ];
}
```

The structure is `[Model][Role][Status]`. In your Blade views, look up the appropriate message:

```blade
@php
    $role = Auth::user()->hasRole('adviser') ? 'adviser' : 'account-holder';
    $nextStep = App\Helpers\NextStepHelper::VALUES['App\Models\Client'][$role][$client->status] ?? null;
@endphp

@if($nextStep)
    <div class="next-step-card">
        <p>{{ $nextStep['message'] }}</p>
        {!! $nextStep['link'] !!}
    </div>
@endif
```

Staff see actionable guidance with buttons to perform the next step. Clients see friendly status messages. The same component renders different content based on who's viewing it.

## Visual Checklists

For workflows with many steps, a checklist provides at-a-glance progress indication. Another helper class generates the checklist based on current state:

```php
<?php

namespace App\Helpers;

use App\Enums\ClientStatus;
use App\Models\Client;

class ChecklistHelper
{
    public static function getClientChecklist(Client $client): array
    {
        $checklist = [];

        if ($client->status == ClientStatus::CONFLICT_CHECKS->value) {
            $checklist = [
                ['icon' => '⬜️', 'message' => 'Complete a Conflict Checks form'],
                ['icon' => '⬜️', 'message' => 'Complete a Risk Assessment form'],
                ['icon' => '⬜️', 'message' => 'Request AML Verification'],
                ['icon' => '⬜️', 'message' => 'Active'],
            ];
        }

        if ($client->status == ClientStatus::RISK_ASSESSMENT->value) {
            $checklist = [
                ['icon' => '✅', 'message' => 'Complete a Conflict Checks form'],
                ['icon' => '⬜️', 'message' => 'Complete a Risk Assessment form'],
                ['icon' => '⬜️', 'message' => 'Request AML Verification'],
                ['icon' => '⬜️', 'message' => 'Active'],
            ];
        }

        if ($client->status == ClientStatus::ACTIVE->value) {
            $checklist = [
                ['icon' => '✅', 'message' => 'Complete a Conflict Checks form'],
                ['icon' => '✅', 'message' => 'Complete a Risk Assessment form'],
                ['icon' => '✅', 'message' => 'Request AML Verification'],
                ['icon' => '✅', 'message' => 'Active'],
            ];
        }

        // Handle rejection states by inserting failure indicators
        if ($client->status == ClientStatus::REJECTED_CONFLICT_CHECKS->value) {
            $checklist = [
                ['icon' => '✅', 'message' => 'Complete a Conflict Checks form'],
                ['icon' => '❌', 'message' => 'Failed Conflict Checks'],
                ['icon' => '⬜️', 'message' => 'Complete a Risk Assessment form'],
                ['icon' => '⬜️', 'message' => 'Request AML Verification'],
                ['icon' => '⬜️', 'message' => 'Active'],
            ];
        }

        return $checklist;
    }
}
```

Pass the checklist to your view:

```php
return view('clients.show', [
    'client' => $client,
    'checklist' => ChecklistHelper::getClientChecklist($client),
]);
```

Render it with a simple loop:

```blade
<ul class="space-y-2">
    @foreach($checklist as $item)
        <li class="flex items-center gap-2">
            <span>{{ $item['icon'] }}</span>
            <span>{{ $item['message'] }}</span>
        </li>
    @endforeach
</ul>
```

## When to Use This Pattern

This approach works well when:

**States are relatively few.** Eight to fifteen states is manageable. Beyond that, the helper classes become unwieldy.

**Transitions are predictable.** The workflow follows a mostly linear path with some branches for rejections.

**You want minimal dependencies.** No packages to update, no breaking changes to navigate.

**The logic is domain-specific.** State machine packages are generic. Your helper classes can encode business rules directly.

## When to Reach for a Package

Consider a dedicated state machine package when:

**States number in the dozens.** Configuration files or database-driven states become more practical.

**Transitions have complex guards.** Multiple conditions, async checks, or external service calls.

**You need transition history.** Packages often include audit logging of state changes.

**Multiple developers need to understand the system.** Packages come with documentation and established patterns.

## Conclusion

Not every workflow needs a state machine library. PHP 8.1 enums provide type-safe state definitions with attached behavior. Helper classes organize transition logic and role-based guidance. Automatic transitions in your action handlers keep related logic together.

The result is a system that's easy to understand, easy to modify, and has zero external dependencies. For many applications, that's exactly the right trade-off.

The pattern scales down beautifully too. Even a simple "draft → published → archived" workflow benefits from an enum with display names and a helper that checks whether publishing is allowed. Start simple, and you'll know when you've outgrown it.

