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
- **Properties** - Buildings and units under management
- **Tenants** - Current and past occupants
- **Work Orders** - Maintenance requests and repairs
- **Leases** - Active rental agreements

Staff need to add notes to all of these. A polymorphic notes system lets them do that with one unified feature.

## The Schema

A polymorphic table needs two columns to identify what it's attached to:

```php
Schema::create('notes', function (Blueprint $table) {
    $table->id();
    $table->morphs('noteable');
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('content');
    $table->boolean('is_private')->default(false);
    $table->timestamps();
    $table->softDeletes();
});
```

The `morphs()` helper creates:
- `noteable_type` - The class name of the parent model (`App\Models\Property`)
- `noteable_id` - The ID of the specific record

Together they form a "pointer" to any model in your system.

## The Note Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use SoftDeletes;

    protected $fillable = ['noteable_id', 'noteable_type', 'user_id', 'content', 'is_private'];

    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

The `morphTo()` relationship is the inverse side. It lets you access the parent model without knowing what type it is:

```php
$note = Note::find(1);
$parent = $note->noteable;  // Could be Property, Tenant, WorkOrder, etc.
```

## Adding Notes to Models

Rather than repeating the same relationship on every model, extract it into a trait:

```php
trait HasNotes
{
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->latest();
    }
}
```

Then apply it to any model that needs notes:

```php
class Property extends Model
{
    use HasNotes;
}

class Tenant extends Model
{
    use HasNotes;
}

class WorkOrder extends Model
{
    use HasNotes;
}
```

The `morphMany()` method takes the Note class and the relationship name (`noteable`). Laravel looks for `noteable_type` and `noteable_id` columns automatically.

## Creating Notes

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
    'content' => 'Inspected unit 4B.',
]);
```

The relationship approach is cleaner - you don't need to specify the type and ID manually.

## Querying Notes

Get all notes for a model:

```php
$property->notes;
```

Get notes with authors eager-loaded:

```php
$property->load('notes.author');

foreach ($property->notes as $note) {
    echo "{$note->author->name}: {$note->content}";
}
```

Filter out private notes for non-staff users:

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

```blade
@forelse($property->notes as $note)
    <article>
        <header>
            <strong>{{ $note->author->name }}</strong>
            <time>{{ $note->created_at->diffForHumans() }}</time>
            @if($note->is_private)
                <span>Staff Only</span>
            @endif
        </header>
        <p>{{ $note->content }}</p>
    </article>
@empty
    <p>No notes yet.</p>
@endforelse
```

## A Livewire Component

```php
<?php

namespace App\Livewire\Notes;

use App\Models\Note;
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
        $this->validate(['content' => 'required|min:3|max:5000']);

        Note::create([
            'noteable_type' => $this->noteableType,
            'noteable_id' => $this->noteableId,
            'user_id' => auth()->id(),
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
<form wire:submit="save">
    <textarea wire:model="content" placeholder="Add a note..."></textarea>
    @error('content') <p>{{ $message }}</p> @enderror

    @if(auth()->user()->isStaff())
        <label>
            <input type="checkbox" wire:model="isPrivate"> Staff only
        </label>
    @endif

    <button type="submit">Add Note</button>
</form>
```

Use it on any page:

```blade
<livewire:notes.create-note 
    :noteable-type="App\Models\Property::class" 
    :noteable-id="$property->id" 
/>
```

## Events and Notifications

Since all notes flow through one model, you can handle events centrally:

```php
class NoteObserver
{
    public function created(Note $note): void
    {
        if ($note->is_private) {
            return;
        }

        $recipient = match ($note->noteable::class) {
            Property::class => $note->noteable->manager,
            Tenant::class => $note->noteable->agent,
            WorkOrder::class => $note->noteable->technician,
            default => null,
        };

        $recipient?->notify(new NoteAdded($note));
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
