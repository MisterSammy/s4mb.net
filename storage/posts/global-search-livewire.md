---
title: "Building a Global Search Component with Livewire"
date: 2025-01-09
excerpt: "A configurable search component that queries multiple models and returns unified results. No JavaScript required."
tags: [laravel, livewire, search, components]
slug: global-search-livewire
---

Users expect search to work everywhere. Type a name, find a client. Type a project title, find the project. They don't want to navigate to specific pages first—they want a universal search box.

Building this with traditional request/response requires page reloads or a separate JavaScript frontend. With Livewire, you get real-time search with zero JavaScript configuration.

## The Component

```php
<?php

namespace App\Http\Livewire;

use App\Models\Client;
use App\Models\Instruction;
use Livewire\Component;
use Illuminate\Support\Str;

class GlobalSearch extends Component
{
    public string $search = '';
    public array $results = [];
    public array $searchable = [];

    protected array $rules = [
        'search' => 'required|min:3',
    ];

    public function mount()
    {
        // Configure which models to search and which fields
        $this->searchable = [
            Client::class => ['company_name'],
            Instruction::class => ['title'],
        ];
    }

    public function updatedSearch()
    {
        $this->reset('results');
        
        // Don't search until we have 3 characters
        $this->validateOnly('search');
        
        $this->getSearchResults();
    }

    public function resetForm()
    {
        $this->reset(['search', 'results']);
    }

    public function getSearchResults()
    {
        foreach ($this->searchable as $model => $columns) {
            // Create a readable key from the model name
            $modelKey = Str::camel(class_basename($model));  // "Client" → "client"

            $query = (new $model())->query();

            // Search each configured column
            foreach ($columns as $column) {
                $query->orWhere($column, 'LIKE', '%' . $this->search . '%');
            }

            $queryResults = $query->take(5)->get();

            if ($queryResults->isNotEmpty()) {
                $this->results[$modelKey] = $queryResults->map(function ($resource) use ($columns) {
                    // Build the result object
                    $fields = [];
                    foreach ($columns as $field) {
                        $fieldKey = Str::ucfirst($field);  // "company_name" → "Company_name"
                        $fields[$fieldKey] = $resource->{$field};
                    }

                    // Generate the link
                    $routeKey = Str::plural(Str::kebab(class_basename($resource)));  // "Client" → "clients"

                    return [
                        'linkTo' => route($routeKey . '.show', [$resource->uuid]),
                        'fields' => $fields,
                    ];
                });
            }
        }
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
```

## The Template

```blade
<div class="relative" x-data="{ open: @entangle('search').length > 0 }">
    {{-- Search Input --}}
    <div class="relative">
        <input 
            type="text" 
            wire:model.debounce.300ms="search"
            placeholder="Search clients, instructions..."
            class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
        >
        <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        
        @if($search)
            <button 
                wire:click="resetForm" 
                class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        @endif
    </div>

    {{-- Results Dropdown --}}
    @if(count($results) > 0)
        <div class="absolute z-50 w-full mt-2 bg-white rounded-lg shadow-lg border max-h-96 overflow-y-auto">
            @foreach($results as $modelName => $items)
                <div class="p-2">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase px-2 mb-1">
                        {{ Str::title(Str::snake($modelName, ' ')) }}
                    </h4>
                    
                    @foreach($items as $item)
                        <a 
                            href="{{ $item['linkTo'] }}" 
                            class="block px-2 py-2 hover:bg-gray-100 rounded"
                        >
                            @foreach($item['fields'] as $fieldName => $fieldValue)
                                <span class="text-gray-900">{{ $fieldValue }}</span>
                            @endforeach
                        </a>
                    @endforeach
                </div>
                
                @if(!$loop->last)
                    <hr class="my-1">
                @endif
            @endforeach
        </div>
    @elseif(strlen($search) >= 3)
        <div class="absolute z-50 w-full mt-2 bg-white rounded-lg shadow-lg border p-4">
            <p class="text-gray-500 text-center">No results found</p>
        </div>
    @endif
</div>
```

## Key Features

**Debounced input.** The `wire:model.debounce.300ms` directive waits 300ms after the user stops typing before triggering a search. This prevents hammering the database on every keystroke.

**Minimum character requirement.** Searches only run with 3+ characters. This avoids overly broad queries.

**Configurable models.** The `$searchable` array defines what gets searched. Adding a new model is one line:

```php
$this->searchable = [
    Client::class => ['company_name'],
    Instruction::class => ['title'],
    Project::class => ['name', 'description'],  // Search multiple fields
];
```

**Limited results.** `take(5)` prevents overwhelming the UI. For a global search, you want quick results, not exhaustive lists.

**Clean reset.** The X button clears both the input and results.

## Using the Component

Drop it in your navigation:

```blade
<nav class="flex items-center gap-4">
    <div class="w-64">
        <livewire:global-search />
    </div>
    {{-- Other nav items --}}
</nav>
```

Or add keyboard shortcuts with Alpine.js:

```blade
<div 
    x-data="{ showSearch: false }"
    @keydown.window.cmd.k.prevent="showSearch = true"
    @keydown.window.ctrl.k.prevent="showSearch = true"
    @keydown.escape="showSearch = false"
>
    <div x-show="showSearch" x-cloak class="fixed inset-0 z-50 flex items-start justify-center pt-20 bg-black/50">
        <div class="w-full max-w-lg bg-white rounded-lg shadow-xl" @click.away="showSearch = false">
            <livewire:global-search />
        </div>
    </div>
</div>
```

Now Cmd+K (Mac) or Ctrl+K (Windows) opens a spotlight-style search modal.

## Extending the Search

**Add scoping.** Only search records the user can access:

```php
$query = (new $model())->query();

// Scope to user's teams
if ($model === Client::class) {
    $query->whereIn('team_id', auth()->user()->allTeams()->pluck('id'));
}
```

**Include metadata.** Show more context in results:

```php
return [
    'linkTo' => route($routeKey . '.show', [$resource->uuid]),
    'fields' => $fields,
    'subtitle' => $resource->created_at->diffForHumans(),
    'badge' => $resource->status ?? null,
];
```

**Highlight matches.** Mark the matching text:

```php
$highlightedValue = preg_replace(
    '/(' . preg_quote($this->search, '/') . ')/i',
    '<mark>$1</mark>',
    $fieldValue
);
```

Global search is one of those features that seems complex but becomes simple with Livewire. A single component, real-time results, no JavaScript to maintain.

