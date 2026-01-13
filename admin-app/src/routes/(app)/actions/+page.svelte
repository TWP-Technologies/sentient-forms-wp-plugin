<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Badge, Alert, Skeleton } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		ActionDefinition,
		ActionCategory,
		FormSourceSummary,
		FormSummary
	} from '$lib/api/types';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { groupDefinitionsByCategory, getCategoryMeta } from '$lib/utils/action-categories';

	const client = createClientFromConfig();
	const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;
	const formSources: FormSourceSummary[] = runtime?.formSources ?? [];

	let definitions = $state<ActionDefinition[]>([]);
	let definitionsLoading = $state(false);
	let formsBySource = $state<Record<string, FormSummary[]>>({});
	let formsLoading = $state(false);
	let selectedSource = $state<FormSourceSummary | null>(
		formSources.find((source) => source.isActive) ?? formSources[0] ?? null
	);
	let error: string | null = $state(null);

	const activeSources = $derived(formSources.filter((source) => source.isActive));
	const customActions = $derived(
		customActionsState.actions.filter((action) => action.status === 'active')
	);

	const displayedForms = $derived(
		selectedSource
			? (formsBySource[selectedSource.slug] ?? [])
			: Object.values(formsBySource).flat()
	);

	// Group definitions by category
	const groupedDefinitions = $derived(groupDefinitionsByCategory(definitions));
	const categoryOrder: ActionCategory[] = [
		'content_quality',
		'data_processing',
		'automation',
		'custom'
	];

	const definitionsBadgeVariant = $derived(
		definitions.some((definition) => definition.source === 'cps') ? 'success' : 'warning'
	);
	const definitionsBadgeLabel = $derived(
		definitions.some((definition) => definition.source === 'cps')
			? 'CPS templates'
			: 'Local templates'
	);

	function friendlyMessageFromError(err: unknown, fallback: string): string {
		if (err instanceof ApiClientError) {
			const payload = err.payload as { message?: string } | null;
			return payload?.message ?? err.message ?? fallback;
		}
		if (err instanceof Error) return err.message ?? fallback;
		return fallback;
	}

	async function loadDefinitions() {
		definitionsLoading = true;
		error = null;
		try {
			definitions = await client.getActionDefinitions({ showNotifications: false });
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load CPS templates');
			definitions = [];
		} finally {
			definitionsLoading = false;
		}
	}

	async function loadForms() {
		if (activeSources.length === 0) {
			formsBySource = {};
			return;
		}

		formsLoading = true;
		error = null;

		try {
			const results = await Promise.all(
				activeSources.map(async (source) => {
					const forms = await client.getForms(source.slug, { showNotifications: false });
					return [source.slug, forms] as const;
				})
			);

			formsBySource = Object.fromEntries(results);
			if (!selectedSource || !selectedSource.isActive) {
				selectedSource = activeSources[0] ?? null;
			}
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load forms');
			formsBySource = {};
		} finally {
			formsLoading = false;
		}
	}

	function configuredActionCount(form: FormSummary): number {
		const actions =
			form.settings && typeof form.settings === 'object'
				? (form.settings as Record<string, unknown>)['actions']
				: null;
		if (actions && typeof actions === 'object') {
			return Object.keys(actions as Record<string, unknown>).length;
		}
		return 0;
	}

	function openFormDetail(form: FormSummary) {
		if (!selectedSource) return;
		navigateToAppPath(`/actions/${selectedSource.slug}/${form.id}`);
	}

	function refreshAll() {
		loadDefinitions();
		loadForms();
		customActionsStore.reload();
	}

	onMount(() => {
		loadDefinitions();
		loadForms();
		customActionsStore.load({ status: 'active' });
	});
</script>

<Section
	heading="Actions"
	description="Pair CPS templates and custom actions with your active forms."
