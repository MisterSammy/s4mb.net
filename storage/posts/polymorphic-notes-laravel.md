---
title: "Polymorphic Notes: One Table for Comments Across Multiple Models"
date: 2025-01-09
excerpt: "Instead of creating separate note tables for properties, tenants, and work orders, use Laravel's polymorphic relationships to build one flexible notes system."
tags: [laravel, eloquent, polymorphic, database-design]
slug: polymorphic-notes-laravel
---

Every application eventually needs a notes feature. Properties need notes. Tenants need notes. Work orders need notes. The instinct is to create separate tables: `property_notes`, `tenant_notes`, `work_order_notes`.

Don't do that.

Laravel's polymorphic relationships let you create one `notes` table that can attach to any model. One migration, one model class, one set of queries to maintain. When you add a new noteable model next month, you just add a relationship - no schema changes.

## The Scenario: Property Management

A property management company tracks various entities:
- **Properties**  -  Buildings and units under management
- **Tenants**  -  Current and past occupants
- **Work Orders**  -  Maintenance requests and repairs
- **Leases**  -  Active rental agreements

Staff need to add notes to all of these. A polymorphic notes system lets them do that with one unified feature.

## The Schema

A polymorphic table needs two columns to identify what it's attached to:

```php
Schema::create('notes', function (Blueprint $table) {
    $table->id();
    $table->morphs('noteable');  // Creates noteable_type and noteable_id
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->text('content');
    $table->boolean('is_private')->default(false);  // Staff-only notes
    $table->timestamps();
    $table->softDeletes();
    
    // Index for faster queries
    $table->index(['noteable_type', 'noteable_id', 'created_at']);
});
```

The `morphs()` helper creates:
- `noteable_type` .  The class name of the parent model (`App\Models\Property`)
- `noteable_id`  -  The ID of the specific record

Together they form a "pointer" to any model in your system.

## The Note Model

```php
<?php

namespace App\Models;

use App\Events\Note\NoteCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'noteable_id',
        'noteable_type',
        'user_id',
        'content',
        'is_private',
    ];

    protected $dispatchesEvents = [
        'created' => NoteCreated::class,
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
        ];
    }

    /**
     * Get the parent model (property, tenant, work order, etc.)
     */
    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getCreatedAtDate(): string
    {
        return $this->created_at->format('M j, Y g:i A');
    }
}
```

The `morphTo()` relationship is the inverse of the polymorphic relationship. It lets you access the parent model without knowing what type it is:

```php
$note = Note::find(1);
$parent = $note->noteable;  // Could be a Property, Tenant, WorkOrder, anything
```

## Adding Notes to Models

On each model that can have notes, add the inverse relationship:

```php
// Property.php
public function notes(): MorphMany
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderByDesc('created_at');
}

// Tenant.php
public function notes(): MorphMany
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderByDesc('created_at');
}

// WorkOrder.php
public function notes(): MorphMany
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderByDesc('created_at');
}

// Lease.php
public function notes(): MorphMany
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderByDesc('created_at');
}
```

The `morphMany()` method takes the Note class and the relationship name (`noteable`). Laravel knows to look for `noteable_type` and `noteable_id` columns.

For DRY code, you can extract this into a trait:

```php
// HasNotes.php
trait HasNotes
{
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')
            ->orderByDesc('created_at');
    }
}

// Then on your models:
class Property extends Model
{
    use HasNotes;
}
```

## Creating Notes

Creating a note is straightforward:

```php
// Via the relationship (preferred)
$property->notes()->create([
    'user_id' => auth()->id(),
    'content' => 'Inspected unit 4B. Minor water damage near kitchen window.',
    'is_private' => true,
]);

// Or directly
Note::create([
    'noteable_type' => Property::class,
    'noteable_id' => $property->id,
    'user_id' => auth()->id(),
    'content' => 'Inspected unit 4B. Minor water damage near kitchen window.',
]);
```

The relationship approach is cleaner - you don't need to specify the type and ID manually.

## Querying Notes

Get all notes for a model:

```php
$property->notes;  // Collection of Note models
```

Get notes with authors eager-loaded:

```php
$property->load('notes.author');

foreach ($property->notes as $note) {
    echo "{$note->author->name}: {$note->content}";
}
```

