---
title: "Building a Customizable Notification System with User Preferences"
date: 2025-01-09
excerpt: "Users hate notification spam. Here's how to build a system where users can choose exactly which events they want to be notified about, with smart defaults and efficient querying."
tags: [laravel, notifications, livewire, user-experience]
slug: customizable-notification-preferences-laravel
---

Nobody likes notification spam. When your application sends emails for every minor event, users either disable all notifications or start ignoring their inbox entirely. Neither outcome is good.

The solution is user-controlled notification preferences. Let users choose which events matter to them. Status changes? Yes. New comments? Definitely. Risk assessment created? Maybe only for some users.

This post walks through building a notification preference system in Laravel—from the database schema to the UI component to the query that efficiently finds who should be notified.

## The Database Schema

You need three tables:

1. **staff_notifications** — A registry of all notification types
2. **notification_user** — A pivot table linking users to their enabled notifications
3. **users** — Your existing users table

```php
// Migration for staff_notifications
Schema::create('staff_notifications', function (Blueprint $table) {
    $table->id();
    $table->string('notification');  // "Client Status Changed", "New Comment", etc.
    $table->timestamps();
});

// Migration for notification_user pivot
Schema::create('notification_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('notification_id')->constrained('staff_notifications')->onDelete('cascade');
    $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
    $table->timestamps();
    
    $table->unique(['notification_id', 'user_id']);
});
```

Seed the notification types:

```php
// Database seeder
StaffNotification::insert([
    ['id' => 1, 'notification' => 'Client Created'],
    ['id' => 2, 'notification' => 'Client Edited'],
    ['id' => 3, 'notification' => 'Client Status Changed'],
    ['id' => 4, 'notification' => 'Client Conflict Check Created'],
    ['id' => 5, 'notification' => 'Client Risk Assessment Created'],
    ['id' => 10, 'notification' => 'Instruction Status Changed'],
    ['id' => 11, 'notification' => 'Instruction Created'],
    ['id' => 12, 'notification' => 'Note Created'],
    ['id' => 13, 'notification' => 'New Comment'],
    ['id' => 14, 'notification' => 'Verification Started'],
    ['id' => 15, 'notification' => 'Verification Completed'],
]);
```

The gaps in IDs leave room for future notifications in logical groups.

## The Models

A simple model for notification types:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffNotification extends Model
{
    protected $table = 'staff_notifications';
    protected $fillable = ['notification'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'notification_user', 'notification_id', 'user_id');
    }
}
```

And the inverse relationship on User:

```php
// User.php
public function notifications()
{
    return $this->belongsToMany(
        StaffNotification::class, 
        'notification_user', 
        'user_id', 
        'notification_id'
    );
}
```

If a user has a record in `notification_user` for a given notification type, they want to receive it. No record means no notification.

## The Preference UI

A Livewire component handles the settings page:

```php
<?php

namespace App\Http\Livewire\Profile;

use Livewire\Component;
use Auth;
use App\Models\UserNotification;
use App\Models\StaffNotification;

class NotificationSettings extends Component
{
    public array $checkboxes = [];

    public function mount()
    {
        // Build an array of notification IDs that are currently enabled
        $enabledNotifications = Auth::user()->notifications->pluck('id')->toArray();
        
        // Create checkbox state: [notification_id => true/false]
        $this->checkboxes = array_fill_keys($enabledNotifications, true);
    }

    public function updateNotification($notification_id)
    {
        $existing = UserNotification::where('notification_id', $notification_id)
            ->where('user_id', Auth::user()->id)
            ->first();

        if ($existing) {
            // Currently enabled, so disable it
            $existing->delete();
            unset($this->checkboxes[$notification_id]);
        } else {
            // Currently disabled, so enable it
            UserNotification::create([
                'notification_id' => $notification_id,
                'user_id' => Auth::user()->id
            ]);
            $this->checkboxes[$notification_id] = true;
        }
    }

    public function render()
    {
        $allNotifications = StaffNotification::all();
        
        return view('livewire.profile.notification-settings', [
            'notifications' => $allNotifications
        ]);
    }
}
```

The template:

```blade
<div>
    <h3 class="text-lg font-semibold mb-4">Email Notifications</h3>
    <p class="text-sm text-gray-600 mb-6">
        Choose which events you want to receive email notifications for.
    </p>

    <div class="space-y-4">
        @foreach($notifications as $notification)
            <label class="flex items-center gap-3 cursor-pointer">
                <input 
                    type="checkbox" 
                    wire:click="updateNotification({{ $notification->id }})"
                    @checked(isset($checkboxes[$notification->id]))
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                >
                <span>{{ $notification->notification }}</span>
            </label>
        @endforeach
    </div>
