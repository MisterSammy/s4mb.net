import './bootstrap';
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
import typescript from 'highlight.js/lib/languages/typescript';

hljs.registerLanguage('php', php);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('blade', xml);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('css', css);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('diff', diff);
hljs.registerLanguage('typescript', typescript);

// Highlight code blocks only within .prose containers (blog post content)
// This excludes decorative code blocks like ASCII art on the homepage
const highlightProseCodeBlocks = () => {
    document.querySelectorAll('.prose pre code').forEach((el) => {
        hljs.highlightElement(el);
    });
};

// Run after DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', highlightProseCodeBlocks);
} else {
    highlightProseCodeBlocks();
}
