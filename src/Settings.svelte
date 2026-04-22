<script lang="ts">
  import { onMount } from "svelte"
  import PasswordPrompt from "./components/Settings/PasswordPrompt.svelte"
  import SettingsForm from "./components/Settings/SettingsForm.svelte"
  import "./app.css"

  const config = (window as any).AnibasFMSettings
  const TOKEN_KEY = 'anibas_fm_token'
  
  let authenticated = $state(false)
  let loading = $state(true)
  let error = $state<string | null>(null)
  let authToken = $state<string | null>(null)

  onMount(async () => {
    if (!config.hasPassword) {
      authenticated = true
      loading = false
      return
    }

    const token = sessionStorage.getItem(TOKEN_KEY)
    if (token) {
      await checkAuth(token)
    } else {
      loading = false
    }
  })

  async function checkAuth(token: string) {
    try {
      const formData = new FormData()
      formData.append('action', 'anibas_fm_check_auth')
      formData.append('nonce', config.nonce)
      formData.append('token', token)

      const res = await fetch(config.ajaxURL, {
        method: 'POST',
        body: formData
      })
      
      const json = await res.json()
      
      if (json.success) {
        authenticated = true
        authToken = token
      } else {
        sessionStorage.removeItem(TOKEN_KEY)
      }
    } catch (err) {
      sessionStorage.removeItem(TOKEN_KEY)
    } finally {
      loading = false
    }
  }

  async function handlePasswordSubmit(password: string) {
    loading = true
    error = null
    
    try {
      const formData = new FormData()
      formData.append('action', 'anibas_fm_verify_password')
      formData.append('nonce', config.nonce)
      formData.append('password', password)

      const res = await fetch(config.ajaxURL, {
        method: 'POST',
        body: formData
      })
      
      const json = await res.json()
      
      if (json.success) {
        authenticated = true
        authToken = json.data.token
        sessionStorage.setItem(TOKEN_KEY, json.data.token)
      } else {
        error = json.data || 'Invalid password'
      }
    } catch (err: any) {
      error = err.message || 'Failed to verify password'
    } finally {
      loading = false
    }
  }

  function handlePasswordChanged() {
    sessionStorage.removeItem(TOKEN_KEY)
    authToken = null
    config.hasPassword = true
    authenticated = false
  }
</script>

<div class="anibas-fm-settings">
  {#if loading}
    <div class="text-center p-5">
      <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
    </div>
  {:else if !authenticated}
    <PasswordPrompt 
      {loading} 
      {error} 
      onSubmit={handlePasswordSubmit} 
    />
  {:else}
    <SettingsForm {authToken} onPasswordChanged={handlePasswordChanged} />
  {/if}
</div>

<style>
  .anibas-fm-settings {
    max-width: 1200px;
    margin: 20px 0;
  }
</style>
