---
title: "Building a Customizable Notification System with User Preferences"
date: 2025-01-09
excerpt: "Use PHP backed enums as your source of truth for notification types instead of database tables. Type-safe, scalable, and zero migration overhead when adding new types."
tags: [laravel, notifications, livewire, user-experience, php-enums]
slug: customizable-notification-preferences-laravel
---

Nobody likes notification spam. When your application sends emails for every minor event, users either disable all notifications or start ignoring their inbox entirely. Neither outcome is good.

The solution is user-controlled notification preferences. Let users choose which events matter to them. Course completions? Yes. New comments? Definitely. Assignment graded? Absolutely.

Most tutorials solve this by creating a `notification_types` table in your database. But here's a question worth asking: if your notification types are developer-defined constants that don't change at runtime, why involve the database at all?

This post shows you a better approach: use a **PHP backed enum** as your single source of truth for notification types. You get type safety, IDE autocomplete, zero migration overhead when adding new types, and all metadata lives right next to the definition. The database only stores user preferences—not the types themselves.

We'll build the complete system: the enum definition, simplified database schema, Livewire component for preferences, and query scopes that efficiently find who should be notified.

## The Scenario: Online Learning Platform

Consider an online learning platform where instructors and students receive various notifications:

**For Instructors:**
- New student enrolled
- Assignment submitted
- Discussion comment posted
- Course review received

**For Students:**
- Assignment graded
- Course updated
- Discussion reply
- Certificate available

Different users care about different events. Let them choose.

## The Database Schema

You only need one table: `notification_preferences`. Notice we don't need a `notification_types` table—the enum is our source of truth.

```php
Schema::create('notification_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('type'); // Stores the enum's string value
    $table->boolean('enabled')->default(true);
    $table->timestamps();
    
    $table->unique(['user_id', 'type']);
});
```

The `type` column stores the string value from our enum (e.g., `'assignment_graded'`). When we retrieve preferences, Laravel's enum casting automatically converts it back to the enum instance.

## The NotificationType Enum

Here's where the magic happens. Instead of a database table, we define all notification types in a PHP backed enum:

```php
<?php

namespace App\Enums;

enum NotificationType: string
{
    // Courses
    case NewStudentEnrolled = 'new_student_enrolled';
    case CourseReviewReceived = 'course_review_received';
    case CourseUpdated = 'course_updated';
    
    // Assignments
    case AssignmentSubmitted = 'assignment_submitted';
    case AssignmentGraded = 'assignment_graded';
    case AssignmentDueSoon = 'assignment_due_soon';
    
    // Discussions
    case DiscussionReply = 'discussion_reply';
    case DiscussionMention = 'discussion_mention';
    
    // Achievements
    case CertificateAvailable = 'certificate_available';
    case BadgeEarned = 'badge_earned';

    public function label(): string
    {
        return match($this) {
            self::NewStudentEnrolled => 'New Student Enrolled',
            self::CourseReviewReceived => 'Course Review Received',
            self::CourseUpdated => 'Course Updated',
            self::AssignmentSubmitted => 'Assignment Submitted',
            self::AssignmentGraded => 'Assignment Graded',
            self::AssignmentDueSoon => 'Assignment Due Soon',
            self::DiscussionReply => 'Discussion Reply',
            self::DiscussionMention => 'Discussion Mention',
            self::CertificateAvailable => 'Certificate Available',
            self::BadgeEarned => 'Badge Earned',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::NewStudentEnrolled => 'When a student enrolls in your course',
            self::CourseReviewReceived => 'When someone reviews your course',
            self::CourseUpdated => 'When a course you\'re enrolled in is updated',
            self::AssignmentSubmitted => 'When a student submits an assignment',
            self::AssignmentGraded => 'When your assignment is graded',
            self::AssignmentDueSoon => 'Reminder before assignment deadline',
            self::DiscussionReply => 'When someone replies to your discussion',
            self::DiscussionMention => 'When someone mentions you in a discussion',
            self::CertificateAvailable => 'When you complete a course and earn a certificate',
            self::BadgeEarned => 'When you earn a new badge',
        };
    }

    public function category(): string
    {
        return match($this) {
            self::NewStudentEnrolled,
            self::CourseReviewReceived,
            self::CourseUpdated => 'courses',
            self::AssignmentSubmitted,
            self::AssignmentGraded,
            self::AssignmentDueSoon => 'assignments',
            self::DiscussionReply,
            self::DiscussionMention => 'discussions',
            self::CertificateAvailable,
            self::BadgeEarned => 'achievements',
        };
    }

    public function defaultEnabled(): bool
    {
        return match($this) {
            self::BadgeEarned => false,
            default => true,
        };
    }

    public static function grouped(): array
    {
        return collect(self::cases())
            ->groupBy(fn ($case) => $case->category())
            ->toArray();
    }
}
```

