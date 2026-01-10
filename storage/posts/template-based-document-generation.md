---
title: "Template-Based Document Generation in Laravel"
date: 2025-01-09
excerpt: "Pre-fill complex forms from templates so users customize rather than create from scratch. A pattern for proposals, packages, and contracts."
tags: [laravel, templates, forms, ux]
slug: template-based-document-generation
---

Service businesses produce the same types of documents repeatedly. Wedding planners send package proposals. Agencies create project quotes. Consultants deliver scoped proposals. The structure is consistent; only the details change.

Instead of making users fill out blank forms every time, let them select a template and customize it. The template provides sensible defaults for all the boilerplate. The user focuses on what's unique to this client.

## The Scenario: Wedding Planning Packages

A wedding planning business offers different service tiers:
- **Full Planning** — End-to-end coordination from engagement to wedding day
- **Partial Planning** — Specific aspects like venue selection or vendor management
- **Day-Of Coordination** — On-the-day logistics and timeline management

Each package has standard inclusions, exclusions, pricing, and terms. But every couple's wedding is unique, so planners need to customize.

## The Template Model

A template stores default values for all the fields a proposal might have:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageTemplate extends Model
{
    protected $fillable = [
        'name',                     // "Full Planning Package"
        'description',              // Overview for clients
        'included_services',        // What's included
        'excluded_services',        // What's not included
        'timeline',                 // Typical timeline
        'meetings_included',        // Number of planning meetings
        'vendor_coordination',      // Vendor management details
        'day_of_services',          // Wedding day specifics
        'pricing',                  // e.g., "$5,000 - $8,000"
        'pricing_type',             // "fixed", "percentage", "hourly"
        'pricing_notes',            // Additional pricing details
        'payment_schedule',         // Deposit and payment milestones
        'travel_policy',            // Travel/destination wedding terms
        'cancellation_policy',      // Cancellation terms
        'communication_expectations', // Response times, office hours
        'client_responsibilities',  // What the couple needs to do
        'liability_terms',          // Insurance and liability
        'additional_notes',         // Any other terms
    ];
}
```

This is a wide table—lots of text columns. That's fine. Templates are read frequently but written rarely. The simplicity of a single table outweighs normalization concerns.

## Seeding Templates

Pre-populate templates with your standard packages:

```php
PackageTemplate::create([
    'name' => 'Full Planning Package',
    'description' => 'Comprehensive wedding planning from engagement to honeymoon send-off. We handle every detail so you can enjoy the journey.',
    'included_services' => "Our full planning service includes:\n" .
        "- Unlimited planning meetings (in-person or virtual)\n" .
        "- Budget creation and management\n" .
        "- Venue scouting and selection\n" .
        "- Vendor research, recommendations, and coordination\n" .
        "- Design concept and styling guidance\n" .
        "- Timeline and logistics planning\n" .
        "- Guest management assistance\n" .
        "- Rehearsal coordination\n" .
        "- Full wedding day coordination (up to 12 hours)",
    'excluded_services' => "This package does not include:\n" .
        "- Vendor fees and costs (passed through at cost)\n" .
        "- Décor rentals and purchases\n" .
        "- Stationery design or printing\n" .
        "- Travel expenses for destination weddings",
    'pricing' => '$[AMOUNT] based on [X] guests',
    'pricing_type' => 'fixed',
    'payment_schedule' => "- 30% deposit to secure services\n" .
        "- 35% due 90 days before wedding\n" .
        "- 35% due 30 days before wedding",
    'cancellation_policy' => "Cancellation more than 6 months out: Deposit minus $500 administration fee\n" .
        "Cancellation 3-6 months out: 50% of total fee\n" .
        "Cancellation less than 3 months: No refund",
    // ... other fields
]);

