---
title: "Role-Based Permissions in Multi-Tenant Laravel with Jetstream Teams"
date: 2025-01-09
excerpt: "Jetstream's team feature is often used for organizations. But what if you need different permission sets for clients vs. staff on the same team? Here's how to make it work."
tags: [laravel, jetstream, permissions, multi-tenant, teams]
slug: role-based-permissions-jetstream-teams
---

Laravel Jetstream ships with a teams feature that's typically used for multi-tenant SaaS applications. Each team is an organization, and users belong to one or more teams with different roles.

But the default setup assumes everyone on a team is roughly equivalent—maybe some are admins and some are editors, but they're all "internal" users. What happens when you need to mix fundamentally different user types on the same team?

Consider a professional services platform where each client is a "team," but that team includes both the client's people (who have limited access) and staff members (who manage the account). Same team, very different permission needs.

This post walks through adapting Jetstream's permission system for this hybrid model.

## The Challenge

In a typical Jetstream setup, you define roles with permissions:

```php
Jetstream::role('admin', 'Administrator', ['*'])->description('Full access');
Jetstream::role('editor', 'Editor', ['read', 'create', 'update'])->description('Can edit content');
```

These roles exist within the context of a team. A user might be an admin on Team A and an editor on Team B.

But what if you have two fundamentally different user populations?

1. **Staff members** — Employees who manage client accounts, create content, run verifications
2. **Client users** — The clients themselves, who can view their account and approve documents

Staff need granular permissions for all the management functions. Clients need a simpler set focused on their own account. And they're both members of the same team (the client's "workspace").

## Defining Roles for Different User Types

Start by defining roles that map to your user populations. In `JetstreamServiceProvider`:

```php
protected function configurePermissions()
{
    Jetstream::defaultApiTokenPermissions(['read']);

    // Client-side roles (limited access)
    Jetstream::role('account-holder', 'Account Holder', [
        'client:read',
        'client:update',
        'instruction:read',
        'instruction:set-client-accepted',
        'instruction:set-client-declined',
        'verification:read',
        'verification:update',
    ])->description('Default role for clients. Can view account and approve documents.');

    Jetstream::role('officer', 'Officer', [
        'client:read',
        'client:update',
        'instruction:read',
        'instruction:set-client-accepted',
        'instruction:set-client-declined',
        'verification:read',
        'verification:update',
    ])->description('Company officer. Same permissions as account holder.');

    Jetstream::role('psc', 'Person with Significant Control', [
        'client:read',
        'client:update',
        'instruction:read',
        'instruction:set-client-accepted',
        'instruction:set-client-declined',
        'verification:read',
        'verification:update',
    ])->description('PSC. Same permissions as account holder.');

    // Staff role (full access)
    Jetstream::role('adviser', 'Adviser', [
        // Client management
        'client:create',
        'client:read',
        'client:update',
        'client:delete',
        'client:skip-verification',
        'client:set-active',
        'client:set-conflict-checks',
        'client:set-risk-assessment',
        'client:set-aml-verification',

        // Conflict checks
        'client-conflict-check:create',
        'client-conflict-check:read',
        'client-conflict-check:delete',

        // Risk assessments
        'client-risk-assessment:create',
        'client-risk-assessment:read',
        'client-risk-assessment:delete',

        // Instructions
        'instruction:create',
        'instruction:read',
        'instruction:update',
        'instruction:delete',
        'instruction:set-instruction-form-client-review',
        'instruction:set-instruction-form-draft',
        'instruction:set-active',
        'instruction:set-risk-assessment',

        // Instruction risk assessments
        'instruction-risk-assessment:create',
        'instruction-risk-assessment:read',
        'instruction-risk-assessment:delete',

        // File reviews
        'instruction-file-review:create',
        'instruction-file-review:read',
        'instruction-file-review:update',
        'instruction-file-review:delete',

        // Verifications
        'verification:create',
        'verification:read',
        'verification:update',
        'verification:request',

        // Notes
        'note:create',
        'note:read',
        'note:delete',
    ])->description('Staff member with full client management access.');
}
```

Notice the permission naming convention: `resource:action`. This makes permissions scannable and predictable. When you need a new permission, you know the format.

## User Type Flags

Roles alone don't capture everything. Some permissions depend on global user attributes, not team membership. Add flags to your User model:

```php
protected $fillable = [
    'name', 
    'email', 
    'password', 
    'isStaff',           // Is this a staff member?
    'isApprovingDirector', // Can approve documents?
    'isFinance',          // Can confirm payments?
    'isAdmin',            // Can access admin panel?
    'current_team_id'
];
```

These flags enable checks that cross team boundaries:

```php
public function canAccessFilament(): bool
{
    return $this->isStaff && $this->hasVerifiedEmail() && $this->isAdmin;
}

public function canSeeAll(): bool
{
    if (!$this->isStaff) return false;
    if ($this->isApprovingDirector) return true;
    if ($this->isFinance) return true;
    if ($this->isAdmin) return true;
    return false;
}
```

## Checking Permissions

Jetstream provides `hasTeamPermission()` to check if a user has a permission on a specific team. Wrap it for convenience:

```php
public function hasPermission(string $ability): bool
{
    return $this->hasTeamPermission($this->currentTeam()->first(), $ability);
}
```

Now controllers can check permissions cleanly:

```php
public function setActive(Client $client)
{
    if (!Auth::user()->hasPermission('client:set-active')) {
        abort(403);
    }
    
    // ... perform action
}
```

## Role-Based Checks

Sometimes you need to know the user's role, not just their permissions:

