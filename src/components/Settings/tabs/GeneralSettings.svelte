<script lang="ts">
	import SettingsForm from "../SettingsForm.svelte"

	let { authToken, onPasswordChanged } = $props<{ authToken: string | null, onPasswordChanged: () => void }>()
</script>

<SettingsForm {authToken} {onPasswordChanged} />
