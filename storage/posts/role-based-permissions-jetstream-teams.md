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

This post walks through adapting Jetstream's permission system for this hybrid model—using a **two-layer approach**: boolean flags for user type and Jetstream roles for team-scoped permissions.

## The Challenge

Picture a coworking company with three locations: Downtown, Westside, and Midtown. Each location has its own staff—space managers who handle day-to-day operations—and members who pay for desk space and meeting rooms.

```
┌─────────────────────────────────────────────────────────────────────┐
│                        COWORKING COMPANY                            │
├─────────────────────┬─────────────────────┬─────────────────────────┤
│      DOWNTOWN       │      WESTSIDE       │        MIDTOWN          │
│    (Jetstream Team) │   (Jetstream Team)  │    (Jetstream Team)     │
├─────────────────────┼─────────────────────┼─────────────────────────┤
│ Staff:              │ Staff:              │ Staff:                  │
│  • Sarah (Manager)  │  • Mike (Manager)   │  • Lisa (Manager)       │
│  • Tom (Front Desk) │                     │  • James (Front Desk)   │
├─────────────────────┼─────────────────────┼─────────────────────────┤
│ Members:            │ Members:            │ Members:                │
│  • Alice            │  • Bob              │  • Carol                │
│  • Dan              │  • Eve              │  • Frank                │
│  • Grace            │                     │  • Henry                │
└─────────────────────┴─────────────────────┴─────────────────────────┘
```

Here's where it gets interesting. **Staff and members both need to access the same location data**—but with completely different capabilities:

**What staff need to do:**
- View and manage ALL bookings at their location
- Check members in and out
- Configure room availability and pricing
- Handle billing and generate reports
- Manage member accounts

**What members need to do:**
- Book rooms and desks for themselves
- View and cancel their OWN bookings
- Update their profile and billing info
- See what rooms are available

The permission problem becomes clear: **members should never see other members' bookings, but staff need to see everyone's**. Members can cancel their own reservations, but staff can cancel anyone's. Both populations interact with the same data (bookings, rooms, the location itself) but through very different lenses.

### Why Jetstream Alone Falls Short

In a typical Jetstream setup, you define roles with permissions:

```php
Jetstream::role('admin', 'Administrator', ['*'])->description('Full access');
Jetstream::role('editor', 'Editor', ['read', 'create', 'update'])->description('Can edit content');
```

These roles exist within the context of a team. A user might be an admin on Team A and an editor on Team B. But the implicit assumption is that all team members are fundamentally the same type of user—internal collaborators with varying levels of access.

Our coworking scenario breaks this assumption. Staff and members aren't just users with different permission levels—they're **fundamentally different user populations** who happen to share a team context:

- Staff might work at multiple locations (Sarah (staff) could be the manager at both Downtown and Westside)
- Members belong to one location and shouldn't even know other members exist
- Staff need access to admin panels, reports, and management tools that members should never see
- The UI, navigation, and entire experience differs by user type

You can't solve this with Jetstream roles alone because the distinction between "staff" and "member" is **global to the user**, not scoped to a team. Sarah (staff) is staff everywhere she goes. Alice (member) is a member everywhere she goes. The question isn't "what role does this user have on this team?"—it's "what kind of user is this, and what can they do here?"

## The Two-Layer Approach

When you have fundamentally different user populations (staff vs members), you need two layers of authorization:

1. **User Type (Global)** — Boolean flags on the User model to distinguish user populations
2. **Team Permissions (Scoped)** — Jetstream roles define what users can do within a specific team

The key insight: **User TYPE is global, but PERMISSIONS are team-scoped**.

```php
// User model - distinguishes user populations globally
protected $fillable = ['name', 'email', 'password', 'isStaff'];

// isStaff = true → Space managers (internal users who can work across locations)
// isStaff = false → Members (coworking clients who belong to one location)
```

The `isStaff` flag answers: "What kind of user is this?" (global question)  
Jetstream roles answer: "What can this user do in THIS team?" (team-scoped question)

This isn't two competing systems—they solve different problems:
- **Boolean flags**: User belongs to a fundamentally different population
- **Jetstream roles**: Permissions within a specific team/location context

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

## Setting Up the User Model

Your User model needs the `isStaff` flag to distinguish user populations, and a simple helper for checking permissions:

```php
// app/Models/User.php
protected $fillable = [
    'name',
    'email',
    'password',
    'isStaff', // Distinguishes staff from members
];

/**
 * Check if the user has a permission on their current team
 */
public function hasPermission(string $ability): bool
{
    if (!$this->currentTeam) {
        return false;
    }
    
    return $this->hasTeamPermission($this->currentTeam, $ability);
}
```

This keeps permission checking simple throughout your application—no need to pass the team every time.

