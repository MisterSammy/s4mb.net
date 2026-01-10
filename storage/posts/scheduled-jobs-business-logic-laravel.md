---
title: "Scheduled Jobs for Business Logic: Automatic Expiration and Reminder Systems"
date: 2025-01-09
excerpt: "Not everything happens on user interaction. Here's how to build scheduled jobs that enforce business rules like rental expiration and reminder emails."
tags: [laravel, scheduling, jobs, automation, business-logic]
slug: scheduled-jobs-business-logic-laravel
---

Some business rules can't wait for user interaction. Equipment rentals expire after 14 days. Reminders need to go out 2 days before deadlines. Inactive accounts should be flagged automatically.

Laravel's task scheduler handles these time-based rules elegantly. Instead of building a separate cron infrastructure, you define scheduled tasks in PHP and let a single cron entry run them.

This post walks through two related scheduled jobs: one that sends reminders before expiration, and one that actually expires rentals when the deadline passes.

## The Business Rule

Consider this workflow: when equipment is sent out for a rental, the customer has 14 days to return it. If they don't:

1. At day 12, send a reminder that the return deadline is approaching
2. At day 14, mark the rental as overdue and notify relevant parties

Both actions happen automatically, without any user triggering them.

## Tracking When States Changed

The challenge is knowing when the 14-day clock started. If you only store the current status, you don't know when it changed.

**The Right Way: Dedicated Timestamp Columns**

While it might seem convenient to derive timing from activity logs, that approach has significant problems:

- **Fragile string matching:** Log messages can change format, breaking your queries
- **Performance issues:** Full-text searches on log content don't scale
- **Unclear intent:** Business logic buried in observability data
- **Missed runs:** If the scheduled job misses a day, `== 14` matching fails

Instead, use dedicated timestamp columns that explicitly track business events:

```php
// Migration
Schema::table('rentals', function (Blueprint $table) {
    $table->timestamp('rented_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('reminder_sent_at')->nullable();
    $table->index('expires_at'); // Index for efficient queries
});
```

Set these timestamps when the status changes:

```php
$rental->status = RentalStatus::RENTED->value;
$rental->rented_at = now();
$rental->expires_at = now()->addDays(14);
$rental->save();
```

Now your scheduled jobs can query efficiently with indexed columns, and the business intent is crystal clear.

## The Expiration Command

Create an Artisan command that finds and expires overdue rentals:

```php
<?php

namespace App\Console\Commands;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Notifications\Rental\RentalOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ExpireRentals extends Command
{
    protected $signature = 'rentals:expire';
    protected $description = 'Marks rentals as overdue when the return deadline has passed.';

    public function handle(): int
    {
        // Find all rentals that are still marked as rented but have passed their expiration date
        // Using whereDate with <= ensures we catch any rentals that expired today or earlier
        $overdueRentals = Rental::where('status', RentalStatus::RENTED->value)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now())
            ->get();

        $expiredCount = 0;

        foreach ($overdueRentals as $rental) {
            // Notify the customer who rented the equipment
            $rental->customer->notify(new RentalOverdueNotification($rental));

            // Also notify staff members who manage this rental
            foreach ($rental->assignedStaff as $staff) {
                $staff->notify(new RentalOverdueNotification($rental));
            }

            // Update status
            $rental->status = RentalStatus::OVERDUE->value;
            $rental->save();
            
            $expiredCount++;
        }

        $this->info("{$expiredCount} rentals marked as overdue.");

        return Command::SUCCESS;
    }
}
```

Key improvements:

**Robust date matching.** Using `whereDate('expires_at', '<=', now())` catches all overdue rentals, even if the job missed running yesterday. No fragile `== 14` exact matching.

**Indexed queries.** The `expires_at` column is indexed, so this query scales efficiently even with thousands of rentals.

**Clear business logic.** The timestamps explicitly track when rentals were sent out and when they expire. No need to parse log messages.

## The Reminder Command

A similar command sends warnings before expiration:

```php
<?php

namespace App\Console\Commands;

use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Notifications\Rental\RentalExpiringSoonNotification;
use Illuminate\Console\Command;

class SendRentalReminders extends Command
{
    protected $signature = 'rentals:send-reminders';
    protected $description = 'Sends reminder emails for rentals expiring in 2 days.';

    public function handle(): int
    {
        // Find rentals that expire in 2 days and haven't been reminded yet
        $twoDaysFromNow = now()->addDays(2)->startOfDay();
        $endOfTwoDays = now()->addDays(2)->endOfDay();

        $rentalsToRemind = Rental::where('status', RentalStatus::RENTED->value)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$twoDaysFromNow, $endOfTwoDays])
            ->whereNull('reminder_sent_at') // Haven't sent a reminder yet
            ->get();

        $reminderCount = 0;

        foreach ($rentalsToRemind as $rental) {
            $rental->customer->notify(new RentalExpiringSoonNotification($rental));

            // Mark that we've sent the reminder
            $rental->reminder_sent_at = now();
            $rental->save();
            
            $reminderCount++;
        }

        $this->info("{$reminderCount} reminders sent.");

        return Command::SUCCESS;
    }
}
```

The `reminder_sent_at` column ensures idempotency - even if the job runs multiple times on the same day, each rental only gets one reminder.

## Scheduling the Commands

In Laravel 12, scheduled tasks are defined in `routes/console.php` rather than the `Kernel` class:

```php
<?php

use Illuminate\Support\Facades\Schedule;

// Check for expiring rentals every hour
Schedule::command('rentals:send-reminders')->hourly();
Schedule::command('rentals:expire')->hourly();
```

Hourly runs ensure you catch expirations within a reasonable window. For most business applications, checking every hour is frequent enough without being wasteful.

## The Cron Entry

On your server, add one cron entry:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This runs every minute, but Laravel's scheduler only executes commands when their scheduled time matches. The `hourly()` commands run at the top of each hour.

## Displaying Time Remaining

Show users how long they have until expiration:

```php
// Rental.php
public function getDaysUntilExpiry(): int
{
    if (!$this->expires_at) {
        return 0;
    }

    $daysRemaining = now()->diffInDays($this->expires_at, false);
    return max(0, $daysRemaining);
}
```

In Blade:

```blade
@if($rental->status == RentalStatus::RENTED->value && $rental->expires_at)
    <p class="text-sm text-orange-600">
        Due back in {{ $rental->getDaysUntilExpiry() }} days
    </p>
@endif
```

## Setting Timestamps When Status Changes

When a rental's status changes, set the appropriate timestamps:

```php
// In your controller or service
public function markAsRented(Rental $rental, int $days = 14): void
{
    $rental->status = RentalStatus::RENTED->value;
    $rental->rented_at = now();
    $rental->expires_at = now()->addDays($days);
    $rental->reminder_sent_at = null; // Reset reminder flag
    $rental->save();

    // Still log the status change for audit purposes
    activity()
        ->performedOn($rental)
        ->log("Rental marked as rented, expires {$rental->expires_at->format('Y-m-d')}");
}
```

This keeps your audit trail intact while using explicit timestamps for business logic.

## Testing Scheduled Commands

Test your commands with specific dates:

```php
use App\Enums\RentalStatus;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks rentals as overdue after expiration date', function () {
    $rental = Rental::factory()->create([
        'status' => RentalStatus::RENTED->value,
        'expires_at' => now()->subDay(), // Expired yesterday
    ]);

    $this->artisan('rentals:expire')->assertSuccessful();

    $rental->refresh();
    expect($rental->status)->toBe(RentalStatus::OVERDUE->value);
});

it('does not expire rentals before expiration date', function () {
    $rental = Rental::factory()->create([
        'status' => RentalStatus::RENTED->value,
        'expires_at' => now()->addDay(), // Expires tomorrow
    ]);

    $this->artisan('rentals:expire')->assertSuccessful();

    $rental->refresh();
    expect($rental->status)->toBe(RentalStatus::RENTED->value);
});

it('sends reminders for rentals expiring in 2 days', function () {
    $rental = Rental::factory()->create([
        'status' => RentalStatus::RENTED->value,
        'expires_at' => now()->addDays(2),
        'reminder_sent_at' => null,
    ]);

    Notification::fake();

    $this->artisan('rentals:send-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $rental->customer,
        RentalExpiringSoonNotification::class
    );

    $rental->refresh();
    expect($rental->reminder_sent_at)->not->toBeNull();
});

it('does not send duplicate reminders', function () {
    $rental = Rental::factory()->create([
        'status' => RentalStatus::RENTED->value,
        'expires_at' => now()->addDays(2),
        'reminder_sent_at' => now()->subHour(), // Already reminded
    ]);

    Notification::fake();

    $this->artisan('rentals:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});
```

## Monitoring

Add logging to track job execution:

```php
public function handle(): int
{
    \Log::info('Starting rental expiration check');
    
    $overdueRentals = Rental::where('status', RentalStatus::RENTED->value)
        ->whereNotNull('expires_at')
        ->whereDate('expires_at', '<=', now())
        ->get();

    $expiredCount = 0;
    
    foreach ($overdueRentals as $rental) {
        // ... expiration logic ...
        $expiredCount++;
    }
    
    \Log::info("Expired {$expiredCount} rentals");
    
    return Command::SUCCESS;
}
```

Consider using Laravel's failed job handling for critical scheduled tasks:

```php
Schedule::command('rentals:expire')
    ->hourly()
    ->onFailure(function () {
        // Alert ops team
        \Log::error('Rental expiration job failed');
    });
```

## Why Not Use Activity Logs?

You might wonder: if you're already logging status changes, why not use those logs to derive timing?

**Activity logs are for observability, not business logic.** They answer "what happened and when?" for debugging and auditing. But using them as the source of truth for business rules creates several problems:

1. **Fragile string matching:** If log message format changes, your scheduled jobs silently break
2. **Performance degradation:** Full-text searches on log content don't scale and can't be efficiently indexed
3. **Unclear intent:** Business rules should be explicit in your schema, not implicit in log messages
4. **Missed runs:** If a scheduled job misses a day, exact day matching (`== 14`) fails

**Use logs for auditing, timestamps for business logic.** You can still log status changes for audit purposes, but use dedicated columns for time-based business rules.

## Conclusion

Scheduled jobs turn time-based business rules into code. Instead of relying on users to remember deadlines, the system enforces them automatically.

The pattern is straightforward: use dedicated timestamp columns to track when business events occur, then query for items that meet your time-based criteria. This approach is robust, scalable, and makes your business logic explicit.

Start with your most important time-based rules - expiration, reminders, cleanup - and add more as needed. Laravel's scheduler makes it easy to define when jobs run, and Artisan commands make them testable and debuggable.

Your users will appreciate the proactive communication, and your business rules will be enforced consistently, 24/7.
