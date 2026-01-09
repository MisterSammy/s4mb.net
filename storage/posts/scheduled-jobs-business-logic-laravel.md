---
title: "Scheduled Jobs for Business Logic: Automatic Expiration and Reminder Systems"
date: 2025-01-09
excerpt: "Not everything happens on user interaction. Here's how to build scheduled jobs that enforce business rules like document expiration and reminder emails."
tags: [laravel, scheduling, jobs, automation, business-logic]
slug: scheduled-jobs-business-logic-laravel
---

Some business rules can't wait for user interaction. Documents expire after 14 days. Reminders need to go out 2 days before deadlines. Inactive accounts should be flagged automatically.

Laravel's task scheduler handles these time-based rules elegantly. Instead of building a separate cron infrastructure, you define scheduled tasks in PHP and let a single cron entry run them.

This post walks through two related scheduled jobs: one that sends reminders before expiration, and one that actually expires documents when the deadline passes.

## The Business Rule

Consider this workflow: when a document is sent to a client for review, they have 14 days to respond. If they don't:

1. At day 12, send a reminder that expiration is approaching
2. At day 14, mark the document as expired and notify relevant parties

Both actions happen automatically, without any user triggering them.

## Tracking When States Changed

The challenge is knowing when the 14-day clock started. If you only store the current status, you don't know when it changed.

One solution: use your activity log. If you're logging status changes (which you should be for audit purposes), you can query the log to find when a document entered the "pending review" state:

```php
use App\Models\Log;
use Illuminate\Support\Carbon;

$log = Log::where('loggable_id', $instruction->id)
    ->where('loggable_type', 'App\\Models\\Instruction')
    ->where('content', 'The instruction status changed to Instruction Form (Client Review)')
    ->latest()
    ->first();

if ($log) {
    $daysSinceStatusChange = Carbon::parse($log->created_at)->diffInDays(Carbon::now());
}
```

This approach has a nice property: the log is your source of truth for timing, and it already exists for audit purposes.

## The Expiration Command

Create an Artisan command that finds and expires overdue documents:

```php
<?php

namespace App\Console\Commands;

use App\Enums\InstructionStatus;
use App\Models\Instruction;
use App\Models\Log;
use App\Notifications\Instruction\InstructionExpiredNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class ExpireInstructions extends Command
{
    protected $signature = 'expire_instructions';
    protected $description = 'Finds instructions pending for 14+ days and sets them to expired.';

    public function handle()
    {
        // Find all instructions waiting for client review
        // Exclude old migrated data (created before the system went live)
        $instructionsToCheck = Instruction::where('status', InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value)
            ->where('created_at', '>', '2022-12-01')
            ->get();

        $expiredCount = 0;

        foreach ($instructionsToCheck as $instruction) {
            // Find when this instruction entered client review status
            $log = Log::where('loggable_id', $instruction->id)
                ->where('loggable_type', 'App\\Models\\Instruction')
                ->where('content', 'The instruction status changed to Instruction Form (Client Review)')
                ->latest()
                ->first();

            if (!$log) {
                continue; // No log entry found, skip
            }

            $daysSinceReview = Carbon::parse($log->created_at)->diffInDays(Carbon::now());

            // Exactly 14 days? Time to expire.
            if ($daysSinceReview == 14) {
                // Notify account holders
                foreach ($instruction->client->team->users as $user) {
                    if ($user->getRole() == 'account-holder') {
                        $user->notify(new InstructionExpiredNotification($instruction));
                    }
                }

                // Update status
                $instruction->status = InstructionStatus::EXPIRED->value;
                $instruction->save();
                
                $expiredCount++;
            }
        }

        $this->info("{$expiredCount} instructions expired.");

        return Command::SUCCESS;
    }
}
```

A few things to note:

**Exact day matching.** We check for `== 14`, not `>= 14`. This prevents re-processing documents on day 15, 16, etc. The job runs hourly, so it will catch documents on their expiration day.

**Filtering old data.** The `created_at` filter excludes data migrated from a previous system. Those records don't have proper log entries for timing.

**User-specific notifications.** Only account holders get the expiration notice—they're the ones who need to take action.

## The Reminder Command

A similar command sends warnings before expiration:

```php
<?php

namespace App\Console\Commands;

use App\Enums\InstructionStatus;
use App\Models\Instruction;
use App\Models\Log;
use App\Notifications\Instruction\InstructionAboutToExpireNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class GenerateInstructionExpiryWarnings extends Command
{
    protected $signature = 'generate_instruction_expiry_warnings';
    protected $description = 'Sends reminder emails for instructions expiring in 2 days.';

    public function handle()
    {
        // Find instructions pending review that haven't been reminded yet
        $instructionsToCheck = Instruction::where('status', InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value)
            ->where('created_at', '>', '2022-12-01')
            ->whereNull('reminder_sent_at')  // Haven't sent a reminder yet
            ->get();

        $reminderCount = 0;

        foreach ($instructionsToCheck as $instruction) {
            $log = Log::where('loggable_id', $instruction->id)
                ->where('loggable_type', 'App\\Models\\Instruction')
                ->where('content', 'The instruction status changed to Instruction Form (Client Review)')
                ->latest()
                ->first();

            if (!$log) {
                continue;
            }

            $daysSinceReview = Carbon::parse($log->created_at)->diffInDays(Carbon::now());

            // Day 12 = 2 days before expiration
            if ($daysSinceReview == 12) {
                foreach ($instruction->client->team->users as $user) {
                    if ($user->getRole() == 'account-holder') {
                        $user->notify(new InstructionAboutToExpireNotification($instruction));
                    }
                }

                // Mark that we've sent the reminder
                $instruction->reminder_sent_at = Carbon::now();
                $instruction->save();
                
                $reminderCount++;
            }
        }

        $this->info("{$reminderCount} expiry warnings sent.");

        return Command::SUCCESS;
    }
}
```