PackageTemplate::create([
    'name' => 'Day-Of Coordination',
    'description' => "You've done the planning—we'll execute it flawlessly on your wedding day.",
    'included_services' => "Day-of coordination includes:\n" .
        "- Final planning meeting 4-6 weeks before wedding\n" .
        "- Vendor confirmation and timeline distribution\n" .
        "- Rehearsal coordination\n" .
        "- Up to 10 hours of wedding day coordination\n" .
        "- Setup and breakdown oversight\n" .
        "- Emergency kit and timeline management",
    'pricing' => '$[AMOUNT]',
    'pricing_type' => 'fixed',
    // ... other fields
]);
```

Notice the `[PLACEHOLDERS]`. Users see these and know to replace them. It's a simple convention that works.

## The Proposal Model

The actual proposal inherits template fields but can override any of them:

```php
// Proposal.php
protected $fillable = [
    'client_id',
    'user_id',
    'status',
    'title',
    'wedding_date',
    'venue_name',
    'guest_count',
    // All the template fields
    'description',
    'included_services',
    'excluded_services',
    'timeline',
    'meetings_included',
    'vendor_coordination',
    'day_of_services',
    'pricing',
    'pricing_type',
    'pricing_notes',
    'payment_schedule',
    'travel_policy',
    'cancellation_policy',
    'communication_expectations',
    'client_responsibilities',
    'liability_terms',
    'additional_notes',
];

public static function getTemplateRelatedFields(): array
{
    return [
        'description',
        'included_services',
        'excluded_services',
        'timeline',
        'meetings_included',
        'vendor_coordination',
        'day_of_services',
        'pricing',
        'pricing_type',
        'pricing_notes',
        'payment_schedule',
        'travel_policy',
        'cancellation_policy',
        'communication_expectations',
        'client_responsibilities',
        'liability_terms',
        'additional_notes',
    ];
}
```

The `getTemplateRelatedFields()` method lists which fields come from templates. Useful for building forms dynamically.

## Creating Proposals from Templates

In a Livewire wizard component:

```php
<?php

namespace App\Livewire\Proposals;

use App\Models\Client;
use App\Models\PackageTemplate;
use App\Models\Proposal;
use Livewire\Component;

class CreateWizard extends Component
{
    public $templates;
    public $templateId;
    public $title;
    public $clientId;
    public $weddingDate;
    public $guestCount;

    public function mount(): void
    {
        $this->templates = PackageTemplate::all();
    }

    public function save()
    {
        $this->validate([
            'templateId' => 'required|exists:package_templates,id',
            'title' => 'required|string|max:255',
            'clientId' => 'required|exists:clients,id',
            'weddingDate' => 'nullable|date|after:today',
            'guestCount' => 'nullable|integer|min:1',
        ]);

        $template = PackageTemplate::find($this->templateId);

        // Create proposal with template values
        $proposal = Proposal::create([
            'client_id' => $this->clientId,
            'user_id' => auth()->id(),
            'title' => $this->title,
            'wedding_date' => $this->weddingDate,
            'guest_count' => $this->guestCount,
            // Copy all template fields
            'description' => $template->description,
            'included_services' => $template->included_services,
            'excluded_services' => $template->excluded_services,
            'timeline' => $template->timeline,
            'meetings_included' => $template->meetings_included,
            'vendor_coordination' => $template->vendor_coordination,
            'day_of_services' => $template->day_of_services,
            'pricing' => $template->pricing,
            'pricing_type' => $template->pricing_type,
            'pricing_notes' => $template->pricing_notes,
            'payment_schedule' => $template->payment_schedule,
            'travel_policy' => $template->travel_policy,
            'cancellation_policy' => $template->cancellation_policy,
            'communication_expectations' => $template->communication_expectations,
            'client_responsibilities' => $template->client_responsibilities,
            'liability_terms' => $template->liability_terms,
            'additional_notes' => $template->additional_notes,
        ]);

        return redirect()->route('proposals.edit', $proposal);
    }