```php
public function hasRole(string $role): bool
{
    $dbRole = DB::table('team_user')
        ->select('role')
        ->where('team_id', $this->currentTeam()->first()->id)
        ->where('user_id', $this->id)
        ->first()
        ->role;
        
    return ($dbRole === $role);
}

public function getRole()
{
    return DB::table('team_user')
        ->select('role')
        ->where('team_id', $this->currentTeam()->first()->id)
        ->where('user_id', $this->id)
        ->first()
        ->role;
}
```

This lets you show different UI based on role:

```blade
@if(Auth::user()->hasRole('adviser'))
    {{-- Staff-specific UI --}}
    <button onclick="createConflictCheck()">Create Conflict Check</button>
@else
    {{-- Client-facing UI --}}
    <p>Your adviser is reviewing your application.</p>
@endif
```

## Combining Permissions with Status Checks

Permissions answer "can this user ever do this action?" Status evaluators answer "is this action valid right now?" Combine them:

```php
public function setStatusRiskAssessment(Client $client)
{
    // Permission check: Can this user type perform this action?
    if (!Auth::user()->hasPermission('client:set-risk-assessment')) {
        abort(403);
    }
    
    // Status check: Is this action valid in the current state?
    if (!ClientStatusEvaluator::canSetRiskAssessmentClient($client->status)) {
        abort(400, 'Cannot reset to risk assessment from current status');
    }

    $client->status = ClientStatus::RISK_ASSESSMENT->value;
    $client->save();

    return redirect()->back();
}
```

## Conditional UI Based on Permissions

Use permissions to show/hide UI elements:

```php
// In controller
$userPermissions = [
    'note:read' => $authUser->hasPermission('note:read'),
    'client-risk-assessment:read' => $authUser->hasPermission('client-risk-assessment:read'),
    'client-conflict-check:read' => $authUser->hasPermission('client-conflict-check:read'),
    'instruction:read' => $authUser->hasPermission('instruction:read'),
    'verification:request' => $authUser->hasPermission('verification:request'),
];

return view('clients.show', [
    'client' => $client,
    'userPermissions' => $userPermissions,
]);
```

In Blade:

```blade
@if($userPermissions['client-conflict-check:read'])
    <div class="tab" id="conflict-checks">
        {{-- Conflict check content --}}
    </div>
@endif

@if($userPermissions['verification:request'])
    <button onclick="requestVerification()">Request Verification</button>
@endif
```

## Default Permissions on User Creation

When staff members are created, give them sensible notification defaults:

```php
protected static function boot()
{
    parent::boot();

    static::created(function($model) {
        if ($model->isStaff) {
            // Subscribe staff to relevant notifications
            DB::table('notification_user')->insert([
                ['notification_id' => 3, 'user_id' => $model->id],  // Client Status Changed
                ['notification_id' => 10, 'user_id' => $model->id], // Instruction Status Changed
                ['notification_id' => 12, 'user_id' => $model->id], // Note Created
                ['notification_id' => 13, 'user_id' => $model->id], // New Comment
                ['notification_id' => 14, 'user_id' => $model->id], // Verification Started
                ['notification_id' => 15, 'user_id' => $model->id], // Verification Completed
            ]);
        } else {
            // For client users, create verification record
            Verification::create([
                'user_id' => $model->id,
                'status' => 'not requested',
                'external_user_id' => uniqid()
            ]);
        }
    });
}
```

Staff and clients have different onboarding flows, handled at the model level.

## Extending Jetstream's Permission Check

Jetstream's default `hasTeamPermission()` checks for exact matches or wildcard (`*`). Extend it for pattern matching:

```php
public function hasTeamPermission($team, string $permission)
{
    if (!$this->belongsToTeam($team)) {
        return false;
    }

    // Check API token permissions if using Sanctum
    if (in_array(HasApiTokens::class, class_uses_recursive($this)) &&
        !$this->tokenCan($permission) &&
        $this->currentAccessToken() !== null) {
        return false;
    }

    $permissions = $this->teamPermissions($team);

    return in_array($permission, $permissions) ||
           in_array('*', $permissions) ||
           // Support wildcard patterns like '*:create'
           (Str::endsWith($permission, ':create') && in_array('*:create', $permissions)) ||
           (Str::endsWith($permission, ':update') && in_array('*:update', $permissions));
}
```

Now you can define roles with pattern permissions:

```php
Jetstream::role('creator', 'Creator', [
    '*:read',   // Can read everything
    '*:create', // Can create everything
])->description('Can read and create but not update or delete.');
```

## Switching Between Teams/Clients

Staff members work with multiple clients. Provide a way to switch context:

```php
public function switchClient($team_id)
{
    $team = Jetstream::newTeamModel()->findOrFail($team_id);

    if (!$this->switchTeam($team)) {
        abort(403, 'You do not have access to this client');
    }

    return true;
}
```

In a Livewire component:

```php
public function switchToClient($teamId)
{
    Auth::user()->switchClient($teamId);
    
    return redirect()->to('/clients/' . Team::find($teamId)->client->uuid);
}
```

## Summary

Jetstream's team and role system is flexible enough to handle mixed user populations. The key adaptations:

1. **Define roles for each user type** with appropriate permissions
2. **Add user-level flags** for global capabilities (staff, admin, etc.)
3. **Combine permission checks with status checks** for complete authorization
4. **Use role checks for UI variations** between user types
5. **Set up user-type-specific defaults** at creation time

The result is a system where clients and staff coexist on the same team, each seeing the interface and capabilities appropriate to their role.

