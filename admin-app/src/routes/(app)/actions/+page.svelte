<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import Alert from '$lib/components/ui/alert.svelte';
	import { Section, Button, Card, Badge } from '$lib/components/ui';
import { ApiClientError, createClientFromConfig } from '$lib/api/client';
import type { FormSourceSummary, FormSummary } from '$lib/api/types';

	const client = createClientFromConfig();
	const runtime = window.sentientFormsConfig;
	const formSources: FormSourceSummary[] = runtime?.formSources ?? [];
	const formsCache = new Map<string, FormSummary[]>();

	let selectedSource: FormSourceSummary | null =
		formSources.find((source) => source.isActive) ?? formSources[0] ?? null;
	let forms: FormSummary[] = [];
	let loading = false;
	let error: string | null = null;

	const defaultRouteBase = '/actions';

	function friendlyMessageFromError(error: unknown, fallback: string): string {
		if (error instanceof ApiClientError) {
			const payload = error.payload as { message?: string } | null;
			if (payload?.message) {
				return payload.message;
			}
			return error.message ?? fallback;
		}

		if (error instanceof Error) {
			return error.message ?? fallback;
		}

		return fallback;
	}

	async function loadFormsForSource(source: FormSourceSummary): Promise<void> {
		if (!source) {
			forms = [];
			return;
		}

		const cached = formsCache.get(source.slug);
		if (cached) {
			forms = cached;
		}

		loading = true;
		error = null;

		try {
			const result = await client.getForms(source.slug);
			formsCache.set(source.slug, result);
			forms = result;
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load forms');
			forms = [];
		}

		loading = false;
	}

	function selectSource(source: FormSourceSummary): void {
		if (selectedSource?.slug === source.slug) {
			return;
		}
		selectedSource = source;
		loadFormsForSource(source);
	}

	function configuredActionCount(form: FormSummary): number {
		const actions = form.settings && typeof form.settings === 'object' ? (form.settings as Record<string, unknown>)['actions'] : null;
		if (actions && typeof actions === 'object') {
			return Object.keys(actions as Record<string, unknown>).length;
		}
		return 0;
	}

	function openFormDetail(form: FormSummary): void {
		if (!selectedSource) {
			return;
		}
		goto(`${defaultRouteBase}/${selectedSource.slug}/${form.id}`);
	}

	const hasSources = formSources.length > 0;

	onMount(() => {
		if (selectedSource) {
			loadFormsForSource(selectedSource);
		}
	});

	$: selectedSourceInactive = selectedSource ? !selectedSource.isActive : false;
	$: canRefresh = Boolean(selectedSource);
</script>

<Section heading="Actions" description="Configure CPS-backed workflows for your form submissions.">
	<div slot="actions" class="sf-flex sf-flex-wrap sf-gap-2">
		{#if formSources.length > 1}
		{#each formSources as source}
			<Button
				variant={selectedSource?.slug === source.slug ? 'primary' : 'secondary'}
				onclick={() => selectSource(source)}
			>
					{source.label}
				</Button>
			{/each}
		{/if}
{#if canRefresh}
			<Button variant="secondary" onclick={() => selectedSource && loadFormsForSource(selectedSource)}>
				Refresh
			</Button>
		{/if}
	</div>

	{#if !hasSources}
		<Alert variant="warning">
			Install and activate a supported form builder (like Gravity Forms) to start mapping Sentient Forms actions.
		</Alert>
	{:else}
		{#if selectedSourceInactive}
			<Alert variant="warning" class="sf-mb-4">
				Activate {selectedSource?.label ?? 'this form provider'} to manage action mappings.
			</Alert>
		{/if}

		{#if error}
			<Alert variant="danger" class="sf-mb-4">
				<div class="sf-flex sf-flex-col sf-gap-2 sm:sf-flex-row sm:sf-items-center sm:sf-justify-between">
					<span>{error}</span>
<Button size="sm" variant="secondary" onclick={() => selectedSource && loadFormsForSource(selectedSource)}>
						Retry
					</Button>
				</div>
			</Alert>
		{/if}

		{#if loading}
			<p class="sf-text-sm sf-text-slate-600">Loading forms…</p>
		{:else if (forms.length === 0)}
			<Card>
				<p class="sf-text-sm sf-text-slate-600">
					No forms detected for {selectedSource?.label ?? 'this provider'}. Create a form first, then refresh this page.
				</p>
			</Card>
		{:else}
			<div class="sf-grid sf-gap-4 md:sf-grid-cols-2 xl:sf-grid-cols-3">
				{#each forms as form (form.id)}
					<Card>
						<div class="sf-flex sf-justify-between sf-items-start sf-gap-3">
							<div>
								<p class="sf-font-semibold sf-text-slate-800">{form.title}</p>
								<p class="sf-text-xs sf-text-slate-500">Form ID: {form.id}</p>
							</div>
							<Badge variant={configuredActionCount(form) > 0 ? 'success' : 'neutral'}>
								{configuredActionCount(form) > 0 ? `${configuredActionCount(form)} mapped` : 'No mappings'}
							</Badge>
						</div>
						<p class="sf-mt-2 sf-text-sm sf-text-slate-600">
							{selectedSource?.label} · {configuredActionCount(form) === 1 ? '1 action mapped' : `${configuredActionCount(form)} actions mapped`}
						</p>
						<div class="sf-mt-4 sf-flex sf-justify-between sf-items-center">
							<span class="sf-text-xs sf-text-slate-500">
								{form.settings && (form.settings as Record<string, unknown>)['enabled'] ? 'Sentient Forms enabled' : 'Sentient Forms disabled'}
							</span>
					<Button size="sm" onclick={() => openFormDetail(form)}>Configure</Button>
						</div>
					</Card>
				{/each}
			</div>
		{/if}
	{/if}
</Section>