The key addition here is `reminder_sent_at`. This prevents sending multiple reminders if the job runs again before the document expires. Once reminded, the instruction won't appear in subsequent queries.

## Scheduling the Commands

Register both commands in your console kernel:

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Check for expiring instructions every hour
        $schedule->command('generate_instruction_expiry_warnings')->hourly();
        $schedule->command('expire_instructions')->hourly();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
```

Hourly runs ensure you catch expirations within a reasonable window. For most business applications, checking every hour is frequent enough without being wasteful.

## The Cron Entry

On your server, add one cron entry:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This runs every minute, but Laravel's scheduler only executes commands when their scheduled time matches. The `hourly()` commands run at the top of each hour.

## Displaying Time Remaining

Show users how long they have:

```php
// Instruction.php
public function getDaysUntilExpiry(): string
{
    $log = Log::where('loggable_id', $this->id)
        ->where('loggable_type', 'App\\Models\\Instruction')
        ->where('content', 'The instruction status changed to Instruction Form (Client Review)')
        ->latest()
        ->first();

    if (!$log) {
        return '';
    }

    $expiryDate = Carbon::parse($log->created_at)->addDays(14);
    $daysRemaining = Carbon::now()->diffInDays($expiryDate, false);

    return max(0, $daysRemaining);
}
```

In Blade:

```blade
@if($instruction->status == InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value)
    <p class="text-sm text-orange-600">
        Expires in {{ $instruction->getDaysUntilExpiry() }} days
    </p>
@endif
```

## Alternative: Dedicated Timestamp Columns

If querying logs feels too indirect, add explicit timestamp columns:

```php
// Migration
$table->timestamp('sent_to_client_at')->nullable();
$table->timestamp('reminder_sent_at')->nullable();
$table->timestamp('expires_at')->nullable();
```

Set them when the status changes:

```php
$instruction->status = InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value;
$instruction->sent_to_client_at = now();
$instruction->expires_at = now()->addDays(14);
$instruction->save();
```

Query directly:

```php
$expiringToday = Instruction::whereDate('expires_at', today())->get();
```

This approach is more explicit but requires remembering to set the timestamps whenever status changes. The log-based approach derives timing from existing audit data.

## Testing Scheduled Commands

Test your commands with specific dates:

```php
public function test_instructions_expire_after_14_days()
{
    // Create an instruction that's been pending for 14 days
    $instruction = Instruction::factory()->create([
        'status' => InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value,
    ]);
    
    Log::create([
        'loggable_id' => $instruction->id,
        'loggable_type' => 'App\\Models\\Instruction',
        'content' => 'The instruction status changed to Instruction Form (Client Review)',
        'created_at' => now()->subDays(14),
        'user_id' => 1,
    ]);

    // Run the expiration command
    $this->artisan('expire_instructions');

    // Verify it expired
    $instruction->refresh();
    $this->assertEquals(InstructionStatus::EXPIRED->value, $instruction->status);
}

public function test_instructions_not_expired_before_14_days()
{
    $instruction = Instruction::factory()->create([
        'status' => InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value,
    ]);
    
    Log::create([
        'loggable_id' => $instruction->id,
        'loggable_type' => 'App\\Models\\Instruction',
        'content' => 'The instruction status changed to Instruction Form (Client Review)',
        'created_at' => now()->subDays(13),  // Only 13 days
        'user_id' => 1,
    ]);

    $this->artisan('expire_instructions');

    $instruction->refresh();
    $this->assertEquals(InstructionStatus::INSTRUCTION_FORM_CLIENT_REVIEW->value, $instruction->status);
}
```

## Monitoring

Add logging to track job execution:

```php
public function handle()
{
    Log::info('Starting instruction expiration check');
    
    // ... job logic ...
    
    Log::info("Expired {$expiredCount} instructions");
    
    return Command::SUCCESS;
}
```

Consider using Laravel's failed job handling for critical scheduled tasks:

```php
$schedule->command('expire_instructions')
    ->hourly()
    ->onFailure(function () {
        // Alert ops team
    });
```

## Conclusion

Scheduled jobs turn time-based business rules into code. Instead of relying on users to remember deadlines, the system enforces them automatically.

The pattern is straightforward: query for items in a target state, check if enough time has passed, take action if so. Whether you track timing via logs or dedicated columns, the logic remains the same.

Start with your most important time-based rules—expiration, reminders, cleanup—and add more as needed. Laravel's scheduler makes it easy to define when jobs run, and Artisan commands make them testable and debuggable.

Your users will appreciate the proactive communication, and your business rules will be enforced consistently, 24/7.

