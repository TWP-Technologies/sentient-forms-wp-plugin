<script lang="ts">
	import type { PluginSettingsResponse } from '$lib/api/types';
	import { Alert, Badge, Button } from '$lib/components/ui';

	type PrivacyPresetId =
		| 'balanced'
		| 'privacy_focused'
		| 'maximum_privacy'
		| 'maximum_visibility';

	interface PrivacyPresetDefinition {
		id: PrivacyPresetId;
		label: string;
		kicker: string;
		description: string;
		retentionLabel: string;
		fullOutputLabel: string;
		loggingLabel: string;
	}

	interface Props {
		open?: boolean;
		saving?: boolean;
		settings?: PluginSettingsResponse | null;
		dismissible?: boolean;
		onapply?: (preset: PrivacyPresetId) => void;
		onclose?: () => void;
	}

	const presetDefinitions: PrivacyPresetDefinition[] = [
		{
			id: 'balanced',
			label: 'Balanced',
			kicker: 'Recommended',
			description: 'Good default for most sites. Keeps useful troubleshooting without saving full AI replies.',
			retentionLabel: '90-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'privacy_focused',
			label: 'Privacy focused',
			kicker: 'Lower retention',
			description: 'Cuts back local history while keeping enough detail to verify that actions are working.',
			retentionLabel: '30-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'maximum_privacy',
			label: 'Maximum privacy',
			kicker: 'Minimum storage',
			description: 'Stores the least local AI detail after actions finish. Best for sensitive intake flows.',
			retentionLabel: '7-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'maximum_visibility',
			label: 'Maximum visibility',
			kicker: 'For tuning and support',
			description: 'Keeps more local detail so you can inspect outputs, compare prompts, and debug setups faster.',
			retentionLabel: '180-day execution logs',
			fullOutputLabel: 'Full AI outputs on',
			loggingLabel: 'On-site logging on'
		}
	];

	let {
		open = false,
		saving = false,
		settings = null,
		dismissible = false,
		onapply,
		onclose
	}: Props = $props();

	function initialPreset(settingsValue: PluginSettingsResponse | null | undefined): PrivacyPresetId {
		const candidate = settingsValue?.privacy_setup_profile;
		return presetDefinitions.some((preset) => preset.id === candidate)
			? (candidate as PrivacyPresetId)
			: 'balanced';
	}

	let selectedPreset = $state<PrivacyPresetId>(initialPreset(settings));
	let selectedDefinition = $derived(
		presetDefinitions.find((preset) => preset.id === selectedPreset) ?? presetDefinitions[0]
	);
	let completedAtLabel = $derived(settings?.privacy_setup_completed_at ?? null);

	$effect(() => {
		if (!open) return;
		selectedPreset = initialPreset(settings);
	});

	function handleBackdropClick(event: MouseEvent): void {
		if (!dismissible || saving) return;
		if (event.target !== event.currentTarget) return;
		onclose?.();
	}

	function handleKeydown(event: KeyboardEvent): void {
		if (!open || !dismissible || saving) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			onclose?.();
		}
	}

	function applySelectedPreset(): void {
		onapply?.(selectedPreset);
	}

	function useBalancedDefaults(): void {
		onapply?.('balanced');
	}
</script>

<svelte:window onkeydown={handleKeydown} />

