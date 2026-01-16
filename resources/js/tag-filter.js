/**
 * Tag filtering system for posts
 * Handles tag selection, URL state, and post visibility
 */

class TagFilter {
    constructor() {
        this.activeTags = new Set();
        this.posts = [];
        this.tagPills = [];
        this.noResultsEl = null;
        this.activeFiltersEl = null;
        this.allButton = null;
    }

    init() {
        // Collect all post elements
        this.posts = Array.from(document.querySelectorAll('[data-tags]'));
        this.tagPills = Array.from(document.querySelectorAll('.tag-pill[data-tag]'));
        this.noResultsEl = document.getElementById('no-results');
        this.activeFiltersEl = document.getElementById('active-filters');
        this.allButton = document.getElementById('tag-filter-all');

        if (this.posts.length === 0) {
            return;
        }

        // Restore state from URL
        this.restoreFromUrl();

        // Apply initial filters
        this.applyFilters();
    }

    toggle(slug) {
        if (this.activeTags.has(slug)) {
            this.activeTags.delete(slug);
        } else {
            this.activeTags.add(slug);
        }

        this.applyFilters();
        this.updateUrl();
    }

    clear() {
        this.activeTags.clear();
        this.applyFilters();
        this.updateUrl();
    }

    applyFilters() {
        const hasFilters = this.activeTags.size > 0;

        // Update "All" button state
        if (this.allButton) {
            this.allButton.classList.toggle('tag-pill--active', !hasFilters);
        }

        // Update tag pill states
        this.tagPills.forEach(pill => {
            const tag = pill.dataset.tag;
            pill.classList.toggle('tag-pill--active', this.activeTags.has(tag));
        });

        // Filter posts
        let visibleCount = 0;
        this.posts.forEach(post => {
            const postTags = (post.dataset.tags || '').split(',').filter(Boolean);

            let visible = false;
            if (!hasFilters) {
                // No filters = show all
                visible = true;
            } else {
                // Show if post has ANY of the active tags
                visible = postTags.some(tag => this.activeTags.has(tag));
            }

            post.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount++;
                // Add fade-in animation
                post.classList.add('post-fade-in');
            }
        });

        // Show/hide "no results" message
        if (this.noResultsEl) {
            this.noResultsEl.style.display = (hasFilters && visibleCount === 0) ? 'block' : 'none';
        }

        // Update active filters display
        this.updateActiveFiltersDisplay();
    }

    updateActiveFiltersDisplay() {
        if (!this.activeFiltersEl) return;

        if (this.activeTags.size === 0) {
            this.activeFiltersEl.style.display = 'none';
            return;
        }

        this.activeFiltersEl.style.display = 'flex';

        // Update the count
        const countEl = this.activeFiltersEl.querySelector('.active-filter-count');
        if (countEl) {
            countEl.textContent = this.activeTags.size;
        }
    }

    updateUrl() {
        const url = new URL(window.location.href);

        if (this.activeTags.size > 0) {
            url.searchParams.set('tags', Array.from(this.activeTags).join(','));
        } else {
            url.searchParams.delete('tags');
        }

        window.history.replaceState({}, '', url.toString());
    }

    restoreFromUrl() {
        const url = new URL(window.location.href);
        const tagsParam = url.searchParams.get('tags');

        if (tagsParam) {
            const tags = tagsParam.split(',').filter(Boolean);
            tags.forEach(tag => this.activeTags.add(tag));
        }
    }
}

// Create global instance
window.TagFilter = new TagFilter();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.TagFilter.init());
} else {
    window.TagFilter.init();
}

export default TagFilter;
