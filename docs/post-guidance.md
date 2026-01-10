# Post Writing Guidance

This document outlines the tone, structure, and writing conventions used across all blog posts. Follow these guidelines to maintain consistency with the existing content, particularly the flagship "How This Blog Works" post.

## Frontmatter Format

Every post must start with YAML frontmatter containing the following fields:

```markdown
---
title: "Your Post Title Here"
date: 2025-01-15
excerpt: "A brief, compelling description (typically one sentence) that appears in post listings."
tags: [laravel, php, architecture, tutorial]
slug: your-post-slug-here
---
```

**Requirements:**
- `title`: Use title case, keep it descriptive and specific
- `date`: Use format `YYYY-MM-DD`, typically the publication date
- `excerpt`: One sentence that summarizes the post's value proposition. Should work as a standalone description.
- `tags`: Array of lowercase tags. Common tags: `laravel`, `php`, `architecture`, `tutorial`, `eloquent`, `livewire`, `enums`, `workflow`, `permissions`, `multi-tenant`, `polymorphic`, `database-design`, `forms`, `ux`, `events`, `search`, `components`
- `slug`: URL-friendly version of the title (lowercase, hyphens for spaces). Should match the filename (minus `.md` extension)

## Voice and Tone

### Core Principles

**Conversational but professional**: Address the reader directly with "you". Write as if talking to a colleague who knows Laravel well.

```markdown
If you're a Laravel developer looking to start a blog, you've probably considered the usual suspects...
```

**Direct and confident**: Make clear recommendations. Take positions. Don't hedge unnecessarily.

```markdown
For most applications, **the native observer approach is recommended** - it has zero dependencies and gives you full control over the logic.
```

**Practical and pragmatic**: Acknowledge trade-offs honestly. Explain when something works well and when it doesn't.

```markdown
For a blog with dozens of posts, this approach is performant. File reads are fast, and the operating system caches frequently accessed files. If you're concerned about performance with hundreds of posts, you could add Laravel's cache layer...
```

**Assumes technical competence**: Don't over-explain basic Laravel concepts. Assume readers understand Eloquent, controllers, views, etc.

**Opinionated**: State preferences clearly. Use phrases like "For most applications, X is recommended" or "Start simple. If your workflow stays simple, the enum approach will serve you well."

### What to Avoid

- Apologetic language ("This might not be the best way, but...")
- Over-explaining basics ("Laravel is a PHP framework...")
- Vague recommendations ("You could try this or that...")
- Passive voice where active is clearer

## Structure Template

Follow this structure for consistency:

### 1. Opening Hook

Start with a relatable problem, question, or observation that immediately connects with the reader's experience:

```markdown
Every application eventually needs a notes feature. Properties need notes. Tenants need notes. Work orders need notes. The instinct is to create separate tables: `property_notes`, `tenant_notes`, `work_order_notes`.

Don't do that.
```

Or:

```markdown
If you're a Laravel developer looking to start a blog, you've probably considered the usual suspects: Jekyll, Hugo, Gatsby, Astro, or one of the many JavaScript-based static site generators. They're popular for good reason - they're fast, they handle markdown well, and they have large ecosystems.

But here's the thing: you already know Laravel.
```

### 2. The Scenario Section

Provide a concrete, real-world example that grounds the technical discussion. Use specific domains (pet grooming, coworking spaces, property management, wedding planning):

```markdown
## The Scenario: Pet Grooming Appointments

Consider a pet grooming salon where appointments move through various statuses:
- **Scheduled**  -  Appointment booked
- **Checked In**  -  Pet has arrived
- **In Progress**  -  Grooming underway
- **Completed**  -  Ready for pickup
- **Cancelled**  -  Appointment cancelled
```

### 3. Main Content Sections

Use clear `##` headers for major sections. Structure with:
- **Concept explanation** (what it is, why it matters)
- **Implementation** (how to do it, with code examples)
- **Advanced usage** (edge cases, optimizations)
- **Alternatives** (when to use different approaches)

### 4. Code Examples

Every significant concept should have a complete, copy-paste-able code example. Include:
- Full class/method definitions
- Required imports
- Context comments explaining non-obvious parts
- Realistic data/field names from the scenario

