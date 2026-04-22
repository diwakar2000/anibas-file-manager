import './types'
import { mount } from 'svelte'
import App from './App.svelte'

function initApp() {
  const target = document.getElementById('anibas-file-manager-app')

  if (!target) {
    console.error('Target element #anibas-file-manager-app not found')
    return
  }

  try {
    mount(App, { target })
  } catch (error) {
    console.error('Failed to initialize Anibas File Manager:', error)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp)
} else {
  initApp()
}
