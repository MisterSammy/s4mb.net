---
title: "How This Blog Works: A Laravel-Powered Markdown Blog"
date: 2025-01-15
excerpt: "Why reach for a JavaScript static site generator when you already know Laravel? This post walks through how to build a file-based markdown blog using the framework you already use."
tags: [laravel, markdown, architecture, php, tutorial]
slug: how-this-blog-works
---

If you're a Laravel developer looking to start a blog, you've probably considered the usual suspects: Jekyll, Hugo, Gatsby, Astro, or one of the many JavaScript-based static site generators. They're popular for good reason - they're fast, they handle markdown well, and they have large ecosystems.

But here's the thing: you already know Laravel. You know Blade templating, you know how services work, you understand the request lifecycle. Why learn an entirely new toolchain when you can build something elegant with the framework you use every day?

This blog is built with Laravel using a **file-based approach** - a departure from the typical database-driven Laravel application. Each blog post is stored as a markdown file in `storage/posts/`, where the filename (e.g., `my-post.md`) becomes the URL slug (`/posts/my-post`). No database for posts, no admin panel, no CMS. Just markdown files, a service class, and Blade views. This post explains how it works and why this approach might be right for your next project.

Here's the file structure we'll be working with:

```
app/
├── Data/
│   └── Post.php                 # Data class
├── Http/Controllers/
│   ├── HomeController.php       # Post listing
│   └── PostController.php       # Single post
└── Services/
    └── MarkdownPostService.php  # File parsing
storage/
└── posts/
    └── *.md                     # Markdown files
resources/views/
├── home.blade.php
└── post.blade.php
```

### Basic Concepts

Before diving into the implementation, here are a few concepts that might be unfamiliar if you're coming from traditional Laravel applications:

**Frontmatter** is a convention for storing metadata at the beginning of a markdown file. It's a block of structured data (typically YAML) enclosed by delimiter lines (usually `---`), followed by the actual content. This allows you to embed metadata like title, date, and tags directly in the file without needing a separate database or configuration file.

**Markdown** is a lightweight markup language that converts plain text to HTML. It uses simple syntax like `#` for headings, `**bold**` for bold text, and code fences (triple backticks) for code blocks. Laravel includes CommonMark for rendering markdown to HTML.