Format code blocks with language hints:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
        ];
    }
}
```

For Blade templates:

```blade
@if(isset($headings) && $headings->count() >= 2)
<nav class="mb-8">
    <ul class="space-y-2">
        @foreach($headings as $heading)
            <li>
                <a href="#{{ $heading['slug'] }}" 
                   class="font-mono text-sm text-[var(--color-text-muted)] hover-line">
                    {{ $heading['text'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
@endif
```

### 5. When to Use / When Not to Use

Always include guidance on when the approach works well and when to consider alternatives:

```markdown
## When This Approach Works Well

This enum-based approach works well when:

**States are few (3-8).** Beyond that, the `match` statements become unwieldy.

**Transitions are predictable.** The workflow follows a mostly linear path with some branches.

**No complex guards.** Transitions don't depend on multiple conditions, async checks, or external service calls.

## When to Reach for a Package

Now consider a more complex workflow - a vendor application system for a large multi-day festival:

- **10+ states:** Application → Initial Review → Background Check → Health Permit → Insurance → Fire Safety → Capacity Review → Payment → Final Approval → Active → Suspended → Rejected (from multiple stages)
- **Role-based transitions:** Only festival coordinators can approve, but vendors can submit documents
- **Time-based guards:** Applications auto-reject if not completed within 30 days

For this complexity, a dedicated state machine package like `spatie/laravel-model-states` is the right choice...
```

### 6. Conclusion

Summarize key takeaways in a concise conclusion:

```markdown
## Conclusion

For truly simple workflows with 3-8 states and predictable transitions, PHP enums with transition validation are elegant and sufficient. They provide type safety, IDE support, and enforce valid transitions - all without external dependencies.

But as complexity grows - more states, complex guards, transition history, multiple roles - a dedicated state machine package becomes the pragmatic choice. The enum approach works until it doesn't, and you'll know when you've outgrown it.

Start simple. If your workflow stays simple, the enum approach will serve you well. If it grows complex, you'll appreciate having a battle-tested package to handle the edge cases.
```

## Writing Conventions

### Punctuation and Formatting

**Em dashes with spaces** for explanatory breaks:
```markdown
- **Scheduled**  -  Appointment booked
- **Health Inspection**  -  Health department reviews permits
- **Insurance Review** .  Festival organizers verify insurance
```

**Bold for key concepts**:
```markdown
This is a **file-based routing system**. Unlike typical Laravel applications that use Eloquent models and database queries, this blog stores posts as markdown files in `storage/posts/`.
```

**Inline code** for:
- File paths: `storage/posts/my-post.md`
- Method names: `getAllPosts()`
- Class names: `MarkdownPostService`
- Config keys: `app.name`
- Route names: `posts.show`

**Bullet lists** with em dashes for definitions (see above examples).

### Paragraph Structure

Keep paragraphs short (2-4 sentences). One idea per paragraph. Break up long explanations with headers or code blocks.

### Code Comments

Include comments in code examples for:
- Non-obvious logic or decisions
- Security considerations
- Performance implications
- Laravel-specific behavior that might not be obvious

```php
// Security: Validate slug format to prevent path traversal attacks
// Only allow: a-z, A-Z, 0-9, hyphens, underscores
// This blocks attempts like "../../../etc/passwd"
if (! preg_match('/^[a-z0-9\-_]+$/i', $slug)) {
    return null;
}
```

## Content Patterns

### Real-World Scenarios

Ground every technical concept in a concrete scenario:
- Pet grooming salons
- Coworking spaces
- Property management companies
- Wedding planning businesses
- Food truck festivals
- Multi-location service businesses

### Complete Code Examples

Always provide full, working examples. Don't show fragments that require readers to infer the rest:

```php
<?php

namespace App\Services;

use App\Data\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class MarkdownPostService
{
    public function getAllPosts(): Collection
    {
        $postsPath = $this->getPostsPath();

        if (! File::exists($postsPath)) {
            File::makeDirectory($postsPath, 0755, true);
        }

        $files = File::glob($postsPath.'/*.md');

        $posts = collect($files)
            ->map(fn (string $file) => $this->parsePostFile($file))
            ->filter()
            ->sortByDesc(fn (Post $post) => $post->date->timestamp);

        return $posts->values();
    }
}
```

### Testing Examples

Include testing examples when relevant. Use Pest syntax:

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
```

### Trade-Off Discussions

Always discuss trade-offs honestly:

```markdown
The trade-off is that you lose the flexibility of database relationships. If you need categories, tags, related posts, or complex filtering, a database becomes more practical. But for a personal blog with straightforward content, the file-based approach keeps things simple and maintainable.
```

### Security Considerations

Include security notes when relevant:

```markdown
**Security note:** The regex validation at the start is critical. Since the slug comes from the URL and is used to construct a file path, it's a potential path traversal attack vector. Without validation, a malicious request like `/posts/../../../etc/passwd` could attempt to read files outside the posts directory.
```

## Examples of Strong Opening Hooks

### Problem/Opportunity Recognition
```markdown
If you're a Laravel developer looking to start a blog, you've probably considered the usual suspects: Jekyll, Hugo, Gatsby, Astro, or one of the many JavaScript-based static site generators.
```

### Anti-Pattern Callout
```markdown
Every application eventually needs a notes feature. Properties need notes. Tenants need notes. Work orders need notes. The instinct is to create separate tables: `property_notes`, `tenant_notes`, `work_order_notes`.

Don't do that.
```

### Direct Recommendation
```markdown
Sometimes you just need search to work. Not "proper" search with fancy indexing and typo tolerance - just a text box that finds stuff. You've got a deadline, a demo tomorrow, or a client who swears they'll "add real search later."

This is that search. Quick, dirty, and MySQL-powered. Copy it, ship it, move on with your life.
```

### Contextual Question
```markdown
Laravel's Eloquent models fire events when things happen - `created`, `updated`, `deleted`, and so on. These events are useful for triggering side effects like sending notifications, updating caches, or logging changes.

But there's a limitation. The `updated` event fires whenever *any* attribute changes.
```

## Section Header Patterns

Use descriptive, action-oriented headers:

- `## The Architecture`
- `## Defining States with Enums`
- `## Transition Validation`
- `## When This Simple Pattern Works Well`
- `## When to Reach for a Package`
- `## The Scenario: [Domain Name]`
- `## Creating [Things] from [Source]`
- `## Querying [Resources]`
- `## Displaying [Content] in [Context]`

Avoid vague headers like "Implementation" or "Details". Be specific about what the section covers.

## Code Block Annotations

When explaining complex code, include inline comments or annotations:

```php
// Parse each file into a Post
->map(fn (string $file) => $this->parsePostFile($file))  
// Remove nulls (failed parses)
->filter()                                                
// Newest first
->sortByDesc(fn (Post $post) => $post->date->timestamp);  
```

Or explain code blocks immediately after presenting them:

```markdown
The method uses `File::glob()` to find all markdown files, maps each through the parser, filters out any that failed to parse, and sorts by date. The `filter()` call removes null values - posts that had invalid frontmatter or missing required fields.
```

## Length Guidelines

- **Short posts**: 400-800 words (quick solutions, copy-paste examples)
- **Medium posts**: 800-2000 words (standard tutorials, architectural patterns)
- **Long posts**: 2000-4000 words (comprehensive guides like "How This Blog Works")

Let the topic determine length. Don't pad or cut unnecessarily. Some posts benefit from thorough exploration; others should be concise.

## Common Patterns from "How This Blog Works"

The flagship post demonstrates several patterns to emulate:

1. **Architecture overview first**: Explain the big picture before diving into details
2. **File structure diagrams**: Show the codebase organization visually
3. **Progressive complexity**: Start simple, then add features (caching, security, optimization)
4. **Security as a first-class concern**: Always discuss security implications
5. **Performance considerations**: Acknowledge scalability limits and solutions
6. **Alternatives and trade-offs**: Compare approaches honestly

## Final Checklist

Before publishing, ensure:

- [ ] Frontmatter is complete and accurate
- [ ] Opening hook connects with reader's experience
- [ ] Concrete scenario grounds the technical discussion
- [ ] All code examples are complete and copy-paste-able
- [ ] Security considerations are addressed (when relevant)
- [ ] Trade-offs are discussed honestly
- [ ] "When to use / When not to use" section is included
- [ ] Conclusion summarizes key takeaways
- [ ] Tone is conversational but professional
- [ ] Technical competence is assumed (no over-explanation)
- [ ] Em dashes with spaces are used correctly
- [ ] Code blocks have language hints
- [ ] Inline code formatting is used consistently
- [ ] Paragraphs are short and focused

Follow these guidelines to maintain consistency with the existing blog's voice and structure.