{#if open}
	<div
		class="sf:fixed sf:inset-0 sf:z-[1200] sf:flex sf:items-start sf:justify-center sf:overflow-y-auto sf:bg-slate-950/45 sf:p-4 sf:sm:p-6"
		role="presentation"
		onclick={handleBackdropClick}
		data-testid="privacy-setup-assistant-backdrop"
	>
		<div
			class="sf:my-6 sf:w-full sf:max-w-5xl sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-2xl"
			role="dialog"
			aria-modal="true"
			aria-labelledby="privacy-setup-assistant-title"
			data-testid="privacy-setup-assistant"
		>
			<div class="sf:border-b sf:border-slate-200 sf:bg-slate-950 sf:px-5 sf:py-5 sf:text-white sf:sm:px-6">
				<div class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3">
					<div class="sf:max-w-3xl sf:space-y-2">
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							<Badge variant="info">Privacy setup</Badge>
							{#if completedAtLabel}
								<Badge variant="neutral">Updated before</Badge>
							{/if}
						</div>
						<h2 id="privacy-setup-assistant-title" class="sf:text-xl sf:font-semibold sf:text-white">
							Choose how much Sentient Forms keeps locally
						</h2>
						<p class="sf:max-w-2xl sf:text-sm sf:text-slate-200">
							These defaults change how long execution history stays on this WordPress site, whether
							full AI replies are saved, and what gets removed on uninstall. You can change them later
							in Settings.
						</p>
					</div>
					{#if dismissible}
						<Button
							variant="secondary"
							size="sm"
							class="sf:border-white/20 sf:bg-white/10 sf:text-white sf:hover:bg-white/15"
							disabled={saving}
							onclick={() => onclose?.()}
						>
							Close
						</Button>
					{/if}
				</div>
			</div>

			<div class="sf:space-y-6 sf:p-5 sf:sm:p-6">
					<div class="sf:grid sf:gap-3 sf:lg:grid-cols-4">
						{#each presetDefinitions as preset}
							<Button
								type="button"
								variant="secondary"
								size="md"
								class={`sf:h-full sf:w-full sf:flex-col sf:items-start sf:rounded-lg sf:p-4 sf:text-left sf:transition-all ${
									selectedPreset === preset.id
										? 'sf:border-primary-600 sf:bg-primary-50 sf:shadow-md sf:ring-2 sf:ring-primary-500 sf:ring-offset-2 sf:ring-offset-white sf:hover:border-primary-600 sf:hover:bg-primary-50'
										: 'sf:border-slate-200 sf:bg-white sf:hover:border-slate-300 sf:hover:bg-slate-50'
								}`}
								aria-pressed={selectedPreset === preset.id}
								onclick={() => {
									selectedPreset = preset.id;
								}}
								data-testid={`privacy-setup-preset-${preset.id}`}
							>
								<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
									<p class="sf:text-base sf:font-semibold sf:text-slate-900">{preset.label}</p>
									<Badge variant={preset.id === 'balanced' ? 'success' : 'neutral'}>
										{preset.kicker}
									</Badge>
									{#if selectedPreset === preset.id}
										<Badge variant="info">Selected</Badge>
									{/if}
							</div>
							<p class="sf:mt-2 sf:text-sm sf:text-slate-600">{preset.description}</p>
								<ul class="sf:mt-4 sf:space-y-2 sf:text-xs sf:text-slate-500">
									<li>{preset.retentionLabel}</li>
									<li>{preset.fullOutputLabel}</li>
									<li>{preset.loggingLabel}</li>
								</ul>
							</Button>
						{/each}
					</div>

				<div class="sf:grid sf:gap-4 sf:xl:grid-cols-[1.2fr_0.8fr]">
					<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4 sf:space-y-4">
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
								{selectedDefinition.label} changes
							</p>
							<Badge variant="info">{selectedDefinition.kicker}</Badge>
						</div>
						<div class="sf:grid sf:gap-3 sf:sm:grid-cols-3">
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">Execution logs</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.retentionLabel}
								</p>
							</div>
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">AI reply storage</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.fullOutputLabel}
								</p>
							</div>
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">Diagnostics</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.loggingLabel}
								</p>
							</div>
						</div>
						<p class="sf:text-sm sf:text-slate-600">
							All presets still keep action definitions, mappings, and provider setup on this site
							until you delete them. The difference is how much execution history stays available for
							review.
						</p>
					</div>

					<div class="sf:space-y-3">
						<Alert variant="info">
							<p class="sf:font-semibold">High-sensitivity secret handling</p>
							<p class="sf:mt-1">
								Saved provider keys are encrypted locally with server-side WordPress secrets. If you
								need stronger operational control, configure the provider through a WordPress constant
								or environment variable instead of storing the key in the database.
							</p>
							<p class="sf:mt-2">
								If WordPress salts change later, encrypted saved keys will need to be entered again.
							</p>
						</Alert>

						<Alert variant="warning">
							<p class="sf:font-semibold">OpenRouter privacy note</p>
							<p class="sf:mt-1">
								Zero Data Retention depends on the specific OpenRouter route and upstream model
								provider. Many free routes have different retention or training policies, so review the
								provider privacy terms before using them on sensitive forms.
							</p>
						</Alert>
					</div>
				</div>

				<div class="sf:flex sf:flex-col sf:gap-3 sf:border-t sf:border-slate-200 sf:pt-5 sf:md:flex-row sf:md:items-center sf:md:justify-between">
					<p class="sf:min-w-0 sf:text-sm sf:text-slate-500">
						Skip Customized Setup applies the recommended Balanced defaults and keeps the plugin ready
						to use immediately.
					</p>
					<div class="sf:flex sf:shrink-0 sf:flex-nowrap sf:gap-2">
						<Button variant="secondary" disabled={saving} onclick={useBalancedDefaults}>
							Skip setup
						</Button>
						<Button
							class="sf:min-w-[9rem]"
							loading={saving}
							disabled={saving}
							onclick={applySelectedPreset}
						>
							{saving ? 'Saving...' : `Apply ${selectedDefinition.label}`}
						</Button>
					</div>
				</div>
			</div>
		</div>
	</div>
{/if}
