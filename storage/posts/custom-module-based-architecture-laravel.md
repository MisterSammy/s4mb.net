---
title: "Building a Custom Module-Based Architecture in Laravel"
date: 2026-02-09
excerpt: "How we structured an 11-module Laravel application with priority-based loading, cross-module communication via an event bus, and shared infrastructure. A practical guide to the modular monolith pattern."
tags: [laravel, architecture, modules, software-design, php]
slug: custom-module-based-architecture-laravel
---

Imagine you're building CreativeHub, a freelance marketplace. Clients post jobs, freelancers submit proposals, contracts get signed, work gets delivered. Simple enough! Until you realize you also need user profiles with portfolios, real-time chat between parties, project management for complex jobs, file storage for deliverables, and a CRM for client relationships.

Six months in, your `app/` directory has 40 models, 60 controllers, and finding anything requires arcane knowledge.

This is the modular monolith problem. You need the deployment simplicity of a monolith with the organizational clarity of microservices. The solution: modules.

## The Module Architecture Overview

Here's how we split CreativeHub. Instead of one massive `app/` directory, we have a `modules/` directory where each major feature lives independently:

```
modules/
├── System/       (priority: 100) - Core infrastructure, event bus
├── Profiles/     (priority: 90)  - Users, teams, portfolios
├── Files/        (priority: 85)  - Attachments, deliverables
├── Projects/     (priority: 80)  - Tasks, milestones
├── Marketplace/  (priority: 70)  - Jobs, proposals, contracts
├── Crm/          (priority: 50)  - Client relationships
└── Chat/         (priority: 40)  - Real-time messaging
```

Each module is self-contained with its own Models, Controllers, Actions, Events, Livewire components, and views. The `priority` number determines boot order - higher numbers load first. System at 100 boots before everything else because it provides the infrastructure that other modules depend on.

The module configuration lives in `config/modules.php`:

```php
<?php

declare(strict_types=1);

return [
    'path' => base_path('modules'),
    'namespace' => 'Modules',
    'auto_discovery' => true,

    'modules' => [
        'System' => [
            'enabled' => true,
            'priority' => 100,
            'provides' => [
                'components',
                'base-classes',
                'event-bus',
                'enums',
            ],
            'requires' => [],
            'description' => 'Core system components, base classes, and shared utilities',
        ],

        'Profiles' => [
            'enabled' => true,
            'priority' => 90,
            'provides' => [
                'user-profiles',
                'teams',
                'posts',
                'messaging',
                'follows',
                'social',
            ],
            'requires' => ['System'],
            'description' => 'User profiles, teams, posts, and social features',
            'config' => [
                'default_avatar' => null,
                'max_team_members' => 50,
            ],
        ],

        'Marketplace' => [
            'enabled' => true,
            'priority' => 70,
            'provides' => [
                'jobs',
                'proposals',
                'contracts',
                'deliveries',
            ],
            'requires' => ['System', 'Profiles'],
            'description' => 'Job listings, proposals, contracts, and service marketplace',
            'config' => [
                'platform_fee_percentage' => 10,
            ],
        ],

        'Chat' => [
            'enabled' => true,
            'priority' => 40,
            'provides' => [
                'conversations',
                'real-time-messaging',
            ],
            'requires' => ['System', 'Profiles'],
            'description' => 'Real-time chat and messaging system',
            'config' => [
                'max_message_length' => 5000,
            ],
        ],

        'Files' => [
            'enabled' => true,
            'priority' => 85,
            'provides' => [
                'file-storage',
                'file-management',
                'media',
            ],
            'requires' => ['System'],
            'description' => 'File storage and media management',
            'config' => [
                'max_upload_size' => 104857600, // 100MB
            ],
        ],
    ],
];
```

Notice the structure of each module definition:

**`enabled`** - A boolean flag to turn modules on or off. This lets you disable Chat for certain deployments or run a stripped-down version for testing.

**`priority`** - Loading order. System (100) loads first, then Profiles (90), then Files (85), and so on. This matters when modules depend on each other.

**`provides`** - An array of capabilities this module offers. Other modules can check if `real-time-messaging` is available before trying to use chat features.

**`requires`** - Dependencies. Marketplace requires both System and Profiles because jobs need users. The system validates this graph at boot time.

**`config`** - Module-specific settings that can be overridden per environment.

## Anatomy of a Module

Let's look inside the Marketplace module - it handles jobs, proposals, and contracts. Notice how it mirrors Laravel's `app/` structure but scoped to marketplace concerns:

