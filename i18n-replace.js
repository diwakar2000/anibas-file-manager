const fs = require('fs');
const glob = require('glob'); // Note: we can use fs.promises.readdir recursive if node >= 20

function processFile(filePath) {
    let content = fs.readFileSync(filePath, 'utf8');
    let original = content;

    // 1. Add import statement if not present, but only if we make changes or find English strings
    const importStmt = "import { __ } from '../../utils/i18n';"; // Might need path adjustment
    
    // Simplification for the import path:
    const depth = filePath.split('/').length - 2; 
    let importPath = "'../".repeat(depth) + "utils/i18n'";
    if (depth === 0) importPath = "'./utils/i18n'";
    
    const statement = `import { __ } from ${importPath};`;

    // 2. Replace placeholders: placeholder="Some text" -> placeholder={__('Some text')}
    content = content.replace(/placeholder="([^"{]+)"/g, (match, p1) => {
        if (!/[a-zA-Z]/.test(p1)) return match;
        return `placeholder={__('${p1}')}`;
    });

    // 3. Replace title / data-tooltip
    content = content.replace(/data-tooltip="([^"{]+)"/g, (match, p1) => {
        if (!/[a-zA-Z]/.test(p1)) return match;
        return `data-tooltip={__('${p1}')}`;
    });
    
    content = content.replace(/aria-label="([^"{]+)"/g, (match, p1) => {
        if (!/[a-zA-Z]/.test(p1)) return match;
        return `aria-label={__('${p1}')}`;
    });

    // 4. Replace tag texts: > Some text < -> >{__('Some text')}<
    // Using a regex to find text nodes that don't have '{' or '}' and have alphanumeric characters
    // We only replace if there's actual letters.
    // Also skip things that are just spaces or JS code, or SVG elements usually don't have text nodes like this, but we'll be careful.
    content = content.replace(/>\s*([^<>{]+?)\s*</g, (match, text) => {
        if (!/[a-zA-Z]/.test(text)) return match;
        // Don't translate HTML entities blindly, or single words like "x"? 
        // We'll just translate if there's some text.
        let trimmed = text.trim();
        // Skip some common non-text patterns
        if (['&times;', '→', '...', '⏳'].includes(trimmed)) return match;
        // Also skip script / style blocks: wait, regex might match inside script? 
        // We shouldn't do regex blindly on the whole file HTML part.
        
        return match.replace(text, `{\`\${__('${trimmed.replace(/'/g, "\\'")}')}\`}`);
    });

    if (content !== original) {
        console.log("Modifications made in " + filePath);
        // Inject import if not exists
        if (!content.includes('import { __ }')) {
            content = content.replace('<script lang="ts">', `<script lang="ts">\n\t${statement}`);
            content = content.replace('<script>', `<script>\n\t${statement}`);
        }
        // Actually, let's just log what we WOULD have written to stdout to verify!
        // fs.writeFileSync(filePath, content, 'utf8');
    }
}

processFile('src/components/Explorer/Toolbar.svelte');
