import { mount } from 'svelte'
import Settings from './components/Settings/Settings.svelte'

const app = mount(Settings, {
  target: document.getElementById('anibas-fm-settings-root')!,
})

export default app