```
modules/Marketplace/
├── Actions/
│   ├── Contracts/
│   │   ├── ApproveDelivery.php
│   │   ├── CancelContract.php
│   │   ├── CompleteContract.php
│   │   └── SubmitDelivery.php
│   ├── Jobs/
│   │   ├── CancelJob.php
│   │   ├── CreateJob.php
│   │   ├── PublishJob.php
│   │   └── UpdateJob.php
│   └── Proposals/
│       ├── AcceptProposal.php
│       ├── DeclineProposal.php
│       ├── SubmitProposal.php
│       └── WithdrawProposal.php
├── Events/
│   ├── ContractCompleted.php
│   ├── DeliverySubmitted.php
│   ├── JobCreated.php
│   ├── ProposalAccepted.php
│   └── ProposalSubmitted.php
├── Models/
│   ├── Job.php
│   ├── JobContract.php
│   ├── JobProposal.php
│   └── JobDelivery.php
├── Policies/
│   ├── JobPolicy.php
│   ├── JobContractPolicy.php
│   └── JobProposalPolicy.php
├── Livewire/
│   ├── JobSearch.php
│   ├── ProposalForm.php
│   └── ContractView.php
├── Providers/
│   └── MarketplaceServiceProvider.php
└── resources/views/
    ├── jobs/
    ├── proposals/
    └── contracts/
```

Everything marketplace-related lives together. A new developer can understand the jobs feature by reading one directory. When the marketplace team makes changes, they're working in isolation from the chat or projects code.

The `MarketplaceServiceProvider` bootstraps the module into Laravel:

```php
<?php

declare(strict_types=1);

namespace Modules\Marketplace\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Marketplace\Livewire\ContractView;
use Modules\Marketplace\Livewire\JobSearch;
use Modules\Marketplace\Livewire\ProposalForm;
use Modules\Marketplace\Models\Job;
use Modules\Marketplace\Models\JobContract;
use Modules\Marketplace\Models\JobProposal;
use Modules\Marketplace\Policies\JobContractPolicy;
use Modules\Marketplace\Policies\JobPolicy;
use Modules\Marketplace\Policies\JobProposalPolicy;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Load views with 'marketplace' namespace
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'marketplace');

        // Register policies
        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(JobProposal::class, JobProposalPolicy::class);
        Gate::policy(JobContract::class, JobContractPolicy::class);

        // Register Livewire components with 'marketplace::' prefix
        Livewire::component('marketplace::job-search', JobSearch::class);
        Livewire::component('marketplace::proposal-form', ProposalForm::class);
        Livewire::component('marketplace::contract-view', ContractView::class);
    }
}
```

The service provider registers views with a namespace (`marketplace`), policies for authorization, and Livewire components with a module prefix. In your Blade templates, you reference them as `@include('marketplace::jobs.show')` or `<livewire:marketplace::job-search />`. This namespacing prevents collisions and makes it clear which module owns each component.

## The System Module - Core Infrastructure

System is the foundation. It has priority 100, requires nothing, and every other module depends on it. Think of it as the operating system for your modules.

### The ModuleRegistry Service

When CreativeHub boots, the `ModuleRegistry` ensures System loads first, then Profiles (because Marketplace needs users), then Marketplace. If you try to enable Marketplace without Profiles, it fails fast with a clear error.

```php
<?php

declare(strict_types=1);

namespace Modules\System\Services;

use Modules\System\Contracts\ModuleInterface;

class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    protected array $modules = [];

    /** @var array<string, int> */
    protected array $priorities = [];

    protected bool $booted = false;

    /**
     * Register a module with the registry.
     */
    public function register(ModuleInterface $module, int $priority = 50): void
    {
        $name = $module->getName();
        $this->modules[$name] = $module;
        $this->priorities[$name] = $priority;
    }

    /**
     * Check if a module is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * Find modules that provide a specific capability.
     *
     * @return array<ModuleInterface>
     */
    public function provides(string $capability): array
    {
        return array_values(array_filter(
            $this->modules,
            fn (ModuleInterface $m) => in_array($capability, $m->getProvides(), true)
        ));
    }

    /**
     * Check if any module provides a capability.
     */
    public function hasCapability(string $capability): bool
    {
        return count($this->provides($capability)) > 0;
    }

    /**
     * Get modules sorted by priority (highest first).
     *
     * @return array<ModuleInterface>
     */
    public function getSortedByPriority(): array
    {
        $sorted = $this->modules;

        uasort($sorted, function (ModuleInterface $a, ModuleInterface $b) {
            return ($this->priorities[$b->getName()] ?? 50)
                <=> ($this->priorities[$a->getName()] ?? 50);
        });

        return array_values($sorted);
    }

    /**
     * Boot all registered modules in priority order.
     */
    public function bootAll(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->getSortedByPriority() as $module) {
            if ($module->isEnabled()) {
                $module->boot();
            }
        }

        $this->booted = true;
    }

    /**
     * Check if all dependencies for a module can be resolved.
     */
    public function canResolve(string $name): bool
    {
        $module = $this->get($name);

        if (! $module) {
            return false;
        }

        foreach ($module->getRequires() as $required) {
            if (! $this->has($required)) {
                return false;
            }

            // Recursively check required module's dependencies
            if (! $this->canResolve($required)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get missing dependencies for a module.
     *
     * @return array<string>
     */
    public function getMissingDependencies(string $name): array
    {
        $module = $this->get($name);

        if (! $module) {
            return [];
        }

        $missing = [];

        foreach ($module->getRequires() as $required) {
            if (! $this->has($required)) {
                $missing[] = $required;
            }
        }

        return $missing;
    }
}
```