## Checking Permissions

With the `hasPermission()` helper on your User model, checking permissions is straightforward:

```php
public function store(Request $request)
{
    if (!auth()->user()->hasPermission('bookings:create')) {
        abort(403);
    }
    
    // Create booking...
}
```

For model-specific authorization (like checking ownership), use Policies. Policies can still use `hasPermission()` to check Jetstream team permissions:

```php
// app/Policies/BookingPolicy.php
public function create(User $user): bool
{
    return $user->hasPermission('bookings:create');
}
```

## Using Gates for User Type Checks

While `isStaff` is a simple boolean check, you can create Gates for cleaner, reusable user-type checks:

```php
// In app/Providers/AuthServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('beStaff', function (User $user) {
        return $user->isStaff;
    });

    Gate::define('beMember', function (User $user) {
        return !$user->isStaff;
    });
}
```

Now you can use these in Blade templates or controllers:

```blade
@can('beStaff')
    {{-- Staff-only content --}}
    <a href="{{ route('admin.dashboard') }}">Admin Dashboard</a>
@endcan

@can('beMember')
    {{-- Member-only content --}}
    <a href="{{ route('bookings.index') }}">My Bookings</a>
@endcan
```

Or in controllers:

```php
if (Gate::allows('beStaff')) {
    // Staff-only logic
}
```

## Handling "Own" vs "All" Permissions

This is where the core permission challenge gets solved. Remember: Alice (a member) should only see her own bookings at Downtown, while Sarah (staff) needs to see all bookings at that location.

Use Laravel Policies for model-specific authorization:

```php
// app/Policies/BookingPolicy.php
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        // Can read all bookings
        if ($user->hasPermission('bookings:read')) {
            return true;
        }
        
        // Can only read own bookings
        return $user->hasPermission('bookings:read-own') && $booking->user_id === $user->id;
    }

    public function delete(User $user, Booking $booking): bool
    {
        // Can cancel any booking
        if ($user->hasPermission('bookings:delete')) {
            return true;
        }
        
        // Can only cancel own bookings
        return $user->hasPermission('bookings:cancel-own') && $booking->user_id === $user->id;
    }
}
```

With this policy:
- When Alice (member) visits `/bookings/123`, the policy checks if she has `bookings:read` (no) or `bookings:read-own` AND owns the booking (yes, if it's hers)
- When Sarah (staff) visits the same URL, the policy checks if she has `bookings:read` (yes) and grants access immediately

Register the policy in your `AuthServiceProvider`:

```php
// In app/Providers/AuthServiceProvider.php
use App\Models\Booking;
use App\Policies\BookingPolicy;

protected $policies = [
    Booking::class => BookingPolicy::class,
];
```

Now use the policy in controllers:

```php
public function show(Booking $booking)
{
    $this->authorize('view', $booking);
    
    return view('bookings.show', compact('booking'));
}

public function destroy(Booking $booking)
{
    $this->authorize('delete', $booking);
    
    $booking->delete();
    
    return redirect()->route('bookings.index');
}
```

## Role-Based UI Variations

Staff and members need completely different experiences. When Sarah (staff) logs into the Downtown location, she sees a management dashboard with all bookings, check-in tools, and reports. When Alice (member) logs in, she sees a simple booking interface focused on available rooms and her own reservations.

Use the `isStaff` flag or Gates for user-type checks:

```blade
@can('beStaff')
    {{-- Sarah (staff) sees: management dashboard with all bookings, check-in tools, reports --}}
    <x-manager-dashboard :location="$location" />
@else
    {{-- Alice (member) sees: booking interface with available rooms and her reservations --}}
    <x-member-dashboard :bookings="$userBookings" />
@endcan
```

Or check permissions directly:

```blade
@if(auth()->user()->hasPermission('admin:access'))
    {{-- Staff dashboard with management tools --}}
    <x-manager-dashboard :location="$location" />
@else
    {{-- Member dashboard with booking interface --}}
    <x-member-dashboard :bookings="$userBookings" />
@endif
```

Prefer permission-based checks over role-based checks for better flexibility. Permission checks are more granular and easier to modify without changing role definitions.

## Conditional UI Based on Permissions

Staff like Sarah (staff) and Tom (staff) need check-in buttons, report links, and admin tools. Members like Alice (member) should never see these controls—not just be denied access, but not even know they exist.

For granular UI control, you can register specific Gates or check permissions directly:

```php
// In app/Providers/AuthServiceProvider.php
public function boot(): void
{
    Gate::define('bookings:check-in', function (User $user) {
        return $user->hasPermission('bookings:check-in');
    });

    Gate::define('reports:view', function (User $user) {
        return $user->hasPermission('reports:view');
    });

    Gate::define('admin:access', function (User $user) {
        return $user->hasPermission('admin:access');
    });
}
```

Then use them in Blade:

```blade
{{-- Only Tom (staff) and Sarah (staff) see check-in buttons; Alice (member) never sees this --}}
@can('bookings:check-in')
    <button wire:click="checkIn({{ $booking->id }})">
        Check In
    </button>
@endcan

{{-- Staff-only: occupancy reports, revenue, etc. --}}
@can('reports:view')
    <a href="{{ route('reports.index') }}">
        View Reports
    </a>
@endcan

{{-- Management dashboard access --}}
@can('admin:access')
    <a href="{{ route('admin.dashboard') }}">
        Admin Dashboard
    </a>
@endcan
```

Alice's (member) view of the booking list shows only her bookings with a "Cancel" button. Sarah's (staff) view shows all bookings with "Check In", "Check Out", "Edit", and "Cancel" buttons. The same Blade component can serve both, with permissions controlling what renders.


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

Or use Laravel's built-in `Gate` middleware after registering the Gate:

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
            \Illuminate\Auth\Middleware\Authorize::class . ':admin:access',
        ]);
}
```

## Switching Between Locations

Remember Sarah (staff) from the challenge? She's a manager at Downtown but might also help out at Westside. Staff members often work at multiple locations, and Jetstream handles team switching natively:

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

When Sarah (staff) switches from Downtown to Westside, her `currentTeam` changes, and all permission checks now evaluate against the Westside team. Her `isStaff` flag remains true—she's still staff—but her team-scoped permissions are now checked against her role at Westside.

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

## Going Further with Bouncer

The hybrid approach with boolean flags, Gates, and Policies works great, but as your permission system grows more complex—especially if you need dynamic permissions or complex hierarchies—you might benefit from a dedicated package like [Bouncer](https://github.com/JosephSilber/bouncer).

Bouncer provides:
- **Database-stored permissions** — Define permissions dynamically without hardcoding in `JetstreamServiceProvider`
- **Ability inheritance** — Permissions cascade through role hierarchies
- **Cleaner API** — More expressive syntax for complex permission scenarios
- **Caching** — Built-in performance optimizations for permission checks

### Using Bouncer with Jetstream Teams

Bouncer can work alongside Jetstream teams. You'd use Bouncer for the permission logic while keeping Jetstream for team membership:

```php
use Silber\Bouncer\BouncerFacade as Bouncer;

