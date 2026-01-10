---
title: "Role-Based Permissions in Multi-Tenant Laravel with Jetstream Teams"
date: 2025-01-09
excerpt: "Jetstream's team feature is often used for organizations. Here's how to use it for a coworking space where staff manage spaces and members book rooms."
tags: [laravel, jetstream, permissions, multi-tenant, teams]
slug: role-based-permissions-jetstream-teams
---

Laravel Jetstream ships with a teams feature that's typically used for multi-tenant SaaS applications. Each team is an organization, and users belong to one or more teams with different roles.

But the default setup assumes everyone on a team is roughly equivalent—maybe some are admins and some are editors, but they're all "internal" users. What happens when you need to mix fundamentally different user types on the same team?

Consider a coworking space management platform where each location is a "team," but that team includes both staff (who manage the space) and members (who book rooms and desks). Same team, very different permission needs.

This post walks through adapting Jetstream's permission system for this hybrid model—using a **single, consistent permission system** rather than mixing approaches.

## The Challenge

In a typical Jetstream setup, you define roles with permissions:

```php
Jetstream::role('admin', 'Administrator', ['*'])->description('Full access');
Jetstream::role('editor', 'Editor', ['read', 'create', 'update'])->description('Can edit content');
```

These roles exist within the context of a team. A user might be an admin on Team A and an editor on Team B.

But what if you have two fundamentally different user populations?

1. **Space managers** — Staff who manage bookings, handle check-ins, and configure rooms
2. **Members** — Coworking members who book rooms, view their reservations, and update their profiles

Staff need granular permissions for all the management functions. Members need a simpler set focused on their own bookings. And they're both members of the same team (the coworking location).

## The Single Source of Truth Principle

A common mistake is creating **two parallel permission systems**—boolean flags on the User model (`isStaff`, `isAdmin`) plus Jetstream roles. This leads to confusion:

```php
// ❌ DON'T DO THIS - Two competing systems
protected $fillable = ['isStaff', 'isAdmin', 'isFinance'];  // Flags

// Plus Jetstream roles
Jetstream::role('manager', 'Manager', ['bookings:*']);

// Now you have to check both everywhere
if ($user->isStaff && $user->hasPermission('bookings:create')) { ... }
```

Instead, **use Jetstream roles as your single source of truth**. If you need additional capabilities, add them as permissions within the role system.

## Defining Roles for Different User Types

Define roles that map to your user populations. In your `JetstreamServiceProvider`:

```php
protected function configurePermissions(): void
{
    Jetstream::defaultApiTokenPermissions(['read']);

    // Member role (limited access to own resources)
    Jetstream::role('member', 'Member', [
        'profile:read',
        'profile:update',
        'bookings:create',
        'bookings:read-own',
        'bookings:cancel-own',
        'rooms:read',
        'events:read',
    ])->description('Coworking member. Can book rooms and manage own reservations.');

    // Space manager role (full location management)
    Jetstream::role('space_manager', 'Space Manager', [
        // Profile management
        'profile:read',
        'profile:update',

        // Room management
        'rooms:create',
        'rooms:read',
        'rooms:update',
        'rooms:delete',

        // Booking management (all bookings, not just own)
        'bookings:create',
        'bookings:read',
        'bookings:update',
        'bookings:delete',
        'bookings:check-in',
        'bookings:check-out',

        // Member management
        'members:read',
        'members:update',
        'members:suspend',

        // Events
        'events:create',
        'events:read',
        'events:update',
        'events:delete',

        // Reports
        'reports:view',
        'reports:export',

        // Admin panel access
        'admin:access',
    ])->description('Staff member who manages the coworking space.');

    // Location admin (can also manage staff)
    Jetstream::role('location_admin', 'Location Admin', [
        '*', // Full access to everything
    ])->description('Full administrative access to the location.');
}
```

Notice the permission naming convention: `resource:action`. This makes permissions scannable and predictable. The `-own` suffix indicates actions limited to the user's own resources.

## Checking Permissions

Jetstream provides `hasTeamPermission()` to check if a user has a permission on a specific team. Create a convenient wrapper:

```php
// In your User model
public function hasPermission(string $ability): bool
{
    $team = $this->currentTeam;
    
    if (!$team) {
        return false;
    }
    
    return $this->hasTeamPermission($team, $ability);
}
```

Now controllers can check permissions cleanly:

```php
public function store(Request $request)
{
    if (!auth()->user()->hasPermission('bookings:create')) {
        abort(403);
    }
    
    // Create booking...
}
```

## Handling "Own" vs "All" Permissions

Some permissions need context—members can read their own bookings, but managers can read all bookings. Handle this with a helper method:

```php
// In your User model
public function canAccessBooking(Booking $booking): bool
{
    // Can read all bookings
    if ($this->hasPermission('bookings:read')) {
        return true;
    }
    
    // Can only read own bookings
    if ($this->hasPermission('bookings:read-own') && $booking->user_id === $this->id) {
        return true;
    }
    
    return false;
}

public function canCancelBooking(Booking $booking): bool
{
    // Can cancel any booking
    if ($this->hasPermission('bookings:delete')) {
        return true;
    }
    
    // Can only cancel own bookings
    if ($this->hasPermission('bookings:cancel-own') && $booking->user_id === $this->id) {
        return true;
    }
    
    return false;
}
```