The key methods are:

**`getSortedByPriority()`** - Returns modules in boot order. System (100) first, then Profiles (90), then Files (85), and so on down to Chat (40).

**`canResolve()`** - Validates the dependency graph. If Marketplace requires Profiles but Profiles isn't enabled, this returns false. You can use this at boot time to fail fast with a clear error message.

**`hasCapability()`** - Checks if any enabled module provides a capability. This is how you build optional integrations:

```php
// In a Blade view - only show chat button if Chat module is enabled
@if(app(ModuleRegistry::class)->hasCapability('real-time-messaging'))
    <x-chat-button :conversation="$contract->conversation" />
@endif
```

The registry doesn't force modules to implement a specific interface rigidly - it provides the infrastructure for modules to declare what they offer and what they need. The actual integration happens through the EventBus.

### The ModuleInterface Contract

Each module implements a simple contract that the registry uses for discovery and management:

```php
<?php

declare(strict_types=1);

namespace Modules\System\Contracts;

interface ModuleInterface
{
    /**
     * Get the unique name of this module.
     *
     * @return string The module name (e.g., 'Profiles', 'Marketplace')
     */
    public function getName(): string;

    /**
     * Get the version of this module.
     *
     * @return string The semantic version (e.g., '1.0.0')
     */
    public function getVersion(): string;

    /**
     * Get the list of capabilities this module provides.
     *
     * Other modules can check for these capabilities to enable integrations.
     *
     * @return array<string> List of capability identifiers
     */
    public function getProvides(): array;

    /**
     * Get the list of modules this module depends on.
     *
     * The application should ensure these modules are loaded before this one.
     *
     * @return array<string> List of required module names
     */
    public function getRequires(): array;

    /**
     * Boot the module.
     *
     * Called after all modules have been registered and dependencies resolved.
     * Use this for initialization logic that depends on other modules.
     */
    public function boot(): void;

    /**
     * Check if this module is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Get the module's configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;
}
```

The interface is deliberately minimal. It captures the essential information the registry needs:

- **Identity** (`getName`, `getVersion`) - For tracking and debugging
- **Capabilities** (`getProvides`) - What this module offers to others
- **Dependencies** (`getRequires`) - What this module needs from others
- **Lifecycle** (`boot`, `isEnabled`) - How and when to initialize
- **Configuration** (`getConfig`) - Module-specific settings

A typical implementation reads from the config file and provides sensible defaults:

```php
<?php

namespace Modules\Marketplace;

use Modules\System\Contracts\ModuleInterface;

class MarketplaceModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Marketplace';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getProvides(): array
    {
        return config('modules.modules.Marketplace.provides', []);
    }

    public function getRequires(): array
    {
        return config('modules.modules.Marketplace.requires', []);
    }

    public function isEnabled(): bool
    {
        return config('modules.modules.Marketplace.enabled', true);
    }

    public function getConfig(): array
    {
        return config('modules.modules.Marketplace.config', []);
    }

    public function boot(): void
    {
        // Register event subscriptions, schedule tasks, etc.
    }
}
```

This separation lets you define module metadata in config while keeping initialization logic in code.

### The EventBus - Cross-Module Communication

Here's where it gets interesting. Consider what happens when a client accepts a proposal on CreativeHub:

1. Marketplace creates a Contract
2. Projects should create a linked Project with Tasks
3. Chat should create a conversation between the parties
4. Profiles should update the freelancer's "active contracts" count
5. Files should prepare a shared folder for deliverables

This single action touches 5 modules. If Marketplace had to import and call Projects, Chat, Profiles, and Files directly, you'd have a tangled web of dependencies. Instead, we use an event bus:

```php
<?php

declare(strict_types=1);

namespace Modules\System\Services;

use Illuminate\Support\Facades\Event;

class EventBus
{
    /** @var array<string, array<string, array<callable>>> */
    protected static array $subscriptions = [];

    /**
     * Subscribe to an event.
     *
     * @param string $event The event name (e.g., 'marketplace.proposal.accepted')
     * @param callable|array $handler The handler (callable or [class, method])
     * @param string|null $module The subscribing module name (for unsubscription)
     */
    public static function subscribe(
        string $event,
        callable|array $handler,
        ?string $module = null
    ): void {
        $module = $module ?? 'app';

        if (! isset(static::$subscriptions[$event])) {
            static::$subscriptions[$event] = [];
        }

        if (! isset(static::$subscriptions[$event][$module])) {
            static::$subscriptions[$event][$module] = [];
        }

        static::$subscriptions[$event][$module][] = $handler;

        // Also register with Laravel's event system
        Event::listen($event, $handler);
    }

    /**
     * Dispatch an event to all subscribers.
     *
     * @param string $event The event name
     * @param array<string, mixed> $payload The event payload
     * @return array<mixed> Results from handlers
     */
    public static function dispatch(string $event, array $payload = []): array
    {
        return Event::dispatch($event, $payload);
    }

    /**
     * Dispatch an event asynchronously via queue.
     */
    public static function dispatchAsync(string $event, array $payload = []): void
    {
        dispatch(function () use ($event, $payload) {
            static::dispatch($event, $payload);
        })->afterResponse();
    }

    /**
     * Unsubscribe all handlers for a specific module.
     *
     * Useful when disabling or reloading a module.
     */
    public static function unsubscribeModule(string $module): void
    {
        foreach (static::$subscriptions as $event => $modules) {
            if (isset($modules[$module])) {
                foreach ($modules[$module] as $handler) {
                    Event::forget($event);
                }
                unset(static::$subscriptions[$event][$module]);
            }
        }
    }

    /**
     * Check if an event has any subscribers.
     */
    public static function hasSubscribers(string $event): bool
    {
        return Event::hasListeners($event);
    }
}
```

The EventBus wraps Laravel's native event system with two important additions:

1. **Module tracking** - Each subscription is tagged with its module name. When you disable the Chat module, you can call `EventBus::unsubscribeModule('Chat')` to remove all its listeners without affecting other modules.

2. **Async dispatch** - `dispatchAsync()` queues the event to run after the response is sent. This keeps the initial request fast while still triggering cross-module reactions.

Here's how the proposal acceptance flow works with the EventBus:

```php
// In Marketplace/Actions/Proposals/AcceptProposal.php
class AcceptProposal extends Action
{
    public function handle(JobProposal $proposal, User $user): JobContract
    {
        $this->authorize('accept', $proposal);

        return DB::transaction(function () use ($proposal, $user) {
            $proposal->accept();

            // Decline all other pending proposals
            $proposal->job->pendingProposals()
                ->where('id', '!=', $proposal->id)
                ->each(fn ($p) => $p->decline('Another proposal was accepted'));

            // Create the contract
            $contract = JobContract::create([
                'marketplace_job_id' => $proposal->marketplace_job_id,
                'proposal_id' => $proposal->id,
                'freelancer_id' => $proposal->freelancer_id,
                'client_id' => $user->id,
                'agreed_budget' => $proposal->proposed_budget,
                'status' => 'active',
                'started_at' => now(),
            ]);

            // One event, multiple modules react
            EventBus::dispatch('marketplace.proposal.accepted', [
                'proposal' => $proposal,
                'contract' => $contract,
                'client' => $user,
            ]);

            return $contract;
        });
    }
}
```

Marketplace doesn't know or care what happens next. It just announces "a proposal was accepted" with the relevant data. Across the codebase, other modules subscribe:

```php
// In Projects/Providers/ProjectsServiceProvider.php
EventBus::subscribe(
    'marketplace.proposal.accepted',
    [CreateProjectFromContract::class, 'handle'],
    'Projects'
);

// In Chat/Providers/ChatServiceProvider.php
EventBus::subscribe(
    'marketplace.proposal.accepted',
    [CreateContractConversation::class, 'handle'],
    'Chat'
);

// In Files/Providers/FilesServiceProvider.php
EventBus::subscribe(
    'marketplace.proposal.accepted',
    [PrepareDeliveryFolder::class, 'handle'],
    'Files'
);

// In Profiles/Providers/ProfilesServiceProvider.php
EventBus::subscribe(
    'marketplace.proposal.accepted',
    [UpdateFreelancerStats::class, 'handle'],
    'Profiles'
);
```

The beauty of this pattern:

- **Marketplace doesn't import Chat, Projects, or Files.** It has no knowledge of what modules exist.
- **Adding a new reaction is just adding a listener.** Want to send a Slack notification when proposals are accepted? Add a subscriber in your Notifications module. Zero changes to Marketplace.
- **Modules can be disabled cleanly.** If Chat is disabled, its listener isn't registered. The event still fires, but nothing happens - no errors, no null checks.

Here's the visual flow:

```
┌─────────────────────────────────────────────────────────────┐
│  Client clicks "Accept Proposal"                            │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  Marketplace Module                                         │
│  AcceptProposal Action                                      │
│  → Creates Contract                                         │
│  → EventBus::dispatch('marketplace.proposal.accepted')      │
└─────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  Projects       │ │  Chat           │ │  Files          │
│  Creates linked │ │  Creates convo  │ │  Prepares       │
│  Project+Tasks  │ │  between parties│ │  delivery folder│
└─────────────────┘ └─────────────────┘ └─────────────────┘
          │                   │                   │
          └───────────────────┼───────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  Profiles Module                                            │
│  Updates freelancer's active_contracts_count                │
└─────────────────────────────────────────────────────────────┘
```

### What the Listeners Look Like

Let's see what those cross-module listeners actually do. Here's how the Chat module creates a conversation when a proposal is accepted:

```php
<?php

declare(strict_types=1);

namespace Modules\Chat\Listeners;

use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationParticipant;

class CreateContractConversation
{
    public function handle(array $payload): void
    {
        $contract = $payload['contract'];
        $proposal = $payload['proposal'];

        // Create a conversation for this contract
        $conversation = Conversation::create([
            'type' => 'contract',
            'subject' => "Contract: {$proposal->job->title}",
            'contract_id' => $contract->id,
        ]);

        // Add both parties as participants
        ConversationParticipant::insert([
            [
                'conversation_id' => $conversation->id,
                'user_id' => $contract->client_id,
                'role' => 'client',
                'joined_at' => now(),
            ],
            [
                'conversation_id' => $conversation->id,
                'user_id' => $contract->freelancer_id,
                'role' => 'freelancer',
                'joined_at' => now(),
            ],
        ]);

        // Send an initial system message
        $conversation->messages()->create([
            'type' => 'system',
            'body' => 'Contract started. You can now message each other.',
        ]);
    }
}
```

The listener receives the event payload, extracts what it needs, and does its work. It has no idea how it was invoked - it just knows it received a contract and proposal.

Here's the Projects module creating a project from the contract:

```php
<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\Section;

class CreateProjectFromContract
{
    public function handle(array $payload): void
    {
        $contract = $payload['contract'];
        $proposal = $payload['proposal'];
        $job = $proposal->job;

        // Create a project linked to this contract
        $project = Project::create([
            'team_id' => $job->team_id,
            'name' => $job->title,
            'description' => $job->description,
            'contract_id' => $contract->id,
            'deadline' => $contract->agreed_deadline,
            'status' => 'active',
        ]);

        // Create default sections based on the job requirements
        Section::insert([
            [
                'project_id' => $project->id,
                'name' => 'Requirements',
                'position' => 0,
            ],
            [
                'project_id' => $project->id,
                'name' => 'In Progress',
                'position' => 1,
            ],
            [
                'project_id' => $project->id,
                'name' => 'Review',
                'position' => 2,
            ],
            [
                'project_id' => $project->id,
                'name' => 'Completed',
                'position' => 3,
            ],
        ]);

        // If the job has specific requirements, create tasks for them
        if ($job->requirements) {
            foreach ($job->requirements as $index => $requirement) {
                $project->tasks()->create([
                    'section_id' => $project->sections->first()->id,
                    'title' => $requirement,
                    'position' => $index,
                    'status' => 'pending',
                ]);
            }
        }
    }
}
```

And the Profiles module updating stats:

```php
<?php

declare(strict_types=1);

namespace Modules\Profiles\Listeners;

class UpdateFreelancerStats
{
    public function handle(array $payload): void
    {
        $contract = $payload['contract'];

        // Increment the freelancer's active contracts count
        $contract->freelancer->increment('active_contracts_count');

        // Update their profile's "last hired" timestamp
        $contract->freelancer->profile->update([
            'last_hired_at' => now(),
        ]);
    }
}
```

Each listener is focused, testable, and completely isolated from the others. You can unit test `CreateContractConversation` without knowing that `UpdateFreelancerStats` exists.

### Event Naming Conventions

We follow a simple convention for event names: `module.entity.action`. Examples:

- `marketplace.proposal.accepted`
- `marketplace.contract.completed`
- `projects.task.assigned`
- `chat.message.sent`
- `profiles.user.verified`

This makes it easy to:

1. **Grep for all events from a module**: `grep -r "marketplace\." modules/`
2. **Find all listeners for an event**: Search for the event name in service providers
3. **Understand intent at a glance**: The event name tells you what happened

### Shared Traits and Base Classes

Every Job, Proposal, and Contract in CreativeHub needs activity tracking, comments, and file attachments. Instead of duplicating code, they use System traits:

```php
// In Marketplace/Models/Job.php
class Job extends Model
{
    use Attachable;       // File attachments from Files module
    use Commentable;      // Clients and freelancers can discuss
    use HasActivity;      // Log when job is created, edited, closed
    use HasTeam;          // Jobs belong to client teams
    use HasCustomFields;  // Dynamic fields per team
    use Filterable;       // Query builder filters
}
```

