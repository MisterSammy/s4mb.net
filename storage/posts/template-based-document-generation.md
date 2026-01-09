---
title: "Template-Based Document Generation in Laravel"
date: 2025-01-09
excerpt: "Pre-fill complex forms from templates so users customize rather than create from scratch. A pattern for legal documents, proposals, and contracts."
tags: [laravel, templates, forms, ux]
slug: template-based-document-generation
---

Professional services firms produce the same types of documents repeatedly. Law firms write engagement letters. Agencies send proposals. Consultants deliver reports. The structure is consistent; only the details change.

Instead of making users fill out blank forms every time, let them select a template and customize it. The template provides sensible defaults for all the boilerplate. The user focuses on what's unique to this situation.

## The Template Model

A template stores default values for all the fields a document might have:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructionTemplate extends Model
{
    protected $fillable = [
        'name',                  // "Standard Fixed Fee", "Subscription Retainer"
        'description',           // Scope of work description
        'scope',                 // What's included
        'exclusions',            // What's not included
        'assumptions',           // Conditions we're assuming
        'additional_information',
        'timescales',
        'timing_of_invoice',
        'payment_terms',
        'payment_on_account',
        'fee',                   // e.g., "£5,000"
        'fee_type',              // "fixed", "hourly", "subscription"
        'fee_structure',         // Detailed fee breakdown
        'subscription_term',     // For subscription-based work
        'acceptable_use',        // Usage terms
        'termination',           // How to end the engagement
        'communication_with_clients',
        'confidential_information',
        'your_obligations',
        'authorisation_to_appoint_company_secretary',
        'termination_of_appointment',
        'liability',
    ];
}
```

This is a wide table—lots of text columns. That's fine. Templates are read frequently but written rarely. The simplicity of a single table outweighs normalization concerns.

## Seeding Templates

Pre-populate templates with your standard documents:

```php
InstructionTemplate::create([
    'name' => 'Standard Fixed Fee',
    'description' => 'We will provide legal advice and assistance in relation to [MATTER DESCRIPTION].',
    'scope' => "Our advice will cover:\n- Reviewing relevant documents\n- Providing written advice\n- Liaising with counterparties as needed",
    'exclusions' => "This engagement does not include:\n- Court representation\n- Regulatory filings\n- Tax advice",
    'assumptions' => "We assume:\n- You will provide all relevant information promptly\n- The matter proceeds without significant complications",
    'fee' => '£[AMOUNT]',
    'fee_type' => 'fixed',
    'payment_terms' => 'Payment is due within 14 days of invoice.',
    // ... other fields
]);

InstructionTemplate::create([
    'name' => 'Hourly Rate Engagement',
    'description' => 'We will provide legal advice and assistance on an hourly rate basis.',
    'fee_type' => 'hourly',
    'fee_structure' => "Partner: £400/hour\nAssociate: £250/hour\nParalegal: £150/hour",
    // ... other fields
]);
```

Notice the `[PLACEHOLDERS]`. Users see these and know to replace them. It's a simple convention that works.

## The Document Model

The actual document inherits template fields but can override any of them:

```php
// Instruction.php
protected $fillable = [
    'client_id',
    'user_id',
    'status',
    'title',
    'department',
    'category',
    // All the template fields
    'description',
    'scope',
    'exclusions',
    'assumptions',
    'additional_information',
    'timescales',
    'timing_of_invoice',
    'payment_terms',
    'payment_on_account',
    'fee',
    'fee_type',
    'fee_structure',
    // ... etc
];

public static function getTemplateRelatedFields()
{
    return [
        'description',
        'fee_type',
        'fee',
        'fee_structure',
        'scope',
        'assumptions',
        'exclusions',
        'your_obligations',
        'timing_of_invoice',
        'additional_information',
        'timescales',
        'payment_terms',
        'payment_on_account',
        'subscription_term',
        'acceptable_use',
        'termination',
        'communication_with_clients',
        'confidential_information',
        'authorisation_to_appoint_company_secretary',
        'termination_of_appointment',
        'liability',
    ];
}
```

The `getTemplateRelatedFields()` method lists which fields come from templates. Useful for building forms dynamically.

## Creating Documents from Templates

In a Livewire wizard component:

```php
<?php