Get only visible notes (filter out private notes for non-staff):

```php
$property->notes()
    ->when(!auth()->user()->isStaff(), fn ($q) => $q->where('is_private', false))
    ->with('author')
    ->get();
```

Find all notes by a specific user across all models:

```php
Note::where('user_id', $userId)->with('noteable')->get();
```

Find all notes for properties specifically:

```php
Note::where('noteable_type', Property::class)->get();
```

## Displaying Notes

In Blade, a simple notes list:

```blade
<div class="space-y-4">
    @forelse($property->notes as $note)
        <div class="bg-gray-50 p-4 rounded-lg">
            <div class="flex justify-between items-start">
                <div class="flex items-center gap-2">
                    <span class="font-medium">{{ $note->author->name }}</span>
                    @if($note->is_private)
                        <span class="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded">
                            Staff Only
                        </span>
                    @endif
                </div>
                <span class="text-sm text-gray-500">{{ $note->getCreatedAtDate() }}</span>
            </div>
            <p class="mt-2 text-gray-700 whitespace-pre-wrap">{{ $note->content }}</p>
        </div>
    @empty
        <p class="text-gray-500 text-center py-4">No notes yet.</p>
    @endforelse
</div>
```

## A Livewire Component for Creating Notes

```php
<?php

namespace App\Livewire\Notes;

use App\Models\Note;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CreateNote extends Component
{
    public string $noteableType;
    public int $noteableId;
    public string $content = '';
    public bool $isPrivate = false;

    public function mount(string $noteableType, int $noteableId): void
    {
        $this->noteableType = $noteableType;
        $this->noteableId = $noteableId;
    }

    public function save(): void
    {
        $this->validate([
            'content' => 'required|min:3|max:5000',
        ]);

        if (!Auth::user()->hasPermission('note:create')) {
            abort(403);
        }

        Note::create([
            'noteable_type' => $this->noteableType,
            'noteable_id' => $this->noteableId,
            'user_id' => Auth::id(),
            'content' => $this->content,
            'is_private' => $this->isPrivate,
        ]);

        $this->reset(['content', 'isPrivate']);
        $this->dispatch('note-created');
    }

    public function render()
    {
        return view('livewire.notes.create-note');
    }
}
```

The template:

```blade
<div>
    <form wire:submit="save">
        <textarea
            wire:model="content"
            rows="3"
            placeholder="Add a note..."
            class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500"
        ></textarea>
        
        @error('content')
            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
        @enderror

        <div class="flex justify-between items-center mt-3">
            @if(auth()->user()->isStaff())
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="isPrivate" class="rounded">
                    <span>Staff only</span>
                </label>
            @else
                <div></div>
            @endif

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Add Note
            </button>
        </div>
    </form>
</div>
```

Use it on any page:

```blade
<livewire:notes.create-note 
    :noteable-type="App\Models\Property::class" 
    :noteable-id="$property->id" 
/>
```

## Events and Notifications

Since all notes flow through one model, you can attach events and notifications centrally:

```php
// Note.php
protected $dispatchesEvents = [
    'created' => NoteCreated::class,
];

// NoteCreatedListener.php
public function handle(NoteCreated $event): void
{
    $note = $event->note;
    $parent = $note->noteable;
    
    // Skip private notes for tenant notifications
    if ($note->is_private) {
        return;
    }
    
    // Notify relevant people based on the parent type
    $recipients = match ($parent::class) {
        Property::class => $parent->propertyManager,
        Tenant::class => $parent->assignedAgent,
        WorkOrder::class => $parent->assignedTechnician,
        default => null,
    };
    
    if ($recipients) {
        Notification::send(
            $recipients,
            new NoteCreatedNotification($note)
        );
    }
}
```

## When to Use Polymorphic Relationships

This pattern works well when:

- Multiple models need the same type of related data
- The related data has the same structure regardless of parent
- You want centralized handling (events, notifications, queries)

Consider separate tables when:

- Different parents need different note fields
- You need foreign key constraints (polymorphic relationships can't use them)
- Query performance is critical and you need targeted indexes

For most notes/comments features, polymorphic relationships are the cleaner choice. The trade-off (no foreign key constraints) is usually worth the simplicity of a single notes system.