Here's what the `HasActivity` trait provides:

```php
<?php

declare(strict_types=1);

namespace Modules\System\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\System\Models\RecordActivity;

trait HasActivity
{
    public static function bootHasActivity(): void
    {
        if (static::shouldAutoLogActivity()) {
            static::created(function (Model $model) {
                $model->logCreated();
            });

            static::updated(function (Model $model) {
                $changes = $model->getActivityChanges();
                if (! empty($changes)) {
                    if (isset($changes['status'])) {
                        $model->logStatusChanged(
                            $changes['status']['old'] ?? '',
                            $changes['status']['new'] ?? ''
                        );
                    } else {
                        $model->logUpdated($changes);
                    }
                }
            });

            static::deleted(function (Model $model) {
                $model->logActivity(
                    type: RecordActivity::TYPE_DELETED,
                    description: class_basename($model).' was deleted'
                );
            });
        }
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(RecordActivity::class, 'recordable')
            ->orderByDesc('created_at');
    }

    public function logActivity(
        string $type,
        ?string $description = null,
        ?array $properties = null,
        ?User $performer = null
    ): RecordActivity {
        $performer = $performer ?? auth()->user();

        return RecordActivity::create([
            'recordable_type' => get_class($this),
            'recordable_id' => $this->getKey(),
            'team_id' => $this->resolveActivityTeamId(),
            'type' => $type,
            'description' => $description,
            'properties' => $properties,
            'performed_by' => $performer?->id,
        ]);
    }

    public function logStatusChanged(string $old, string $new, ?User $performer = null): RecordActivity
    {
        return $this->logActivity(
            type: $this->resolveStatusChangeType($old, $new),
            description: class_basename($this).' status changed from '.$old.' to '.$new,
            properties: ['old_status' => $old, 'new_status' => $new],
            performer: $performer
        );
    }
}
```

Models can customize the behavior by overriding methods. The Job model, for example, customizes how status changes are logged:

```php
// In Marketplace/Models/Job.php
protected static bool $autoLogActivity = true;

protected function resolveStatusChangeType(string $old, string $new): string
{
    return match ($new) {
        'live' => RecordActivity::TYPE_PUBLISHED,
        'draft' => $old === 'live'
            ? RecordActivity::TYPE_UNPUBLISHED
            : RecordActivity::TYPE_STATUS_CHANGED,
        'completed' => RecordActivity::TYPE_COMPLETED,
        'cancelled' => RecordActivity::TYPE_CANCELLED,
        default => RecordActivity::TYPE_STATUS_CHANGED,
    };
}
```

The `Commentable` trait adds threaded comments to any model:

```php
<?php

declare(strict_types=1);

namespace Modules\System\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\System\Models\Comment;

trait Commentable
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function rootComments(): MorphMany
    {
        return $this->comments()->whereNull('parent_id');
    }

    public function addComment(User $user, string $body, ?Comment $parent = null): Comment
    {
        return $this->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
            'parent_id' => $parent?->id,
        ]);
    }
}
```

These traits are the "building blocks" that modules compose. New models get activity logging, comments, and attachments "for free" by adding three lines of `use` statements. The behavior is consistent across the entire application, and when you need to change how comments work, you change one trait.

## Building a Feature Module

Let's trace through a complete flow: a freelancer submits a proposal on CreativeHub. This shows how a feature module uses everything System provides.

The `AcceptProposal` action extends the base `Action` class from System:

```php
<?php

declare(strict_types=1);

namespace Modules\Marketplace\Actions\Proposals;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Models\JobContract;
use Modules\Marketplace\Models\JobProposal;
use Modules\System\Support\Actions\Action;

class AcceptProposal extends Action
{
    public function handle(JobProposal $proposal, User $user): JobContract
    {
        // From Action base class - policy check
        $this->authorize('accept', $proposal);

        // From Action base class - database transaction wrapper
        return DB::transaction(function () use ($proposal, $user) {
            // Update the proposal status
            $proposal->accept();

            // Decline competing proposals
            $proposal->job->pendingProposals()
                ->where('id', '!=', $proposal->id)
                ->each(fn ($p) => $p->decline('Another proposal was accepted'));

            // Create the contract
            $contract = JobContract::create([
                'marketplace_job_id' => $proposal->marketplace_job_id,
                'proposal_id' => $proposal->id,
                'freelancer_id' => $proposal->freelancer_id,
                'client_id' => $user->id,
                'agreed_budget' => $proposal->proposed_budget,
                'agreed_deadline' => now()->addDays($proposal->proposed_days ?? 30),
                'status' => 'active',
                'started_at' => now(),
            ]);

            // Update the job status
            $proposal->job->update([
                'status' => 'live',
                'contracted_at' => now(),
            ]);

            // Cross-module communication - we don't care who listens
            EventBus::dispatch('marketplace.proposal.accepted', [
                'proposal' => $proposal,
                'contract' => $contract,
                'client' => $user,
            ]);

            return $contract;
        });
    }
}
```

