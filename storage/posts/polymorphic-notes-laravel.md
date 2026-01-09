---
title: "Polymorphic Notes: One Table for Comments Across Multiple Models"
date: 2025-01-09
excerpt: "Instead of creating separate note tables for clients, instructions, and projects, use Laravel's polymorphic relationships to build one flexible notes system."
tags: [laravel, eloquent, polymorphic, database-design]
slug: polymorphic-notes-laravel
---

Every application eventually needs a notes feature. Clients need notes. Projects need notes. Support tickets need notes. The instinct is to create separate tables: `client_notes`, `project_notes`, `ticket_notes`.

Don't do that.

Laravel's polymorphic relationships let you create one `notes` table that can attach to any model. One migration, one model class, one set of queries to maintain. When you add a new noteable model next month, you just add a relationship—no schema changes.

## The Schema

A polymorphic table needs two columns to identify what it's attached to:

```php
Schema::create('notes', function (Blueprint $table) {
    $table->id();
    $table->morphs('noteable');  // Creates noteable_type and noteable_id
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->text('content');
    $table->timestamps();
    $table->softDeletes();
});
```

The `morphs()` helper creates:
- `noteable_type` — The class name of the parent model (`App\Models\Client`)
- `noteable_id` — The ID of the specific record

Together they form a "pointer" to any model in your system.

## The Note Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Events\Note\NoteCreated;

class Note extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'noteable_id',
        'noteable_type',
        'user_id',
        'content'
    ];

    protected $dispatchesEvents = [
        'created' => NoteCreated::class,
    ];

    /**
     * Get the parent model (client, instruction, etc.)
     */
    public function noteable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this note
     */
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getCreatedAtDate()
    {
        return $this->created_at->format('d/m/Y H:i');
    }
}
```

The `morphTo()` relationship is the inverse of the polymorphic relationship. It lets you access the parent model without knowing what type it is:

```php
$note = Note::find(1);
$parent = $note->noteable;  // Could be a Client, Instruction, anything
```

## Adding Notes to Models

On each model that can have notes, add the inverse relationship:

```php
// Client.php
public function notes()
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderBy('created_at', 'desc');
}

// Instruction.php
public function notes()
{
    return $this->morphMany(Note::class, 'noteable')
        ->orderBy('created_at', 'desc');
}
```

The `morphMany()` method takes the Note class and the relationship name (`noteable`). Laravel knows to look for `noteable_type` and `noteable_id` columns.

## Creating Notes

Creating a note is straightforward:

```php
// Via the relationship
$client->notes()->create([
    'user_id' => auth()->id(),
    'content' => 'Called client to discuss contract terms.',
]);

// Or directly
Note::create([
    'noteable_type' => Client::class,
    'noteable_id' => $client->id,
    'user_id' => auth()->id(),
    'content' => 'Called client to discuss contract terms.',
]);
```

The relationship approach is cleaner—you don't need to specify the type and ID manually.

## Querying Notes

Get all notes for a model:

```php
$client->notes;  // Collection of Note models
```

Get notes with authors eager-loaded:

```php
$client->load('notes.author');

foreach ($client->notes as $note) {
    echo "{$note->author->name}: {$note->content}";
}
```

Find all notes by a specific user across all models:

```php
Note::where('user_id', $userId)->get();
```

Find all notes for clients specifically:

```php
Note::where('noteable_type', Client::class)->get();
```

## Displaying Notes

In Blade, a simple notes list:

```blade
<div class="space-y-4">
    @forelse($client->notes as $note)
        <div class="bg-gray-50 p-4 rounded">
            <div class="flex justify-between items-start">
                <span class="font-medium">{{ $note->author->name }}</span>
                <span class="text-sm text-gray-500">{{ $note->getCreatedAtDate() }}</span>
            </div>
            <p class="mt-2 text-gray-700">{{ $note->content }}</p>
        </div>
    @empty
        <p class="text-gray-500">No notes yet.</p>
    @endforelse
</div>
```

## A Livewire Component for Creating Notes

```php
<?php

namespace App\Http\Livewire\Notes;

use App\Models\Note;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Create extends Component
{
    public $noteableType;
    public $noteableId;
    public $content = '';

    public function mount($noteableType, $noteableId)
    {
        $this->noteableType = $noteableType;
        $this->noteableId = $noteableId;
    }

    public function save()
    {
        $this->validate([
            'content' => 'required|min:3',
        ]);

        if (!Auth::user()->hasPermission('note:create')) {
            abort(403);
        }

        Note::create([
            'noteable_type' => $this->noteableType,
            'noteable_id' => $this->noteableId,
            'user_id' => Auth::id(),
            'content' => $this->content,
        ]);

        $this->content = '';
        $this->emit('noteCreated');
    }

    public function render()
    {
        return view('livewire.notes.create');
    }
}
```

Use it on any page:

```blade
<livewire:notes.create 
    :noteable-type="App\Models\Client::class" 
    :noteable-id="$client->id" 
/>
```

## Events and Notifications

Since all notes flow through one model, you can attach events and notifications centrally:

```php
// Note.php
protected $dispatchesEvents = [
    'created' => NoteCreated::class,
];

// NoteCreated listener
public function handle($event)
{
    $note = $event->note;
    $parent = $note->noteable;
    
    // Notify team members when a note is added
    // Works regardless of what type of model the note is attached to
    Notification::send(
        User::whereShouldBeNotified($parent->team->id, 'Note Created'),
        new NoteCreatedNotification($note)
    );
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

For most notes/comments features, polymorphic relationships are the cleaner choice.

