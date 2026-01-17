import './bootstrap';
import './tag-filter';
import hljs from 'highlight.js/lib/core';
// Custom New Moon theme defined in app.css

// Register languages used in blog posts
import php from 'highlight.js/lib/languages/php';
import javascript from 'highlight.js/lib/languages/javascript';
import xml from 'highlight.js/lib/languages/xml'; // HTML/Blade
import css from 'highlight.js/lib/languages/css';
import bash from 'highlight.js/lib/languages/bash';
import markdown from 'highlight.js/lib/languages/markdown';
import diff from 'highlight.js/lib/languages/diff';
import typescript from 'highlight.js/lib/languages/typescript';
import plaintext from 'highlight.js/lib/languages/plaintext';
import ini from 'highlight.js/lib/languages/ini';

hljs.registerLanguage('php', php);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('blade', xml);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('css', css);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('diff', diff);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('plaintext', plaintext);
hljs.registerLanguage('text', plaintext);
hljs.registerLanguage('ini', ini);
hljs.registerLanguage('env', ini);

// Highlight code blocks only within .prose containers (blog post content)
// This excludes decorative code blocks like ASCII art on the homepage
const highlightProseCodeBlocks = () => {
    document.querySelectorAll('.prose pre code').forEach((el) => {
        const langClass = [...el.classList].find(c => c.startsWith('language-'));
        const lang = langClass?.replace('language-', '');

        // Skip highlighting for plaintext/text blocks - just apply base styling
        if (lang === 'plaintext' || lang === 'text') {
            el.classList.add('hljs');
            return;
        }

        hljs.highlightElement(el);
    });
};

// Add copy buttons to code blocks
const addCopyButtons = () => {
    document.querySelectorAll('.prose pre').forEach((pre) => {
        // Skip if already has a copy button
        if (pre.querySelector('.copy-button')) {
            return;
        }

        // Make pre position relative for absolute positioning of button
        pre.style.position = 'relative';

        const button = document.createElement('button');
        button.className = 'copy-button';
        button.setAttribute('aria-label', 'Copy code to clipboard');
        button.innerHTML = `
            <svg class="copy-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        `;

        button.addEventListener('click', async () => {
            const code = pre.querySelector('code');
            const text = code ? code.innerText : pre.innerText;

            try {
                await navigator.clipboard.writeText(text);

                // Show success state
                button.classList.add('copied');
                button.querySelector('.copy-icon').style.display = 'none';
                button.querySelector('.check-icon').style.display = 'block';

                // Reset after 2 seconds
                setTimeout(() => {
                    button.classList.remove('copied');
                    button.querySelector('.copy-icon').style.display = 'block';
                    button.querySelector('.check-icon').style.display = 'none';
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
            }
        });

        pre.appendChild(button);
    });
};

// Reading progress bar
const initReadingProgress = () => {
    const progressBar = document.getElementById('reading-progress');
    if (!progressBar) return;

    const updateProgress = () => {
        const article = document.querySelector('article');
        if (!article) return;

        const articleTop = article.offsetTop;
        const articleHeight = article.offsetHeight;
        const windowHeight = window.innerHeight;
        const scrollY = window.scrollY;

        const start = articleTop - windowHeight / 2;
        const end = articleTop + articleHeight - windowHeight / 2;
        const progress = Math.min(Math.max((scrollY - start) / (end - start), 0), 1);

        progressBar.style.width = `${progress * 100}%`;
    };

    window.addEventListener('scroll', updateProgress, { passive: true });
    updateProgress();
};

// Image lazy load fade-in
const initImageLoading = () => {
    document.querySelectorAll('.prose img[loading="lazy"]').forEach(img => {
        if (img.complete) {
            img.classList.add('loaded');
        } else {
            img.addEventListener('load', () => img.classList.add('loaded'));
        }
    });
};

// Run after DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        highlightProseCodeBlocks();
        addCopyButtons();
        initReadingProgress();
        initImageLoading();
    });
} else {
    highlightProseCodeBlocks();
    addCopyButtons();
    initReadingProgress();
    initImageLoading();
}