    public function render()
    {
        return view('livewire.proposals.create-wizard');
    }
}
```

The proposal is created with all template values. The user then reviews and customizes.

## The Editing Interface

After creation, present each field for review:

```blade
<form wire:submit="save">
    {{-- Client-specific fields --}}
    <div class="mb-6">
        <label class="block text-sm font-medium mb-2">Couple's Names</label>
        <input type="text" wire:model="title" class="w-full border rounded-lg p-3">
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div>
            <label class="block text-sm font-medium mb-2">Wedding Date</label>
            <input type="date" wire:model="weddingDate" class="w-full border rounded-lg p-3">
        </div>
        <div>
            <label class="block text-sm font-medium mb-2">Guest Count</label>
            <input type="number" wire:model="guestCount" class="w-full border rounded-lg p-3">
        </div>
    </div>

    {{-- Template fields --}}
    @foreach(App\Models\Proposal::getTemplateRelatedFields() as $field)
        <div class="mb-6">
            <label class="block text-sm font-medium mb-2">
                {{ Str::title(str_replace('_', ' ', $field)) }}
            </label>
            
            <textarea 
                wire:model="fields.{{ $field }}"
                rows="4"
                class="w-full border rounded-lg p-3"
            ></textarea>
            
            @if(str_contains($this->fields[$field] ?? '', '['))
                <p class="text-sm text-amber-600 mt-1">
                    ⚠️ Contains placeholders that need to be filled in
                </p>
            @endif
        </div>
    @endforeach

    <button type="submit" class="btn btn-primary">Save Changes</button>
</form>
```

Users see all the template content and edit what needs changing. Placeholders like `[AMOUNT]` prompt them for specific details.

## Managing Templates

Build an admin interface for template CRUD. Filament makes this straightforward:

```php
<?php

namespace App\Filament\Resources;

use App\Models\PackageTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PackageTemplateResource extends Resource
{
    protected static ?string $model = PackageTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Information')
                ->schema([
                    Forms\Components\TextInput::make('name')->required(),
                    Forms\Components\Select::make('pricing_type')
                        ->options([
                            'fixed' => 'Fixed Fee',
                            'percentage' => 'Percentage of Budget',
                            'hourly' => 'Hourly Rate',
                        ]),
                    Forms\Components\Textarea::make('description')->rows(3),
                ]),
                
            Forms\Components\Section::make('Services')
                ->schema([
                    Forms\Components\Textarea::make('included_services')->rows(8),
                    Forms\Components\Textarea::make('excluded_services')->rows(5),
                    Forms\Components\Textarea::make('day_of_services')->rows(5),
                ]),
                
            Forms\Components\Section::make('Pricing & Terms')
                ->schema([
                    Forms\Components\TextInput::make('pricing'),
                    Forms\Components\Textarea::make('payment_schedule')->rows(4),
                    Forms\Components\Textarea::make('cancellation_policy')->rows(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('pricing_type')->badge(),
            Tables\Columns\TextColumn::make('pricing'),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
        ]);
    }
}
```

## Dynamic Template Copying

For cleaner code, use a method that copies all template fields automatically:

```php
// Proposal.php
public static function createFromTemplate(PackageTemplate $template, array $attributes): self
{
    $templateFields = collect(self::getTemplateRelatedFields())
        ->mapWithKeys(fn ($field) => [$field => $template->$field])
        ->toArray();

    return self::create(array_merge($templateFields, $attributes));
}
```

Then in your Livewire component:

```php
$proposal = Proposal::createFromTemplate($template, [
    'client_id' => $this->clientId,
    'user_id' => auth()->id(),
    'title' => $this->title,
    'wedding_date' => $this->weddingDate,
    'guest_count' => $this->guestCount,
]);
```

## Benefits

**Consistency.** Every proposal starts from approved language. No one accidentally omits the cancellation policy.

**Efficiency.** Users fill in the blanks instead of writing from scratch. A 20-field form becomes 3-4 customizations.

**Maintainability.** Update a template, and all future proposals use the new language. (Existing proposals keep their values—templates are copied, not referenced.)

**Audit trail.** You can track which template a proposal started from and what was changed.

## When to Use This Pattern

Template-based generation works well for:

- Service proposals and quotes
- Contracts and agreements
- Project scopes of work
- Event packages
- Email templates
- Any form with repetitive boilerplate

The more consistent your documents, the more value templates provide. If every document is truly unique, templates won't help. But in most service contexts, 80% of the content is standard—templates let users focus on the 20% that matters.