</div>
```

Each checkbox click immediately updates the database. No save button needed—changes are instant.

## Finding Who to Notify

The core query: given a team and a notification type, find all users who should receive it. This combines team membership with notification preferences:

```php
// User.php
public static function whereShouldBeNotified(int $team_id, string $notificationName)
{
    // Find the notification type ID
    $notification_id = StaffNotification::where('notification', $notificationName)
        ->pluck('id')
        ->first();

    // Find staff users who:
    // 1. Are members of the specified team
    // 2. Have opted in to this notification type
    $users = User::where('isStaff', true)
        ->join('team_user', 'team_user.user_id', '=', 'users.id')
        ->where('team_user.team_id', $team_id)
        ->join('notification_user', 'notification_user.user_id', '=', 'users.id')
        ->where('notification_user.notification_id', $notification_id)
        ->get();

    return $users;
}
```

This query is efficient—it uses joins rather than nested queries, and the database can use indexes on `team_id` and `notification_id`.

## Special Cases: Client Users for Certain Notifications

Some notifications should always go to certain user types regardless of preferences. For example, all account holders should get comment notifications:

```php
public static function whereShouldBeNotified(int $team_id, string $notificationName)
{
    $notification_id = StaffNotification::where('notification', $notificationName)
        ->pluck('id')
        ->first();

    $users = User::where('isStaff', true)
        ->join('team_user', 'team_user.user_id', '=', 'users.id')
        ->where('team_user.team_id', $team_id)
        ->join('notification_user', 'notification_user.user_id', '=', 'users.id')
        ->where('notification_user.notification_id', $notification_id)
        ->get();

    // Special case: All account holders get comment notifications
    // ID 13 is "New Comment"
    if ($notification_id == 13) {
        $accountHolders = User::where('isStaff', false)
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->where('team_user.team_id', $team_id)
            ->where('team_user.role', 'account-holder')
            ->get();
            
        return $users->merge($accountHolders);
    }

    return $users;
}
```

## Using the Query in Listeners

In your event listeners, send notifications to the filtered list:

```php
<?php

namespace App\Listeners\Client;

use App\Models\User;
use App\Notifications\Client\ClientStatusChangedNotification;
use Illuminate\Support\Facades\Notification;

class ClientStatusChanged
{
    public function handle($event)
    {
        $client = $event->client;

        // Only notify users who opted in to this notification type
        $recipients = User::whereShouldBeNotified(
            $client->team->id, 
            'Client Status Changed'
        );

        Notification::send(
            $recipients,
            new ClientStatusChangedNotification($client)
        );
    }
}
```

## Setting Defaults on User Creation

New users should have sensible defaults. Don't make them manually enable everything:

```php
// User.php boot method
protected static function boot()
{
    parent::boot();

    static::created(function($model) {
        if ($model->isStaff) {
            // Staff get a default set of notifications
            DB::table('notification_user')->insert([
                ['notification_id' => 3, 'user_id' => $model->id],   // Client Status Changed
                ['notification_id' => 10, 'user_id' => $model->id],  // Instruction Status Changed
                ['notification_id' => 12, 'user_id' => $model->id],  // Note Created
                ['notification_id' => 13, 'user_id' => $model->id],  // New Comment
                ['notification_id' => 14, 'user_id' => $model->id],  // Verification Started
                ['notification_id' => 15, 'user_id' => $model->id],  // Verification Completed
            ]);
        }
    });
}
```

New staff members are subscribed to the most important notifications. They can customize later.

## Grouping Notifications in the UI

As your notification types grow, group them for better UX:

```blade
<div class="space-y-8">
    <div>
        <h4 class="font-medium mb-3">Client Events</h4>
        <div class="space-y-2 ml-4">
            @foreach($notifications->where('id', '<', 10) as $notification)
                {{-- Checkbox component --}}
            @endforeach
        </div>
    </div>

    <div>
        <h4 class="font-medium mb-3">Instruction Events</h4>
        <div class="space-y-2 ml-4">
            @foreach($notifications->whereBetween('id', [10, 12]) as $notification)
                {{-- Checkbox component --}}
            @endforeach
        </div>
    </div>

    <div>
        <h4 class="font-medium mb-3">Communication</h4>
        <div class="space-y-2 ml-4">
            @foreach($notifications->whereBetween('id', [12, 14]) as $notification)
                {{-- Checkbox component --}}
            @endforeach
        </div>
    </div>
</div>
```

Or add a `category` column to `staff_notifications` for more flexibility.

## Testing

Test that the query works correctly:

```php
public function test_only_opted_in_users_receive_notifications()
{
    $team = Team::factory()->create();
    
    // User who opted in
    $optedIn = User::factory()->create(['isStaff' => true]);
    $optedIn->teams()->attach($team);
    DB::table('notification_user')->insert([
        'notification_id' => 3, // Client Status Changed
        'user_id' => $optedIn->id
    ]);
    
    // User who did not opt in
    $optedOut = User::factory()->create(['isStaff' => true]);
    $optedOut->teams()->attach($team);
    
    $recipients = User::whereShouldBeNotified($team->id, 'Client Status Changed');
    
    $this->assertTrue($recipients->contains($optedIn));
    $this->assertFalse($recipients->contains($optedOut));
}
```

## Conclusion

A notification preference system respects your users' attention. Instead of blasting everyone with every event, you send targeted notifications to people who care.

The implementation is straightforward: a registry of notification types, a pivot table for preferences, and a query that combines team membership with opt-in status. Add a Livewire component for instant updates, and you've got a system that scales to dozens of notification types without overwhelming anyone.

Your users' inboxes will thank you.

