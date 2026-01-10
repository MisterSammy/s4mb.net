---
title: "Building a Global Search Component with Livewire"
date: 2025-01-09
excerpt: "Quick and dirty MySQL search with Livewire. Copy it, ship it, move on. For production, use Typesense."
tags: [laravel, livewire, search, components]
slug: global-search-livewire
---

Sometimes you just need search to work. Not "proper" search with fancy indexing and typo tolerance - just a text box that finds stuff. You've got a deadline, a demo tomorrow, or a client who swears they'll "add real search later."

This is that search. Quick, dirty, and MySQL-powered. Copy it, ship it, move on with your life.

> **For production apps:** This MySQL approach works for small datasets. For anything serious, use [Typesense](https://typesense.org/). It's open-source, blazing fast, typo-tolerant, and integrates with Laravel Scout in minutes.

## The Component

```php
<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Models\Project;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $search = '';
    public array $results = [];

    public function updatedSearch(): void
    {
        if (strlen($this->search) < 3) {
            $this->results = [];
            return;
        }

        $this->results = [
            'customers' => Customer::where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->take(5)
                ->get(),
            'projects' => Project::where('title', 'like', "%{$this->search}%")
                ->take(5)
                ->get(),
        ];
    }

    public function resetForm(): void
    {
        $this->reset(['search', 'results']);
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
```

## The Template

```blade
<div class="relative">
    {{-- Search Input --}}
    <div class="relative">
        <input 
            type="text" 
            wire:model.live.debounce.300ms="search"
            placeholder="Search customers, projects..."
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
            @if(isset($results['customers']) && $results['customers']->isNotEmpty())
                <div class="p-2">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase px-2 mb-1">Customers</h4>
                    @foreach($results['customers'] as $customer)
                        <a 
                            href="{{ route('customers.show', $customer) }}" 
                            class="block px-2 py-2 hover:bg-gray-100 rounded"
                        >
                            {{ $customer->name }} · {{ $customer->email }}
                        </a>
                    @endforeach
                </div>
            @endif

            @if(isset($results['projects']) && $results['projects']->isNotEmpty())
                @if(isset($results['customers']) && $results['customers']->isNotEmpty())
                    <hr class="my-1">
                @endif
                <div class="p-2">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase px-2 mb-1">Projects</h4>
                    @foreach($results['projects'] as $project)
                        <a 
                            href="{{ route('projects.show', $project) }}" 
                            class="block px-2 py-2 hover:bg-gray-100 rounded"
                        >
                            {{ $project->title }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif(strlen($search) >= 3)
        <div class="absolute z-50 w-full mt-2 bg-white rounded-lg shadow-lg border p-4">
            <p class="text-gray-500 text-center">No results found</p>
        </div>
    @endif
</div>
```

## Key Features

**Debounced input.** The `wire:model.live.debounce.300ms` directive waits 300ms after the user stops typing before triggering a search. This prevents hammering the database on every keystroke.

**Minimum character requirement.** Searches only run with 3+ characters. This avoids overly broad queries.

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
'customers' => Customer::where('team_id', auth()->user()->team_id)
    ->where('name', 'like', "%{$this->search}%")
    ->take(5)
    ->get(),
```

**Add more models.** Just add another array key:

```php
'invoices' => Invoice::where('reference_number', 'like', "%{$this->search}%")
    ->take(5)
    ->get(),
```

## Testing

```php
use App\Livewire\GlobalSearch;
use App\Models\Customer;
use Livewire\Livewire;

it('searches customers by name', function () {
    Customer::factory()->create(['name' => 'Acme Corporation']);
    Customer::factory()->create(['name' => 'Beta Industries']);

    Livewire::test(GlobalSearch::class)
        ->set('search', 'Acme')
        ->assertSet('results.customers', fn ($results) => 
            $results->contains(fn ($c) => $c->name === 'Acme Corporation')
        );
});

it('requires minimum 3 characters', function () {
    Customer::factory()->create(['name' => 'Acme Corporation']);

    Livewire::test(GlobalSearch::class)
        ->set('search', 'Ac')
        ->assertSet('results', []);
});
```

That's it. A quick MySQL search that works for small datasets. When you outgrow it, switch to Typesense and Laravel Scout. But for now? Copy, paste, ship.