namespace App\Http\Livewire\Instructions;

use App\Models\Instruction;
use App\Models\InstructionTemplate;
use Livewire\Component;

class Wizard extends Component
{
    public $templates;
    public $template;
    public $title;
    public $clientId;
    // ... other fields

    public function mount()
    {
        $this->templates = InstructionTemplate::all();
    }

    public function save()
    {
        $this->validate([
            'template' => 'required',
            'title' => 'required',
            'clientId' => 'required',
        ]);

        $template = InstructionTemplate::find($this->template);

        // Create instruction with template values
        $instruction = Instruction::create([
            'client_id' => $this->clientId,
            'user_id' => auth()->id(),
            'title' => $this->title,
            // Copy all template fields
            'description' => $template->description,
            'scope' => $template->scope,
            'exclusions' => $template->exclusions,
            'assumptions' => $template->assumptions,
            'additional_information' => $template->additional_information,
            'timescales' => $template->timescales,
            'timing_of_invoice' => $template->timing_of_invoice,
            'payment_terms' => $template->payment_terms,
            'payment_on_account' => $template->payment_on_account,
            'fee' => $template->fee,
            'fee_type' => $template->fee_type,
            'fee_structure' => $template->fee_structure,
            'subscription_term' => $template->subscription_term,
            'acceptable_use' => $template->acceptable_use,
            'termination' => $template->termination,
            'communication_with_clients' => $template->communication_with_clients,
            'confidential_information' => $template->confidential_information,
            'your_obligations' => $template->your_obligations,
            'liability' => $template->liability,
        ]);

        return redirect()->to('/instructions/' . $instruction->uuid);
    }
}
```

The document is created with all template values. The user then reviews and customizes.

## The Editing Interface

After creation, present each field for review:

```blade
<form wire:submit.prevent="save">
    @foreach(App\Models\Instruction::getTemplateRelatedFields() as $field)
        <div class="mb-6">
            <label class="block text-sm font-medium mb-2">
                {{ Str::title(str_replace('_', ' ', $field)) }}
            </label>
            
            <textarea 
                wire:model="fields.{{ $field }}"
                rows="4"
                class="w-full border rounded-lg p-3"
            >{{ $instruction->$field }}</textarea>
        </div>
    @endforeach

    <button type="submit" class="btn btn-primary">Save Changes</button>
</form>
```

Users see all the template content and edit what needs changing. Placeholders like `[MATTER DESCRIPTION]` prompt them for specific details.

## Managing Templates

Build an admin interface for template CRUD. Filament makes this trivial:

```php
<?php

namespace App\Filament\Resources;

use App\Models\InstructionTemplate;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;

class InstructionTemplateResource extends Resource
{
    protected static ?string $model = InstructionTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('fee_type')
                ->options([
                    'fixed' => 'Fixed Fee',
                    'hourly' => 'Hourly Rates',
                    'subscription' => 'Subscription',
                ]),
            Forms\Components\Textarea::make('description')->rows(5),
            Forms\Components\Textarea::make('scope')->rows(5),
            Forms\Components\Textarea::make('exclusions')->rows(5),
            // ... all other fields
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('fee_type'),
            Tables\Columns\TextColumn::make('updated_at')->dateTime(),
        ]);
    }
}
```

## Benefits

**Consistency.** Every document starts from approved language. No one accidentally omits the liability clause.

**Efficiency.** Users fill in the blanks instead of writing from scratch. A 20-field form becomes 3-4 customizations.

**Maintainability.** Update a template, and all future documents use the new language. (Existing documents keep their values—templates are copied, not referenced.)

**Audit trail.** You can see exactly what template a document started from and what was changed.

## When to Use This Pattern

Template-based generation works well for:

- Legal documents (contracts, agreements, letters)
- Proposals and quotes
- Reports with standard sections
- Email templates
- Any form with repetitive boilerplate

The more consistent your documents, the more value templates provide. If every document is truly unique, templates won't help. But in most professional contexts, 80% of the content is standard—templates let users focus on the 20% that matters.