>
	<div slot="actions" class="sf:flex sf:flex-wrap sf:gap-2">
		<Button variant="secondary" onclick={refreshAll}>Refresh</Button>
		<Button variant="secondary" onclick={() => navigateToAppPath('/actions/custom')}>
			Manage custom actions
		</Button>
	</div>

	<div class="sf:grid sf:gap-4 sf:lg:grid-cols-3">
		<Card>
			<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Built-in actions</p>
					<p class="sf:text-xs sf:text-slate-500">CPS templates available to map.</p>
				</div>
				<Badge variant={definitionsBadgeVariant}>{definitionsBadgeLabel}</Badge>
			</div>
			{#if definitionsLoading}
				<div class="sf:mt-3 sf:space-y-2">
					{#each Array(3) as _, idx}
						<div class="sf:flex sf:items-center sf:gap-2" aria-label={`template-skeleton-${idx}`}>
							<Skeleton className="sf:h-3 sf:w-32" />
							<Skeleton className="sf:h-3 sf:w-12" />
						</div>
					{/each}
				</div>
			{:else if definitions.length === 0}
				<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
					No templates loaded yet. Refresh or check CPS connectivity.
				</p>
			{:else}
				<div class="sf:mt-3 sf:space-y-3">
					{#each categoryOrder as category}
						{@const items = groupedDefinitions.get(category) ?? []}
						{#if items.length > 0}
							{@const meta = getCategoryMeta(category)}
							<div>
								<p
									class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide sf:mb-1"
								>
									{meta.icon}
									{meta.label}
								</p>
								<ul class="sf:space-y-1">
									{#each items.slice(0, 3) as definition (definition.id)}
										<li class="sf:flex sf:items-start sf:justify-between sf:gap-2">
											<div>
												<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
													{definition.label ?? definition.id}
												</p>
											</div>
											<Badge variant={definition.source === 'cps' ? 'success' : 'warning'}>
												{definition.source === 'cps' ? 'CPS' : 'Local'}
											</Badge>
										</li>
									{/each}
								</ul>
							</div>
						{/if}
					{/each}
				</div>
			{/if}
		</Card>

		<Card>
			<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Custom actions</p>
					<p class="sf:text-xs sf:text-slate-500">Tenant-specific automations.</p>
				</div>
				<Badge variant="info">{customActions.length} active</Badge>
			</div>
			{#if customActions.length === 0}
				<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
					No custom actions yet. Create one to tailor responses to this site.
				</p>
			{:else}
				<ul class="sf:mt-3 sf:space-y-2">
					{#each customActions.slice(0, 4) as action (action.id)}
						<li class="sf:flex sf:items-center sf:justify-between sf:gap-2">
							<div>
								<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{action.display_name}</p>
								<p class="sf:text-xs sf:text-slate-500">Code: {action.code}</p>
							</div>
							<Badge variant="success">Active</Badge>
						</li>
					{/each}
				</ul>
			{/if}
		</Card>

		<Card>
			<p class="sf:text-sm sf:font-medium sf:text-slate-700">Form providers</p>
			{#if formSources.length === 0}
				<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
					Install and activate a supported form builder (like Gravity Forms) to start mapping
					actions.
				</p>
			{:else}
				<div class="sf:flex sf:flex-wrap sf:gap-2 sf:mt-3">
					{#each formSources as source}
						<Badge variant={source.isActive ? 'success' : 'warning'}>
							{source.label}
						</Badge>
					{/each}
				</div>
				{#if activeSources.length === 0}
					<Alert variant="warning" class="sf:mt-3">
						Activate at least one form provider to configure actions.
					</Alert>
				{/if}
			{/if}
		</Card>
	</div>

	{#if error}
		<Alert variant="danger" class="sf:mt-4">
			<div class="sf:flex sf:flex-col sf:sm:flex-row sf:items-start sf:sm:items-center sf:gap-3">
				<span>{error}</span>
				<Button size="sm" variant="secondary" onclick={refreshAll}>Retry</Button>
			</div>
		</Alert>
	{/if}

	{#if activeSources.length === 0}
		<Alert variant="warning" class="sf:mt-4">
			Install and activate a supported form builder (like Gravity Forms) to start mapping Sentient
			Forms actions.
		</Alert>
	{:else}
		<Card class="sf:mt-4">
			<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					{#each activeSources as source}
						<Button
							size="sm"
							variant={selectedSource?.slug === source.slug ? 'primary' : 'secondary'}
							onclick={() => (selectedSource = source)}
						>
							{source.label}
						</Button>
					{/each}
				</div>
				<Button size="sm" variant="secondary" onclick={loadForms} disabled={formsLoading}>
					Refresh forms
				</Button>
			</div>

			{#if formsLoading}
				<div class="sf:mt-4 sf:grid sf:gap-4 sf:md:grid-cols-2 sf:xl:grid-cols-3">
					{#each Array(6) as _, idx}
						<Card aria-label={`form-skeleton-${idx}`}>
							<Skeleton className="sf:h-4 sf:w-3/4" />
							<Skeleton className="sf:mt-2 sf:h-3 sf:w-1/2" />
							<Skeleton className="sf:mt-4 sf:h-3 sf:w-2/3" />
							<Skeleton className="sf:mt-4 sf:h-8 sf:w-full" />
						</Card>
					{/each}
				</div>
			{:else if displayedForms.length === 0}
				<p class="sf:mt-4 sf:text-sm sf:text-slate-600">
					No forms detected for {selectedSource?.label ?? 'this provider'}. Create a form first,
					then refresh this page.
				</p>
			{:else}
				<div class="sf:mt-4 sf:grid sf:gap-4 sf:md:grid-cols-2 sf:xl:grid-cols-3">
					{#each displayedForms as form (form.id)}
						<Card>
							<div class="sf:flex sf:justify-between sf:items-start sf:gap-3">
								<div>
									<p class="sf:font-semibold sf:text-slate-800">{form.title}</p>
									<p class="sf:text-xs sf:text-slate-500">Form ID: {form.id}</p>
								</div>
								<Badge variant={configuredActionCount(form) > 0 ? 'success' : 'neutral'}>
									{configuredActionCount(form) > 0
										? `${configuredActionCount(form)} mapped`
										: 'No mappings'}
								</Badge>
							</div>
							<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
								{selectedSource?.label ?? form.adapter_name ?? form.adapter} ·
								{configuredActionCount(form) === 1
									? '1 action mapped'
									: `${configuredActionCount(form)} actions mapped`}
							</p>
							<div class="sf:mt-4 sf:flex sf:justify-between sf:items-center">
								<span class="sf:text-xs sf:text-slate-500">
									{form.settings && (form.settings as { enabled?: boolean })?.enabled
										? 'Sentient Forms enabled'
										: 'Sentient Forms disabled'}
								</span>
								<Button size="sm" onclick={() => openFormDetail(form)}>Configure</Button>
							</div>
						</Card>
					{/each}
				</div>
			{/if}
		</Card>
	{/if}
</Section>