Notice what's happening:

1. **Authorization via `$this->authorize()`** - The base Action class provides this helper that checks the `JobProposalPolicy`. The policy lives in the Marketplace module.

2. **Transaction wrapping** - All the database operations are wrapped in a transaction. If creating the contract fails, the proposal status change is rolled back.

3. **Model methods** - `$proposal->accept()` and `$p->decline()` encapsulate the status change logic. The model knows its valid transitions.

4. **Event dispatch** - At the end, one event fires. The Marketplace module's job is done.

Meanwhile, the `Job` model uses System's traits for cross-cutting concerns:

```php
<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Files\Models\Concerns\Attachable;
use Modules\System\Traits\Commentable;
use Modules\System\Traits\HasActivity;

class Job extends Model
{
    use Attachable;      // From Files module
    use Commentable;     // From System module
    use HasActivity;     // From System module
    use HasTeam;         // From System module
    use HasCustomFields; // From System module

    protected static bool $autoLogActivity = true;

    protected $fillable = [
        'team_id',
        'title',
        'description',
        'budget',
        'status',
        // ...
    ];

    // Relationships to other Marketplace models
    public function proposals(): HasMany
    {
        return $this->hasMany(JobProposal::class, 'marketplace_job_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(JobContract::class, 'marketplace_job_id');
    }

    // Business logic stays on the model
    public function canReceiveProposalFrom(User $user): bool
    {
        return $this->isLive()
            && ! $this->isContracted()
            && ! $this->hasProposalFrom($user)
            && $this->created_by !== $user->id;
    }
}
```

The model is focused on Marketplace concerns - relationships between jobs, proposals, and contracts; business rules about who can submit proposals. The cross-cutting concerns (activity logging, comments, attachments) come from traits.

## When This Approach Makes Sense

CreativeHub needed modules because we have 5 developers working on different features. The marketplace team doesn't need to understand the chat implementation. The projects team can work independently. But this architecture has costs.

**Use modules when:**

- You have 3+ developers working on different features simultaneously
- Features could theoretically be separate apps (Marketplace could be its own SaaS)
- You need feature flags ("enable Chat module for premium users only")
- Your codebase has 30+ models across distinct domains
- Teams are stepping on each other's toes in the same directories

**Don't use modules when:**

- You're building a simple CRUD app - a todo list doesn't need modules
- You're a solo developer prototyping - the overhead isn't worth it
- You have a tight deadline and need to ship fast - start monolithic, extract later
- Everything is tightly coupled anyway - if every model touches every other model, modules won't help

**The complexity cost is real:**

- More directories to navigate
- More service providers to maintain
- Mental overhead of "which module owns this?"
- Potential for circular dependencies if you're not careful
- EventBus debugging can be tricky ("which module handled this event?")

**Compared to packages like `nwidart/laravel-modules`:**

Packages give you conventions and tooling out of the box - generators, migrations per module, artisan commands. A custom approach gives you control - you define exactly how modules communicate, what the configuration looks like, and how the boot sequence works.

If you want batteries-included, use a package. If you have specific architectural requirements (like our EventBus pattern for cross-module communication), build your own.

**Common mistakes to avoid:**

1. **Too many modules too early.** Don't start with 10 modules. Start with `app/` and extract when pain appears. Your first extraction should be the most independent feature.

2. **Circular dependencies.** If Marketplace requires Projects and Projects requires Marketplace, you have a design problem. One of them should be a dependency of the other, or they should communicate only through events.

3. **Sharing too much through traits.** Traits are great for cross-cutting concerns (logging, comments, attachments). They're not great for business logic. If you find yourself putting complex business rules in a trait, it probably belongs in a service or action.

4. **Over-engineering the EventBus.** The version shown here is around 150 lines. That's enough. You don't need middleware, priorities, or replay functionality until you actually need them.

5. **Ignoring the database.** Modules can share a database, but be thoughtful about foreign keys across module boundaries. Consider using soft references (storing IDs without constraints) for cross-module relationships, and validating at the application level.

**This is a stepping stone, not a destination:**

If CreativeHub grows enough that the Marketplace needs its own deployment, the modular structure makes extraction easier. The module already has clear boundaries, its own namespace, and communicates via events. Moving it to a separate service means:

1. Extract the `modules/Marketplace` directory
2. Replace EventBus dispatches with HTTP calls or message queues
3. Replace EventBus subscriptions with webhook handlers

The modular monolith is an intermediate architecture between "one big app" and "many small services."

## Testing Modular Code

Testing modules requires some thought about boundaries. Unit tests live within the module. Integration tests verify cross-module communication.

