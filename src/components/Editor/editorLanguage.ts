import type { LanguageSupport } from '@codemirror/language';

/**
 * Returns the CodeMirror LanguageSupport for a given filename.
 * All language imports are dynamic so CodeMirror is never in the initial bundle.
 */
export async function getLanguage(filename: string): Promise<LanguageSupport | null> {
    const base = filename.split('/').pop() || filename;
    const ext  = base.includes('.') ? base.split('.').pop()!.toLowerCase() : '';

    // dot-files with no meaningful extension — plain text
    if (ext === '' || ext === base.toLowerCase()) {
        return null;
    }

    switch (ext) {
        // JavaScript / TypeScript
        case 'js':
        case 'jsx':
        case 'mjs':
        case 'cjs': {
            const { javascript } = await import('@codemirror/lang-javascript');
            return javascript();
        }
        case 'ts':
        case 'tsx': {
            const { javascript } = await import('@codemirror/lang-javascript');
            return javascript({ typescript: true });
        }

        // HTML / templates
        case 'html':
        case 'htm':
        case 'svelte':
        case 'vue': {
            const { html } = await import('@codemirror/lang-html');
            return html();
        }

        // CSS
        case 'css': {
            const { css } = await import('@codemirror/lang-css');
            return css();
        }

        // JSON
        case 'json': {
            const { json } = await import('@codemirror/lang-json');
            return json();
        }

        // XML / YAML (XML covers YAML reasonably for highlighting)
        case 'xml':
        case 'svg':
        case 'rss': {
            const { xml } = await import('@codemirror/lang-xml');
            return xml();
        }

        // Markdown
        case 'md':
        case 'markdown': {
            const { markdown } = await import('@codemirror/lang-markdown');
            return markdown();
        }

        // Python
        case 'py':
        case 'pyw': {
            const { python } = await import('@codemirror/lang-python');
            return python();
        }

        // SQL
        case 'sql': {
            const { sql } = await import('@codemirror/lang-sql');
            return sql();
        }

        // C / C++
        case 'c':
        case 'cpp':
        case 'cc':
        case 'h':
        case 'hpp': {
            const { cpp } = await import('@codemirror/lang-cpp');
            return cpp();
        }

        // Java
        case 'java': {
            const { java } = await import('@codemirror/lang-java');
            return java();
        }

        // Rust
        case 'rs': {
            const { rust } = await import('@codemirror/lang-rust');
            return rust();
        }

        // Legacy-modes: PHP, Shell, YAML, INI, CSV, etc.
        case 'php': {
            const { html } = await import('@codemirror/lang-html');
            return html(); // HTML mode includes embedded PHP highlighting
        }
        case 'sh':
        case 'bash':
        case 'zsh':
        case 'ps1':
        case 'bat':
        case 'cmd': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { shell } = await import('@codemirror/legacy-modes/mode/shell');
            return StreamLanguage.define(shell) as unknown as LanguageSupport;
        }
        case 'yaml':
        case 'yml': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { yaml } = await import('@codemirror/legacy-modes/mode/yaml');
            return StreamLanguage.define(yaml) as unknown as LanguageSupport;
        }
        case 'ini':
        case 'cfg':
        case 'conf':
        case 'env':
        case 'toml': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { toml } = await import('@codemirror/legacy-modes/mode/toml');
            return StreamLanguage.define(toml) as unknown as LanguageSupport;
        }
        case 'rb': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { ruby } = await import('@codemirror/legacy-modes/mode/ruby');
            return StreamLanguage.define(ruby) as unknown as LanguageSupport;
        }
        case 'go': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { go } = await import('@codemirror/legacy-modes/mode/go');
            return StreamLanguage.define(go) as unknown as LanguageSupport;
        }
        case 'cs': {
            const { StreamLanguage } = await import('@codemirror/language');
            const { csharp } = await import('@codemirror/legacy-modes/mode/clike');
            return StreamLanguage.define(csharp) as unknown as LanguageSupport;
        }

        default:
            return null; // plaintext fallback — CM works fine without a language
    }
}
