```
      ____       __ 
  ___/ / /__ _  / / 
 (_-<_  _/  ' \/ _ \
/___//_//_/_/_/_.__/
```

A Laravel-powered markdown blog. No database, no CMS—just markdown files, a service class, and Blade views.

## Tech Stack

- **Laravel 12** - PHP framework
- **Tailwind CSS v4** - Styling
- **Vite** - Asset bundling
- **highlight.js** - Syntax highlighting
- **Pest** - Testing framework

## Project Overview

This blog uses a file-based approach where posts are stored as markdown files in `storage/posts/`. Each post includes YAML frontmatter for metadata, and the system automatically parses, renders, and serves them through Laravel routes.

### Architecture

The system has four main components:

1. **Markdown files** in `storage/posts/` with YAML frontmatter
2. **Post data class** (`App\Data\Post`) that represents a single post
3. **MarkdownPostService** (`App\Services\MarkdownPostService`) that reads and parses files
4. **Controllers and views** that render the content

**Flow**: Markdown file → Service parsing → Post data object → Blade rendering → HTML output

### File Structure

```
app/
├── Data/
│   └── Post.php                    # Post data class
├── Http/
│   └── Controllers/
│       ├── HomeController.php      # Lists all posts
│       └── PostController.php      # Shows single post
├── Services/
│   ├── MarkdownPostService.php     # File parsing logic
│   ├── TagRegistryService.php      # Tag data access
│   └── ThemeService.php            # Theme management
├── Console/
│   └── Commands/
│       └── ScanTagsCommand.php     # Tag registry generator
└── View/
    └── Composers/
        └── ThemeComposer.php       # Injects theme data

storage/
├── posts/
│   └── *.md                         # Your blog posts
└── app/
    └── tags-registry.json           # Generated tag data

resources/
└── views/
    ├── home.blade.php              # Post listing
    └── post.blade.php              # Single post view
```

## Adding New Posts

### File Location

Create a new markdown file in `storage/posts/` directory.

### Naming Convention

**Critical**: The filename determines the URL slug.

- **Format**: `your-slug.md`
- **Slug rules**: Only lowercase letters, numbers, and hyphens are allowed (`[a-z0-9\-]+`)
- **Example**: `my-awesome-post.md` → URL: `/posts/my-awesome-post`

**Important**: The slug in the frontmatter (if provided) must match the filename. If no slug is provided in frontmatter, it will be auto-generated from the title, but the filename still determines the URL.

### Frontmatter Format

Each post must start with YAML frontmatter between `---` delimiters:

```markdown
---
title: "Your Post Title"
date: 2025-01-15
excerpt: "A brief description for the post listing."
tags: [laravel, php, tutorial]
slug: your-post-slug
---

Your markdown content goes here.
```

**Required fields:**
- `title` - The post title (required)

**Optional fields:**
- `date` - Publication date (YYYY-MM-DD). If omitted, uses file modification time
- `excerpt` - Description for listings. If omitted, auto-generated from content (first 250 chars)
- `tags` - Array of tags for categorization
- `slug` - URL identifier. If omitted, auto-generated from title (but filename still determines URL)

### Example Post Template

```markdown
---
title: "Getting Started with Laravel"
date: 2025-01-15
excerpt: "Learn the basics of Laravel framework"
tags: [laravel, php, tutorial]
slug: getting-started-with-laravel
---

## Introduction

This is your post content. Use standard markdown syntax.

### Code Blocks

```php
<?php

echo "Hello, World!";
```

## Conclusion

That's it!
```

### Slug System Explained

The slug system works as follows:

1. **Filename = URL**: The filename (without `.md`) becomes the URL path
   - `my-post.md` → `/posts/my-post`

2. **Route constraint**: The route only accepts slugs matching `[a-z0-9\-]+` (lowercase letters, numbers, hyphens)
   - ✅ Valid: `my-post`, `post-123`, `getting-started`
   - ❌ Invalid: `My_Post` (uppercase/underscore), `post/123` (slash), `post..md` (dots)

3. **Security**: The service validates the slug format to prevent path traversal attacks
   - Attempts like `../../../etc/passwd` are blocked

4. **Frontmatter slug**: If you provide a `slug` in frontmatter, it should match the filename for consistency, but the filename is what actually determines the URL.

## Local Development

### Prerequisites

- PHP 8.2+
- Composer
- Node.js and npm

### Installation

1. Clone the repository
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Install Node.js dependencies:
   ```bash
   npm install
   ```
4. Copy `.env.example` to `.env` and configure:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
5. Build assets:
   ```bash
   npm run build
   ```

### Running the Development Server

Use the convenience script that runs both PHP server and Vite:

```bash
composer run dev
```

Or run separately:

```bash
# Terminal 1: PHP server
php artisan serve

# Terminal 2: Vite dev server
npm run dev
```

The site will be available at `http://localhost:8000`.

## Theming System

The blog supports two themes:

- **pixel-cream** - Light mode (default)
- **pixel-dark** - Dark mode

### How Theme Switching Works

1. User preference is stored in the session
2. System preference (from `prefers-color-scheme`) is detected client-side
3. Theme CSS variables are injected into every page via a View Composer
4. Theme switching uses a simple form POST to update the session

Themes are defined in `config/themes.php` and converted to CSS custom properties by `ThemeService`.

## Tag System

The homepage includes a tag filtering feature that allows visitors to filter posts by topic.

### How It Works

1. **Tag Registry**: Tags are scanned from all posts and stored in `storage/app/tags-registry.json`
2. **Tag Configuration**: Icon mappings and labels are defined in `config/tags.php`
3. **Client-side Filtering**: JavaScript handles filtering without page reloads
4. **URL State**: Filter state is preserved in the URL (`?tags=laravel,php`) for shareable links

### Generating the Tag Registry

After adding or modifying posts, regenerate the tag registry:

```bash
php artisan tags:scan
```

Available options:
- `--dry-run` - Preview the registry without writing to file
- `--show-unmapped` - Display tags that don't have custom icon mappings

### Adding Custom Tag Icons

Edit `config/tags.php` to add mappings for new tags:

```php
'mappings' => [
    'my-new-tag' => [
        'label' => 'My New Tag',
        'icon' => 'code',        // Icon key from the icons array
        'color' => '#FF5733',    // Optional accent color
    ],
],
```

Icons are inline SVGs defined in the `icons` array of the same config file. Use `viewBox="0 0 24 24"` and `currentColor` for consistency.

### Unmapped Tags

Tags without explicit mappings in `config/tags.php` will:
- Use a title-cased label (e.g., `my-tag` → "My Tag")
- Display the default tag icon
- Still be fully functional for filtering

## Testing

Run the test suite:

```bash
php artisan test
```

Or use the composer script:

```bash
composer test
```

Tests cover:
- Markdown parsing and frontmatter extraction
- Heading extraction and anchor ID injection
- Route security and slug validation
- Post data class functionality