This enum gives us several benefits:

- **Type safety**: `NotificationType::AssignmentGraded` instead of `'Assignment Graded'` means typos are caught at compile time, not runtime.
- **IDE autocomplete**: Your editor knows all available notification types and can suggest them.
- **No migrations**: Adding a new notification type is as simple as adding a new enum case—no database changes needed.
- **Metadata lives with the definition**: Label, description, category, and default state are all defined right where the type is declared.
- **Easy refactoring**: Rename an enum case, and your IDE can update all usages automatically.

## The Models

First, the simple `NotificationPreference` model:

```php
<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'enabled'];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

The `type` cast ensures that when you access `$preference->type`, you get a `NotificationType` enum instance, not a string.

Now the User model methods:

```php
// User.php
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Relations\HasMany;

public function notificationPreferences(): HasMany
{
    return $this->hasMany(NotificationPreference::class);
}

/**
 * Check if user wants to receive a specific notification.
 */
public function wantsNotification(NotificationType $type): bool
{
    $preference = $this->notificationPreferences()
        ->where('type', $type->value)
        ->first();

    // If no preference exists, use the default from the enum
    if (!$preference) {
        return $type->defaultEnabled();
    }

    return $preference->enabled;
}
```

Notice how `wantsNotification()` now accepts a `NotificationType` enum directly—type-safe and impossible to typo.

## The Preference UI

A Livewire component handles the settings page:

```php
<?php

namespace App\Livewire\Profile;

use App\Enums\NotificationType;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationSettings extends Component
{
    public array $preferences = [];

    public function mount(): void
    {
        $user = Auth::user();
        
        // Build preferences array from user's current settings
        $userPrefs = $user->notificationPreferences
            ->keyBy(fn ($pref) => $pref->type->value)
            ->map(fn ($pref) => $pref->enabled);

        // Fill in defaults for unset preferences
        foreach (NotificationType::cases() as $type) {
            $this->preferences[$type->value] = 
                $userPrefs[$type->value] ?? $type->defaultEnabled();
        }
    }

    public function toggleNotification(string $typeValue): void
    {
        $type = NotificationType::from($typeValue);
        $user = Auth::user();
        $currentState = $this->preferences[$typeValue] ?? false;
        $newState = !$currentState;

        // Update or create the preference
        // Use $type->value in the where clause since we're querying the database
        $user->notificationPreferences()->updateOrCreate(
            ['type' => $type->value],
            ['enabled' => $newState]
        );

        $this->preferences[$typeValue] = $newState;
    }

    public function render()
    {
        $notificationsByCategory = NotificationType::grouped();

        return view('livewire.profile.notification-settings', [
            'notificationsByCategory' => $notificationsByCategory,
        ]);
    }
}
```

The template with grouped notifications:

```blade
<div>
    <h3 class="text-lg font-semibold mb-4">Email Notifications</h3>
    <p class="text-sm text-gray-600 mb-6">
        Choose which events you want to receive email notifications for.
    </p>

    <div class="space-y-8">
        @foreach($notificationsByCategory as $category => $notifications)
            <div>
                <h4 class="font-medium text-gray-900 mb-3 capitalize">
                    {{ str_replace('_', ' ', $category) }}
                </h4>
                <div class="space-y-3 ml-4">
                    @foreach($notifications as $notification)
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input 
                                type="checkbox" 
                                wire:click="toggleNotification('{{ $notification->value }}')"
                                @checked($preferences[$notification->value] ?? false)
                                class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                            <div>
                                <span class="block font-medium">{{ $notification->label() }}</span>
                                <span class="block text-sm text-gray-500">{{ $notification->description() }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
```

Each checkbox click immediately updates the database. No save button needed—changes are instant.

## Finding Who to Notify

The core query: given a course and a notification type, find all users who should receive it. Create a query scope for clean, reusable queries:

```php
// User.php
use App\Enums\NotificationType;

public function scopeWantsNotification($query, NotificationType $type)
{
    return $query->where(function ($q) use ($type) {
        // Users who explicitly opted in
        $q->whereHas('notificationPreferences', fn ($pref) => 
            $pref->where('type', $type->value)->where('enabled', true)
        )
        // OR users with no preference who should get the default
        ->orWhere(function ($defaultQuery) use ($type) {
            if ($type->defaultEnabled()) {
                $defaultQuery->whereDoesntHave('notificationPreferences', fn ($pref) =>
                    $pref->where('type', $type->value)
                );
            }
        });
    });
}

public function scopeEnrolledInCourse($query, int $courseId)
{
    return $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
}

public function scopeInstructorOf($query, int $courseId)
{
    return $query->whereHas('courses', fn ($q) => $q->where('courses.id', $courseId));
}
```

Now you can chain these scopes elegantly with type-safe enum values:

```php
// Find all enrolled students who want assignment graded notifications
$students = User::enrolledInCourse($course->id)
    ->wantsNotification(NotificationType::AssignmentGraded)
    ->get();

// Find the instructor if they want submission notifications
$instructors = User::instructorOf($course->id)
    ->wantsNotification(NotificationType::AssignmentSubmitted)
    ->get();
```

## Using Scopes in Event Listeners

In your event listeners, send notifications to the filtered list with type-safe enum usage:

```php
<?php

namespace App\Listeners\Assignment;

use App\Enums\NotificationType;
use App\Events\Assignment\AssignmentGraded;
use App\Models\User;
use App\Notifications\Assignment\AssignmentGradedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyStudentOfGrade
{
    public function handle(AssignmentGraded $event): void
    {
        $assignment = $event->assignment;
        $student = $assignment->student;

        // Only notify if student wants this notification
        if ($student->wantsNotification(NotificationType::AssignmentGraded)) {
            $student->notify(new AssignmentGradedNotification($assignment));
        }
    }
}
```

For bulk notifications:

```php
<?php

namespace App\Listeners\Course;

use App\Enums\NotificationType;
use App\Events\Course\CourseUpdated;
use App\Models\User;
use App\Notifications\Course\CourseUpdatedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyEnrolledStudents
{
    public function handle(CourseUpdated $event): void
    {
        $course = $event->course;

        // Get all enrolled students who want course update notifications
        $recipients = User::enrolledInCourse($course->id)
            ->wantsNotification(NotificationType::CourseUpdated)
            ->get();

        Notification::send(
            $recipients,
            new CourseUpdatedNotification($course)
        );
    }
}
```

## Testing

Test that the query works correctly with enum-based notification types:

```php
use App\Enums\NotificationType;
use App\Models\User;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only includes users who opted in to notifications', function () {
    $course = Course::factory()->create();
    
    // User who opted in
    $optedIn = User::factory()->create();
    $optedIn->enrollments()->create(['course_id' => $course->id]);
    $optedIn->notificationPreferences()->create([
        'type' => NotificationType::AssignmentGraded,
        'enabled' => true,
    ]);
    
    // User who opted out
    $optedOut = User::factory()->create();
    $optedOut->enrollments()->create(['course_id' => $course->id]);
    $optedOut->notificationPreferences()->create([
        'type' => NotificationType::AssignmentGraded,
        'enabled' => false,
    ]);
    
    $recipients = User::enrolledInCourse($course->id)
        ->wantsNotification(NotificationType::AssignmentGraded)
        ->get();
    
    expect($recipients)->toContain($optedIn)
        ->not->toContain($optedOut);
});