Or use a policy:

```php
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $user->hasPermission('bookings:read') 
            || ($user->hasPermission('bookings:read-own') && $booking->user_id === $user->id);
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->hasPermission('bookings:delete')
            || ($user->hasPermission('bookings:cancel-own') && $booking->user_id === $user->id);
    }
}
```

## Role-Based UI Variations

Sometimes you need different UI for different roles. Use role checks for this:

```php
// In your User model - use the relationship, not raw queries
public function teamRole(): ?string
{
    $membership = $this->currentTeam?->users()
        ->where('user_id', $this->id)
        ->first();
    
    return $membership?->pivot->role;
}

public function isSpaceManager(): bool
{
    return in_array($this->teamRole(), ['space_manager', 'location_admin']);
}
```

In Blade:

```blade
@if(auth()->user()->isSpaceManager())
    {{-- Staff dashboard with management tools --}}
    <x-manager-dashboard :location="$location" />
@else
    {{-- Member dashboard with booking interface --}}
    <x-member-dashboard :bookings="$bookings" />
@endif
```

## Conditional UI Based on Permissions

For granular UI control, check specific permissions:

```blade
@can('bookings:check-in')
    <button wire:click="checkIn({{ $booking->id }})">
        Check In
    </button>
@endcan

@can('reports:view')
    <a href="{{ route('reports.index') }}">
        View Reports
    </a>
@endcan
```

Register permissions with Laravel's Gate:

```php
// In AuthServiceProvider
public function boot(): void
{
    Gate::define('bookings:check-in', function (User $user) {
        return $user->hasPermission('bookings:check-in');
    });

    Gate::define('reports:view', function (User $user) {
        return $user->hasPermission('reports:view');
    });
}
```

Or register them dynamically:

```php
// In AuthServiceProvider
public function boot(): void
{
    Gate::before(function (User $user, string $ability) {
        // Check Jetstream team permissions for any ability
        if ($user->currentTeam && $user->hasTeamPermission($user->currentTeam, $ability)) {
            return true;
        }
        
        return null; // Fall through to other gates
    });
}
```

## Admin Panel Access

For Filament or other admin panels, check permissions in the panel provider:

```php
// In your Filament AdminPanelProvider
public function panel(Panel $panel): Panel
{
    return $panel
        ->authGuard('web')
        ->login()
        ->authMiddleware([
            Authenticate::class,
        ])
        ->middleware([
            // Custom middleware to check admin:access permission
            EnsureUserHasAdminAccess::class,
        ]);
}
```

The middleware:

```php
class EnsureUserHasAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->user()?->hasPermission('admin:access')) {
            abort(403, 'You do not have access to the admin panel.');
        }

        return $next($request);
    }
}
```

## Switching Between Locations

Staff members might work at multiple locations. Jetstream handles team switching natively:

```php
public function switchToLocation(int $teamId): RedirectResponse
{
    $team = Team::findOrFail($teamId);

    if (!auth()->user()->belongsToTeam($team)) {
        abort(403, 'You do not have access to this location.');
    }

    auth()->user()->switchTeam($team);
    
    return redirect()->route('dashboard');
}
```

In Livewire:

```php
public function switchLocation(int $teamId): void
{
    $team = Team::findOrFail($teamId);

    if (!auth()->user()->belongsToTeam($team)) {
        $this->dispatch('notify', message: 'Access denied', type: 'error');
        return;
    }

    auth()->user()->switchTeam($team);
    
    $this->redirect(route('dashboard'));
}
```

## Extending Permission Checks with Wildcards

Jetstream's default `hasTeamPermission()` checks for exact matches or the `*` wildcard. Extend it to support pattern matching:

```php
// In your User model, override the method
public function hasTeamPermission($team, string $permission): bool
{
    if (!$this->belongsToTeam($team)) {
        return false;
    }

    $permissions = $this->teamPermissions($team);

    // Exact match or full wildcard
    if (in_array($permission, $permissions) || in_array('*', $permissions)) {
        return true;
    }

    // Support resource:* wildcard (e.g., 'bookings:*' matches 'bookings:create')
    [$resource, $action] = explode(':', $permission) + [null, null];
    if ($resource && in_array("{$resource}:*", $permissions)) {
        return true;
    }

    return false;
}
```

Now you can define roles with pattern permissions:

```php
Jetstream::role('booking_manager', 'Booking Manager', [
    'bookings:*',  // All booking permissions
    'rooms:read',  // But only read rooms
])->description('Can manage all bookings but not rooms.');
```

## Summary

Jetstream's team and role system is flexible enough to handle mixed user populations. The key principles:

1. **Use a single permission system** — Don't mix boolean flags with roles
2. **Define clear roles** for each user type with appropriate permissions
3. **Use the `resource:action` naming convention** for scannable permissions
4. **Handle "own" vs "all"** with `-own` suffixed permissions and policies
5. **Check permissions via policies and gates** for consistent authorization
6. **Extend wildcards** if you need pattern matching

The result is a system where staff and members coexist on the same team, each seeing the interface and capabilities appropriate to their role—all managed through a single, consistent permission system.