// Define abilities
Bouncer::allow('space_manager')->to('manage', Booking::class);
Bouncer::allow('space_manager')->to('create', Room::class);
Bouncer::allow('member')->to('view', Booking::class);
Bouncer::allow('member')->toOwn(Booking::class)->to('delete'); // Own bookings only

// Assign roles scoped to a team
$user->assign('space_manager')->to($team);

// Check permissions
$user->can('manage', $booking); // true for space_manager on that team
$user->can('delete', $booking); // true only if it's their own booking

// Use in policies
class BookingPolicy
{
    public function delete(User $user, Booking $booking): bool
    {
        return $user->can('delete', $booking); // Bouncer handles the logic
    }
}
```

### When to Use Bouncer

Consider Bouncer if you need:
- **Dynamic permissions** — Permissions assigned at runtime, not just in code
- **Complex hierarchies** — Roles that inherit from other roles
- **Fine-grained ownership rules** — "own" permissions handled automatically
- **Multi-tenant permissions** — Different permission sets per team/location

For simpler applications, Gates and Policies with Jetstream's built-in permissions are usually sufficient and keep your dependencies minimal.

## Summary

Jetstream's team and role system is flexible enough to handle mixed user populations. The key principles:

1. **Use a two-layer approach** — Boolean flags (`isStaff`) distinguish user populations globally, while Jetstream roles handle team-scoped permissions
2. **Define clear roles** for each user type with appropriate permissions using the `resource:action` naming convention
3. **Add a simple `hasPermission()` helper** on the User model to make permission checking convenient throughout your application
4. **Use Gates for user-type checks** — Create Gates like `beStaff` for reusable user-type authorization
5. **Use Policies for model-specific authorization** — Handle "own" vs "all" permissions in dedicated policy classes that call `hasPermission()`
6. **Prefer permission checks over role checks** — Permission-based checks are more granular and flexible than hardcoded role names
7. **Consider Bouncer for advanced scenarios** — If you need dynamic permissions, complex hierarchies, or database-driven permissions, Bouncer provides a powerful alternative

The result is a system where staff and members coexist on the same team, each seeing the interface and capabilities appropriate to their role. User type is handled globally via boolean flags, while permissions remain team-scoped through Jetstream roles—giving you the best of both worlds.