it('uses defaults when user has no preference set', function () {
    $course = Course::factory()->create();
    
    // User with no explicit preference
    // CourseUpdated has defaultEnabled() => true, so they should be included
    $user = User::factory()->create();
    $user->enrollments()->create(['course_id' => $course->id]);
    
    $recipients = User::enrolledInCourse($course->id)
        ->wantsNotification(NotificationType::CourseUpdated)
        ->get();
    
    expect($recipients)->toContain($user);
});

it('respects default disabled preference', function () {
    $course = Course::factory()->create();
    
    // BadgeEarned has defaultEnabled() => false
    // User with no explicit preference should NOT receive it
    $user = User::factory()->create();
    $user->enrollments()->create(['course_id' => $course->id]);
    
    $recipients = User::enrolledInCourse($course->id)
        ->wantsNotification(NotificationType::BadgeEarned)
        ->get();
    
    expect($recipients)->not->toContain($user);
});
```

## Conclusion

A notification preference system respects your users' attention. Instead of blasting everyone with every event, you send targeted notifications to people who care.

The enum-based approach we've built gives you several advantages over the traditional database-table approach:

- **Type safety**: IDE autocomplete and compile-time checks prevent typos like `'Assigment Graded'` (notice the typo).
- **Simpler schema**: One table for preferences instead of two—no need to manage a registry table.
- **Zero migration overhead**: Adding a new notification type is as simple as adding a new enum case. No database migration, no seeding, no coordination.
- **Metadata co-location**: Label, description, category, and default state all live right where the type is defined, making it easy to understand and modify.
- **Refactor-friendly**: Rename an enum case, and your IDE can update all usages automatically.

The implementation scales cleanly: add new enum cases as your application grows, and the rest of the system adapts automatically. The database only stores what matters—user preferences—while the developer-defined types live in code where they belong.

Your users' inboxes will thank you, and so will your future self when you need to add a new notification type.
