<script lang="ts">
	import SettingsForm from "../SettingsForm.svelte"

	let { authToken, onPasswordChanged } = $props<{ authToken: string | null, onPasswordChanged: (hasPassword: boolean) => void }>()
</script>

<SettingsForm {authToken} {onPasswordChanged} />