### Unit Testing Actions

Actions are easy to test because they have clear inputs and outputs:

```php
<?php

namespace Tests\Modules\Marketplace\Actions;

use Modules\Marketplace\Actions\Proposals\AcceptProposal;
use Modules\Marketplace\Models\Job;
use Modules\Marketplace\Models\JobContract;
use Modules\Marketplace\Models\JobProposal;
use Tests\TestCase;

class AcceptProposalTest extends TestCase
{
    public function test_accepting_proposal_creates_contract(): void
    {
        $job = Job::factory()->live()->create();
        $proposal = JobProposal::factory()
            ->for($job, 'job')
            ->pending()
            ->create();

        $action = new AcceptProposal();
        $contract = $action->handle($proposal, $job->creator);

        $this->assertInstanceOf(JobContract::class, $contract);
        $this->assertEquals('active', $contract->status);
        $this->assertEquals($proposal->id, $contract->proposal_id);
    }

    public function test_accepting_proposal_declines_other_proposals(): void
    {
        $job = Job::factory()->live()->create();
        $accepted = JobProposal::factory()->for($job, 'job')->pending()->create();
        $declined = JobProposal::factory()->for($job, 'job')->pending()->create();

        $action = new AcceptProposal();
        $action->handle($accepted, $job->creator);

        $accepted->refresh();
        $declined->refresh();

        $this->assertEquals('accepted', $accepted->status);
        $this->assertEquals('declined', $declined->status);
    }
}
```

### Testing EventBus Subscriptions

To verify that events trigger the right handlers, test at the integration level:

```php
<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Event;
use Modules\Chat\Listeners\CreateContractConversation;
use Modules\Chat\Models\Conversation;
use Modules\Marketplace\Actions\Proposals\AcceptProposal;
use Modules\Marketplace\Models\JobProposal;
use Tests\TestCase;

class ProposalAcceptedIntegrationTest extends TestCase
{
    public function test_accepting_proposal_creates_chat_conversation(): void
    {
        $proposal = JobProposal::factory()->pending()->create();

        $action = new AcceptProposal();
        $contract = $action->handle($proposal, $proposal->job->creator);

        // Verify the conversation was created
        $conversation = Conversation::where('contract_id', $contract->id)->first();

        $this->assertNotNull($conversation);
        $this->assertEquals(2, $conversation->participants->count());
    }

    public function test_chat_listener_can_be_disabled(): void
    {
        // Unsubscribe the Chat module
        EventBus::unsubscribeModule('Chat');

        $proposal = JobProposal::factory()->pending()->create();

        $action = new AcceptProposal();
        $contract = $action->handle($proposal, $proposal->job->creator);

        // No conversation should exist
        $this->assertNull(
            Conversation::where('contract_id', $contract->id)->first()
        );
    }
}
```

### Debugging EventBus Issues

When events aren't being handled as expected, the EventBus provides introspection:

```php
// In a tinker session or debug controller
$subscriptions = EventBus::getSubscriptions();
dd($subscriptions['marketplace.proposal.accepted']);

// Output shows which modules are listening:
// [
//     'Projects' => [[CreateProjectFromContract::class, 'handle']],
//     'Chat' => [[CreateContractConversation::class, 'handle']],
//     'Files' => [[PrepareDeliveryFolder::class, 'handle']],
//     'Profiles' => [[UpdateFreelancerStats::class, 'handle']],
// ]
```

You can also check if an event has any subscribers before dispatching:

```php
if (EventBus::hasSubscribers('marketplace.proposal.accepted')) {
    EventBus::dispatch('marketplace.proposal.accepted', $payload);
} else {
    Log::warning('No subscribers for marketplace.proposal.accepted');
}
```

For production debugging, consider adding logging to your EventBus:

```php
public static function dispatch(string $event, array $payload = []): array
{
    if (config('modules.debug_events')) {
        Log::debug("EventBus: Dispatching {$event}", [
            'subscribers' => array_keys(static::$subscriptions[$event] ?? []),
        ]);
    }

    return Event::dispatch($event, $payload);
}
```

## Conclusion

The architecture is straightforward:

1. **Modules are directories** with their own Models, Actions, Events, and views
2. **Priority-based loading** ensures dependencies boot in the right order
3. **ModuleRegistry** tracks capabilities and validates the dependency graph
4. **EventBus** enables cross-module communication without imports
5. **Shared traits** provide consistent cross-cutting concerns

Start simple. Keep your `app/` directory until the pain appears - when developers are stepping on each other, when you can't find files, when changes in one feature break another. Then extract the clearest boundary first (usually the most independent feature). Add modules incrementally.

The goal is boundaries, not perfection. A module that talks to other modules via events is better than 60 controllers in one directory. You can always refine the boundaries later.
