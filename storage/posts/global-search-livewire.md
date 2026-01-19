---
title: "Building a Global Search Component with Livewire"
date: 2025-12-28
excerpt: "A practical MySQL-based global search component using Livewire. Suitable for prototypes and small datasets."
tags: [laravel, livewire, search, components]
slug: global-search-livewire
---

A straightforward global search component built with Livewire provides a functional search interface for your Laravel application. This implementation uses MySQL's LIKE queries, making it suitable for prototypes and small datasets.

> **For production applications:** This MySQL approach works well for small datasets. For larger scale applications, consider using [Typesense](https://typesense.org/). It's open-source, fast, typo-tolerant, and integrates with Laravel Scout.

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
<div>
    <input 
        type="text" 
        wire:model.live.debounce.300ms="search"
        placeholder="Search..."
    >

    @if(count($results) > 0)
        @foreach($results['customers'] ?? [] as $customer)
            <a href="{{ route('customers.show', $customer) }}">
                {{ $customer->name }}
            </a>
        @endforeach

        @foreach($results['projects'] ?? [] as $project)
            <a href="{{ route('projects.show', $project) }}">
                {{ $project->title }}
            </a>
        @endforeach
    @elseif(strlen($search) >= 3)
        <p>No results found</p>
    @endif
</div>
```

## Key Behaviors

- **Debounced input**: The `wire:model.live.debounce.300ms` directive waits 300ms after the user stops typing before triggering a search
- **Minimum character requirement**: Searches only run with 3+ characters to avoid overly broad queries
- **Limited results**: Each model query uses `take(5)` to keep results focused and manageable

## Using the Component

Include the component in your Blade templates:

```blade
<livewire:global-search />
```

This MySQL-based search solution is well-suited for small datasets and prototyping. For production applications with larger datasets or more complex search requirements, consider implementing Typesense with Laravel Scout for improved performance and typo tolerance.
