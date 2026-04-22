import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import { resolve } from 'path'

// Two build groups. Running each produces its own bundle graph, so modules
// shared by entries within a group become shared chunks (good — e.g. codemirror
// between main and editor), while modules shared across groups are duplicated
// into each entry (good — e.g. BackupModal ends up inlined into both main.js
// and settings.js rather than becoming a separate BackupModal-*.js chunk that
// WP can't easily enqueue).
const ENTRY_GROUPS = {
  app: {
    main:   resolve(__dirname, 'src/main.ts'),
    editor: resolve(__dirname, 'src/editor.ts'),
  },
  settings: {
    settings: resolve(__dirname, 'src/settings.ts'),
  },
} as const

export default defineConfig(() => {
  const group = (process.env.VITE_ENTRY_GROUP || 'app') as keyof typeof ENTRY_GROUPS
  const input = ENTRY_GROUPS[group] ?? ENTRY_GROUPS.app
  // With only one entry in a group, Rollup has nothing to share — let it inline
  // everything into the entry bundle instead of emitting tiny named chunks.
  const splitShared = Object.keys(input).length > 1
  // In `--watch`, the two groups run concurrently — emptying dist would race and
  // wipe the other group's outputs. Only clean on a one-shot build of the app group.
  const isWatch = process.argv.includes('--watch') || process.argv.includes('-w')

  return {
    clearScreen: false,
    plugins: [
      svelte({
        compilerOptions: {
          runes: true
        }
      })
    ],
    base: './',
    build: {
      outDir: 'dist',
      emptyOutDir: group === 'app' && !isWatch,
      rollupOptions: {
        input,
        output: {
          entryFileNames: '[name].js',
          chunkFileNames: '[name]-[hash].js',
          assetFileNames: (assetInfo) => {
            const name = assetInfo.names?.[0] || ''
            if (/\.css$/.test(name)) return '[name].css'
            if (/\.(woff2?)$/.test(name)) return 'fonts/[name][extname]'
            return '[name][extname]'
          },
          manualChunks(id) {
            if (id.includes('@codemirror/') || id.includes('@lezer/')) {
              return 'codemirror'
            }
            if (!splitShared) return
            if (id.includes('/node_modules/svelte/')) {
              return 'svelte'
            }
            if (id.includes('preload-helper')) {
              return 'vite-preload'
            }
            if (id.includes('/components/Editor/editorLanguage')) {
              return 'editor-language'
            }
          },
        }
      }
    }
  }
})