**YAML** (YAML Ain't Markup Language) is a human-readable data serialization format used in the frontmatter. It supports key-value pairs, arrays, and nested structures. For example, `tags: [laravel, php]` defines an array of tags.

## Why Laravel Instead of a Static Site Generator?

Static site generators are purpose-built for content sites, so why use a full web framework? A few reasons:

**Familiar tooling.** If Laravel is your daily driver, you can spin up a blog in an afternoon without consulting documentation for a new framework. Blade, Tailwind, Vite, Artisan - it's all there.

**Deployment simplicity.** If you're already deploying Laravel applications, your blog can use the same infrastructure. No separate build pipeline, no Node.js runtime on the server, no static file hosting configuration.

**Room to grow.** Need to add a contact form? User authentication? An API endpoint? You're already in Laravel. With a static site generator, these features often require bolting on additional services or switching to a hybrid approach.

**Server-side rendering by default.** Laravel renders pages on request, which means dynamic features (like the theme switcher on this site) work without client-side JavaScript frameworks.

The trade-off is that you don't get the performance benefits of pre-built static HTML files. For a personal blog with modest traffic, this is rarely a concern. Laravel with proper caching handles thousands of requests per second without breaking a sweat.

## The Architecture

The system has four components:

1. **Markdown files** in `storage/posts/` with YAML frontmatter
2. **A Post data class** that represents a single post
3. **A MarkdownPostService** that reads and parses the files
4. **Controllers and views** that render the content

Let's examine each piece.

## Post Format

Each post is a markdown file with YAML frontmatter at the top:

```markdown
---
title: "Your Post Title"
date: 2025-01-09
excerpt: "A brief description for the post listing."
tags: [laravel, php, tutorial]
slug: your-post-slug
---

Your markdown content goes here.
```

The frontmatter provides metadata:
- `title`  -  The post title (required)
- `date`  -  Publication date, or defaults to file modification time
- `excerpt`  -  Description for listings, or auto-generated from content
- `tags`  -  An array of tags for categorization
- `slug`  -  URL identifier, also used as the filename

The slug determines both the URL (`/posts/your-post-slug`) and the filename (`your-post-slug.md`). This convention keeps the system simple - finding a post by slug is just a file lookup. All posts are stored in `storage/posts/`, making it easy to version control your content and keep everything in one place.

## The Post Data Class

The `Post` class is a data transfer object using PHP 8's constructor property promotion:

```php
<?php

namespace App\Data;

use Carbon\Carbon;
use Illuminate\Support\Str;

class Post
{
    // PHP 8 constructor property promotion: these parameters automatically
    // become public properties without explicit declaration
    public function __construct(
        public string $title,
        public string $slug,
        public string $content,
        public ?string $excerpt,    // Nullable: may not be defined in frontmatter
        public Carbon $date,
        public array $tags,
    ) {}

    /**
     * Returns the excerpt if defined, otherwise generates one from content.
     */
    public function getDisplayExcerpt(): string
    {
        if (! empty($this->excerpt)) {
            return $this->excerpt;
        }

        // Convert markdown to HTML, then strip all tags to get plain text
        $plainText = strip_tags(
            str($this->content)->markdown([
                'html_input' => 'strip',
                'allow_unsafe_links' => false
            ])
        );

        // Truncate to 250 characters with "..." suffix
        return Str::limit($plainText, 250);
    }
}
```

This is intentionally not an Eloquent model. There's no database table, no relationships, no query scopes. It's a plain PHP object that holds data. The `getDisplayExcerpt()` method provides a fallback when no excerpt is defined in the frontmatter - it strips markdown formatting and returns the first 250 characters.

Using a dedicated data class rather than an associative array gives you IDE autocompletion, type safety, and a clear contract for what a post contains.

## The MarkdownPostService

This service handles all file operations and parsing. It's the core of the system.

### Retrieving All Posts

```php
public function getAllPosts(): Collection
{
    $postsPath = $this->getPostsPath();

    // Auto-create the posts directory if it doesn't exist
    if (! File::exists($postsPath)) {
        File::makeDirectory($postsPath, 0755, true);
    }

    // Find all .md files in the posts directory
    $files = File::glob($postsPath.'/*.md');

    $posts = collect($files)
        ->map(fn (string $file) => $this->parsePostFile($file))  // Parse each file into a Post
        ->filter()                                                // Remove nulls (failed parses)
        ->sortByDesc(fn (Post $post) => $post->date->timestamp);  // Newest first

    // values() re-indexes the collection (0, 1, 2...) after sorting
    return $posts->values();
}
```

The method uses `File::glob()` to find all markdown files, maps each through the parser, filters out any that failed to parse, and sorts by date. The `filter()` call removes null values - posts that had invalid frontmatter or missing required fields.

For a blog with dozens of posts, this approach is performant. File reads are fast, and the operating system caches frequently accessed files. If you're concerned about performance with hundreds of posts, you could add Laravel's cache layer:

```php
public function getAllPosts(): Collection
{
    return Cache::remember('posts.all', 3600, function () {
        // ... existing implementation
    });
}
```

### Finding a Single Post

```php
public function findBySlug(string $slug): ?Post
{
    // Security: Validate slug format to prevent path traversal attacks
    // Only allow: a-z, A-Z, 0-9, hyphens, underscores
    // This blocks attempts like "../../../etc/passwd"
    if (! preg_match('/^[a-z0-9\-_]+$/i', $slug)) {
        return null;
    }

    $postsPath = $this->getPostsPath();
    $filePath = $postsPath.'/'.$slug.'.md';  // Direct file lookup: O(1)

    if (! File::exists($filePath)) {
        return null;
    }

    return $this->parsePostFile($filePath);
}
```

Since the slug matches the filename, finding a post is a direct file lookup. No directory scanning, no iteration. This is O(1) regardless of how many posts exist.

**Security note:** The regex validation at the start is critical. Since the slug comes from the URL and is used to construct a file path, it's a potential path traversal attack vector. Without validation, a malicious request like `/posts/../../../etc/passwd` could attempt to read files outside the posts directory. The regex ensures only safe characters (alphanumeric, hyphens, underscores) are allowed, rejecting anything with dots, slashes, or other dangerous characters.

For defense in depth, you should also constrain the route parameter:

```php
Route::get('/posts/{slug}', [PostController::class, 'show'])
    ->name('posts.show')
    ->where('slug', '[a-z0-9\-]+');
```

This blocks invalid slugs at the routing layer before they reach the controller, returning a 404 immediately. Both layers together provide robust protection - the route constraint handles the common case efficiently, while the service validation protects against direct service usage or future code paths that might bypass routing.

### Parsing Frontmatter

The frontmatter parser handles the subset of YAML syntax needed for blog metadata:

```php
private function parseFrontmatter(string $content): ?array
{
    // Frontmatter must start with "---" on line 1
    if (! str_starts_with($content, '---')) {
        return null;
    }

    // Find the closing "---" delimiter (start searching after position 3 
    // to skip the opening delimiter)
    $endPos = strpos($content, "\n---", 3);
    if ($endPos === false) {
        return null;
    }

    // Extract just the YAML content between the two "---" delimiters
    $frontmatterText = substr($content, 3, $endPos - 3);

    return $this->parseSimpleYaml($frontmatterText);
}

private function parseSimpleYaml(string $yaml): array
{
    $result = [];
    $lines = explode("\n", trim($yaml));

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and YAML comments
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }

        // Match YAML key-value pairs: "key: value"
        // The regex captures: (key_name): (everything after the colon)
        // Keys must start with a letter or underscore (YAML spec)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);

            // Strip surrounding quotes from strings like: title: "My Post"
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Parse inline arrays like: tags: [php, laravel, tutorial]
            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $arrayContent = substr($value, 1, -1);        // Remove [ and ]
                $items = array_map('trim', explode(',', $arrayContent));
                $items = array_map(fn ($item) => trim($item, ' "\''), $items); // Strip quotes from items
                $result[$key] = array_filter($items);         // Remove empty items
            } else {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}
```

This parser handles key-value pairs, quoted strings, and inline arrays - everything needed for post metadata. It deliberately doesn't support nested structures or multi-line values. If you need full YAML support, you could use the `symfony/yaml` package instead, but for frontmatter, this lightweight approach works well and avoids an additional dependency.

### Date Handling

```php
private function parseDate(mixed $dateValue, string $filePath): Carbon
{
    if ($dateValue) {
        try {
            return Carbon::parse($dateValue);
        } catch (\Exception $e) {
            // Fall through to file modification time
        }
    }

    return Carbon::createFromTimestamp(File::lastModified($filePath));
}
```

If no date is provided or parsing fails, the system falls back to the file's modification time. This means you can create a quick draft without worrying about the date field - it will default to when you created the file.

## Controllers

With the service handling the heavy lifting, controllers remain minimal:

```php
class HomeController extends Controller
{
    public function __construct(
        protected MarkdownPostService $postService
    ) {}

    public function index()
    {
        $posts = $this->postService->getAllPosts();

        return view('home', ['posts' => $posts]);
    }
}

class PostController extends Controller
{
    public function __construct(
        protected MarkdownPostService $postService
    ) {}

    public function show(string $slug)
    {
        $post = $this->postService->findBySlug($slug);

        if (! $post) {
            abort(404);
        }

        return view('post', ['post' => $post]);
    }
}
```

Constructor injection provides the service, and each method does exactly one thing. This follows Laravel's convention of thin controllers that delegate to services.

## Rendering Markdown

Laravel's `Str` helper includes markdown rendering via the CommonMark library. In Blade:

```blade
{{-- {!! !!} outputs unescaped HTML (needed for rendered markdown) --}}
{!! str($post->content)->markdown([
    'html_input' => 'strip',        {{-- Remove any raw HTML tags from markdown --}}
    'allow_unsafe_links' => false   {{-- Block javascript: and data: URL schemes --}}
]) !!}
```

The options provide basic XSS protection by stripping HTML tags and blocking dangerous link protocols. For a personal blog where you control all content, this is sufficient. For user-generated content, you'd want more rigorous sanitization.

## Table of Contents

Long-form posts benefit from navigation. This blog automatically generates a table of contents from the h2 headings in each post. The system has two parts: extracting headings from markdown, and injecting anchor IDs into the rendered HTML.

### Extracting Headings

The `extractHeadings()` method scans the raw markdown for h2 headings:

```php
public function extractHeadings(string $markdownContent): Collection
{
    $headings = collect();
    $lines = explode("\n", $markdownContent);

    foreach ($lines as $line) {
        // Match markdown h2 headings: "## Heading Text"
        // The regex: ^## matches "##" at line start, \s+ requires whitespace,
        // (.+)$ captures everything after as the heading text
        if (preg_match('/^##\s+(.+)$/', trim($line), $matches)) {
            $text = trim($matches[1]);
            $slug = Str::slug($text);  // "My Heading" → "my-heading"

            // Only include headings that produce valid slugs
            if (! empty($text) && ! empty($slug)) {
                $headings->push([
                    'text' => $text,
                    'slug' => $slug,
                ]);
            }
        }
    }

    return $headings;
}
```

It returns a collection of arrays, each containing the heading text and a URL-safe slug. Laravel's `Str::slug()` handles the conversion - "The MarkdownPostService" becomes `the-markdownpostservice`.

### Injecting Anchor IDs

For the table of contents links to work, each h2 in the rendered HTML needs a matching `id` attribute. The `renderMarkdownWithIds()` method handles this:

```php
public function renderMarkdownWithIds(string $markdownContent): string
{
    // Convert markdown to HTML using Laravel's str() helper
    $html = str($markdownContent)->markdown([
        'html_input' => 'strip',          // Remove any raw HTML in the markdown
        'allow_unsafe_links' => false,     // Block javascript: and data: URLs
    ]);

    $headings = $this->extractHeadings($markdownContent);

    if ($headings->isEmpty()) {
        return $html;
    }

    // Create a lookup map: heading text → ['text' => ..., 'slug' => ...]
    $headingMap = $headings->keyBy('text');

    // Parse HTML with DOMDocument to manipulate the h2 elements
    $dom = new \DOMDocument;

    // Suppress libxml warnings (HTML5 tags trigger warnings in libxml)
    // We save the previous error setting to restore it after parsing
    $previousValue = libxml_use_internal_errors(true);

    // Load the HTML fragment wrapped in a container div
    // - <?xml encoding="UTF-8"> ensures proper character handling
    // - The wrapper div is needed because DOMDocument expects a root element
    // - LIBXML_HTML_NOIMPLIED: Don't add implicit <html><body> wrapper
    // - LIBXML_HTML_NODEFDTD: Don't add a default DOCTYPE declaration
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="markdown-wrapper">'.$html.'</div>', 
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Clear any accumulated warnings and restore error handling
    libxml_clear_errors();
    libxml_use_internal_errors($previousValue);

    $h2Elements = $dom->getElementsByTagName('h2');

    // Iterate backwards to avoid index shifting when modifying the DOM
    // (not strictly necessary here since we're only adding attributes,
    // but it's a good habit for DOM manipulation)
    for ($i = $h2Elements->length - 1; $i >= 0; $i--) {
        $h2 = $h2Elements->item($i);
        $text = trim($h2->textContent);

        // Match this h2's text to our heading map and add the slug as an id
        if ($headingMap->has($text)) {
            $slug = $headingMap->get($text)['slug'];
            if (! $h2->hasAttribute('id')) {
                $h2->setAttribute('id', $slug);  // <h2 id="my-heading">
            }
        }
    }

    // Extract inner HTML from wrapper...
    return $innerHtml;
}
```

The method first renders markdown to HTML, then uses PHP's DOMDocument to parse the HTML and add `id` attributes to each h2 element. The wrapper div approach handles HTML fragments that don't have a root element.

### Rendering the Table of Contents

The controller passes both the headings and the processed HTML to the view:

```php
public function show(string $slug)
{
    $post = $this->postService->findBySlug($slug);

    if (! $post) {
        abort(404);
    }

    $headings = $this->postService->extractHeadings($post->content);
    $htmlContent = $this->postService->renderMarkdownWithIds($post->content);

    return view('post', [
        'post' => $post,
        'headings' => $headings,
        'htmlContent' => $htmlContent,
    ]);
}
```

The Blade template conditionally renders the table of contents only when there are two or more headings - a single heading doesn't warrant navigation:

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

Each link points to `#slug`, which the browser scrolls to thanks to the matching `id` attribute on the heading element. No JavaScript required - it's all standard anchor link behavior.

## Syntax Highlighting

A technical blog without syntax highlighting is like a cookbook without pictures - technically complete, but missing something essential. Rather than relying on CommonMark's plain code block output, this blog uses highlight.js for language-aware syntax coloring.

### Why highlight.js?

Several options exist for client-side highlighting: Prism.js, Shiki, and highlight.js being the most popular. highlight.js won out for a few reasons:

- **Official Monokai theme.** The classic dark theme that developers recognize instantly.
- **Simple integration.** Import, register languages, call one function.
- **Automatic language detection.** Works even when code fences don't specify a language.
- **Tree-shakeable.** Import only the languages you need to keep bundle size small.

### Implementation

The setup lives in `resources/js/app.js`. Rather than importing the entire highlight.js library (which includes every language), we import the core and register only the languages that appear in blog posts:

```javascript
import hljs from 'highlight.js/lib/core';
import 'highlight.js/styles/monokai.css';

// Register languages used in blog posts
import php from 'highlight.js/lib/languages/php';
import javascript from 'highlight.js/lib/languages/javascript';
import xml from 'highlight.js/lib/languages/xml'; // HTML/Blade
import css from 'highlight.js/lib/languages/css';
import bash from 'highlight.js/lib/languages/bash';
import markdown from 'highlight.js/lib/languages/markdown';
import diff from 'highlight.js/lib/languages/diff';

hljs.registerLanguage('php', php);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('blade', xml);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('css', css);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('diff', diff);

// Highlight after DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    hljs.highlightAll();
});
```

The `hljs.highlightAll()` function finds every `<pre><code>` block on the page and applies syntax highlighting based on the language class (e.g., `language-php`) that CommonMark adds from the markdown fence.

### Styling Considerations

The Monokai theme provides the colors, but a few CSS adjustments ensure code blocks feel at home in the design:

```css
.prose pre {
    background: #272822 !important; /* Monokai dark background */
    border: 1px solid #49483e;
    border-radius: 0; /* Pixel art aesthetic;no rounded corners */
    padding: 1rem;
    white-space: pre-wrap; /* Wrap long lines */
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.prose pre code {
    background: transparent !important;
    font-size: 0.9em;
    line-height: 1.5;
    white-space: inherit;
}
```

The `white-space: pre-wrap` rule is worth noting. By default, code blocks use `pre` (preserve all whitespace, never wrap), which creates horizontal scrollbars on long lines. For a blog read on various screen sizes, wrapping is more practical. The `pre-wrap` value preserves indentation and intentional line breaks while allowing the browser to wrap at the container edge.

This is a trade-off. Horizontal scrolling preserves the author's exact line breaks, which matters for some code examples. Wrapping prioritizes readability on narrow screens. For a personal blog with mixed code samples, wrapping wins.

### Adding New Languages

If you write about a language not in the registered list, add it:

```javascript
import rust from 'highlight.js/lib/languages/rust';
hljs.registerLanguage('rust', rust);
```

highlight.js supports over 190 languages. Check the [highlight.js language list](https://highlightjs.org/static/demo/) for the import path.

## The Theming System

This blog includes a dynamic theming system that demonstrates how a Laravel approach enables features that would be complex in a static site generator.

Themes are defined in `config/themes.php`:

```php
return [
    'default' => 'pixel-cream',
    'themes' => [
        'pixel-cream' => [
            'name' => 'Pixel Cream',
            'colors' => [
                'background' => '#f5f3ee',
                'surface' => '#e6e2d8',
                'accent' => '#0b8b7f',
                'text' => '#1c201c',
                // ...
            ],
        ],
        'pixel-dark' => [
            'name' => 'Pixel Dark',
            'colors' => [
                'background' => '#11130f',
                'surface' => '#1a2330',
                // ...
            ],
        ],
    ],
];
```

A `ThemeService` converts the active theme into CSS custom properties, and a View Composer injects these into every page:

```php
class ThemeComposer
{
    public function __construct(
        protected ThemeService $themeService
    ) {}

    public function compose(View $view): void
    {
        $view->with('themeCss', $this->themeService->getCssVariables());
        $view->with('currentThemeSlug', $this->themeService->getCurrentThemeSlug());
    }
}
```

The CSS variables are injected into the page head:

```blade
@if(isset($themeCss))
<style>{!! $themeCss !!}</style>
@endif
```

Views reference these variables with Tailwind's arbitrary value syntax:

```html
<body class="bg-[var(--color-background)] text-[var(--color-text)]">
```

Theme preference is stored in the session. When a user toggles the theme, a simple form POST updates the session value, and the next page load renders with the new colors. No JavaScript framework required.

With a static site generator, this kind of dynamic theming would require client-side JavaScript to swap stylesheets or CSS classes. The Laravel approach handles it server-side with a form submission.

## Routing

The routes are straightforward:

```php
Route::get('/', [HomeController::class, 'index']);
Route::get('/posts/{slug}', [PostController::class, 'show'])
    ->name('posts.show')
    ->where('slug', '[a-z0-9\-_]+');  // Security: constrain slug format
```

This is a **file-based routing system**. Unlike typical Laravel applications that use Eloquent models and database queries, this blog stores posts as markdown files in `storage/posts/`. When a request comes in for `/posts/my-post`, Laravel extracts the `slug` parameter (`my-post`), and the controller looks up `storage/posts/my-post.md` directly.

### Why `storage/posts/`?

Posts are stored in `storage/posts/` for a few practical reasons:

- **Outside the web root.** Files in `storage/` aren't directly accessible via HTTP, providing a security layer. Even if your web server configuration fails, users can't browse your post files directly.
- **Version controlled.** You can commit your markdown files to git alongside your application code. Every post edit becomes a commit with a diff, making content changes easy to track and review.
- **Simple deployment.** When you deploy, your posts deploy with your code. No separate database migrations or content sync process.

### File-to-URL Mapping

The slug-to-filename convention (`/posts/my-post` → `storage/posts/my-post.md`) creates a one-to-one mapping that makes the system predictable:

```php
// URL: /posts/building-state-machines-without-a-library
// File: storage/posts/building-state-machines-without-a-library.md
```

When the `PostController::show()` method receives a slug, it doesn't need to query a database or scan a directory. It constructs the file path directly: `$filePath = $postsPath.'/'.$slug.'.md'`. This is an O(1) operation - constant time regardless of how many posts you have. Contrast this with a database-driven approach where you'd need a `SELECT` query, even with an index.

### A Departure from Typical Laravel Patterns

Most Laravel applications follow this pattern for content:

```php
// Typical Laravel approach
Route::get('/posts/{post}', [PostController::class, 'show']);

class PostController
{
    public function show(Post $post)  // Route model binding
    {
        return view('post', ['post' => $post]);
    }
}
```

The `Post` model extends `Illuminate\Database\Eloquent\Model`, queries a `posts` table in the database, and provides relationships, scopes, and all the power of Eloquent.

This blog takes a different approach:

```php
// File-based approach
Route::get('/posts/{slug}', [PostController::class, 'show']);

class PostController
{
    public function show(string $slug)  // Simple string parameter
    {
        $post = $this->postService->findBySlug($slug);  // File lookup
        // ...
    }
}
```

The `Post` class is a plain data object, not an Eloquent model. There's no database table, no migrations, no relationships. Posts are files, and routing is file-based.

### Why This Works Well

This file-based approach works particularly well for developer blogs because:

- **Developer-friendly workflow.** You write posts in your favorite editor, commit them to git, and they're live. No need to log into an admin panel or deal with WYSIWYG editors.
- **Fast lookup.** Finding a post is a single file system operation. No database round-trip, no query parsing, no result hydration.
- **Simple deployment.** Your content is code. When you deploy, your posts deploy. No separate content management or sync process.
- **Git-native.** Your posts have full version history, can be reviewed in pull requests, and can be reverted with `git revert`. You get all the benefits of version control for your content.

The trade-off is that you lose the flexibility of database relationships. If you need categories, tags, related posts, or complex filtering, a database becomes more practical. But for a personal blog with straightforward content, the file-based approach keeps things simple and maintainable.

## When This Approach Works Well

**Personal blogs and portfolios.** Low traffic, infrequent updates, content authored by developers comfortable with markdown and git.

**Documentation sites.** Technical documentation often lives in markdown files anyway. This approach lets you render them with your application's existing styling.

**Developer blogs.** If you're writing about code, you're probably comfortable with the command line and text editors. No need for a web-based CMS.

**Projects where posts should live in version control.** Every change is a git commit. You get history, diffs, and the ability to revert changes.

## When to Consider Alternatives

**High-traffic sites.** Static site generators produce HTML files that can be served from a CDN with no server processing. For sites with millions of visitors, this matters.

**Non-technical authors.** If your writers aren't comfortable with markdown and git, a traditional CMS with a visual editor is a better choice.

**Complex content relationships.** If posts have categories, series, related posts, and other relationships, a database becomes more practical.

**Build-time processing.** Static generators can optimize images, generate responsive variants, and perform other build-time transformations. With Laravel, you'd handle this separately or on-demand.

## Getting Started

To build something similar:

1. Create the `Post` data class in `app/Data/Post.php`
2. Create the `MarkdownPostService` in `app/Services/MarkdownPostService.php`
3. Add the controllers
4. Create your Blade views
5. Add routes
6. Create your first markdown file in `storage/posts/`

The entire setup takes less than an hour if you're familiar with Laravel.

## Conclusion

Not every project needs a purpose-built tool. If you know Laravel, you can build a capable markdown blog without learning a new framework, setting up a JavaScript build pipeline, or configuring static site hosting.

The code is simple, the architecture is clear, and you have the full power of Laravel available when you need it. For a developer blog, that's often exactly the right trade-off.
