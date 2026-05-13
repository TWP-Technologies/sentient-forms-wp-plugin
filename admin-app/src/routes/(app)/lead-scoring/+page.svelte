<script lang="ts">
	import RefreshCwIcon from '@lucide/svelte/icons/refresh-cw';
	import SearchIcon from '@lucide/svelte/icons/search';
	import SettingsIcon from '@lucide/svelte/icons/settings';
	import { onMount } from 'svelte';
	import { Alert, Button, ButtonLink, Card, Section } from '$lib/components/ui';
	import CorrectionModal from '$lib/components/lead-scoring/correction-modal.svelte';
	import DetailSheet from '$lib/components/lead-scoring/detail-sheet.svelte';
	import EntriesTable from '$lib/components/lead-scoring/entries-table.svelte';
	import GradeDistribution from '$lib/components/lead-scoring/grade-distribution.svelte';
	import SummaryStrip from '$lib/components/lead-scoring/summary-strip.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { appHref, navigateToAppPath } from '$lib/navigation';
	import type {
		LeadGrade,
		LeadScoringEntry,
		LeadScoringFormSummary,
		LeadValueDashboard
	} from '$lib/api/types';
	import { detailPath, providerLabel, setupJumpPath } from '$lib/components/lead-scoring/utils';
	import { leadScoringCorrectionSchema } from '$lib/schemas/lead-scoring';
	import { notifications } from '$lib/stores/notifications';

	const client = createClientFromConfig();

	let loading = $state(true);
	let error = $state<string | null>(null);
	let dashboard = $state<LeadValueDashboard | null>(null);
	let query = $state('');
	let page = $state(1);
	let selectedEntry = $state<LeadScoringEntry | null>(null);
	let correctionEntry = $state<LeadScoringEntry | null>(null);
	let correctionGrade = $state<LeadGrade>('B');
	let correctionJustification = $state('');
	let correcting = $state(false);
	let generatingManualReply = $state(false);

	const entries = $derived(dashboard?.entries ?? []);
	const gradeTotal = $derived(
		Object.values(dashboard?.grades ?? {}).reduce((total, count) => total + Number(count ?? 0), 0)
	);

	onMount(() => {
		void loadDashboard();
	});

	async function loadDashboard() {
		loading = true;
		error = null;
		try {
			dashboard = await client.getLeadScoringDashboard({
				page,
				per_page: 12,
				q: query.trim()
			});
			openRequestedEntry(dashboard.entries ?? []);
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead scoring dashboard failed to load.';
		} finally {
			loading = false;
		}
	}

	async function searchEntries() {
		page = 1;
		await loadDashboard();
	}

	async function setPage(nextPage: number) {
		page = Math.max(1, nextPage);
		await loadDashboard();
	}

	function setupHref(entry: Pick<LeadScoringEntry | LeadScoringFormSummary, 'form_source' | 'form_id'>) {
		return appHref(setupJumpPath(entry));
	}

	function openDetail(entry: LeadScoringEntry) {
		selectedEntry = entry;
	}

	function closeDetail() {
		selectedEntry = null;
		clearEntryQueryParams();
	}

	function openCorrection(entry: LeadScoringEntry) {
		correctionEntry = entry;
		correctionGrade = normalizeGrade(String(entry.grade ?? '')) ?? 'B';
		correctionJustification = '';
	}

	async function correctEntry() {
		if (!correctionEntry) return;
		const parsed = leadScoringCorrectionSchema.safeParse({
			grade: correctionGrade,
			justification: correctionJustification
		});
		if (!parsed.success) {
			error = parsed.error.issues[0]?.message ?? 'Enter a corrected grade and justification.';
			return;
		}

		correcting = true;
		error = null;
		try {
			const response = await client.correctLeadScoringEntry(
				correctionEntry.form_source,
				correctionEntry.form_id,
				correctionEntry.entry_id,
				parsed.data
			);
			dashboard = response.dashboard ?? dashboard;
			const updatedEntry = (response.entry as LeadScoringEntry | undefined) ?? correctionEntry;
			selectedEntry =
				selectedEntry?.entry_id === updatedEntry.entry_id &&
				String(selectedEntry.form_id) === String(updatedEntry.form_id)
					? updatedEntry
					: selectedEntry;
			correctionEntry = null;
			correctionJustification = '';
			notifications.success('Lead grade correction saved');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead grade correction could not be saved.';
		} finally {
			correcting = false;
		}
	}

	async function generateManualSuggestedReply(entry: LeadScoringEntry) {
		generatingManualReply = true;
		error = null;
		try {
			const response = await client.generateLeadSuggestedReply(
				entry.form_source,
				entry.form_id,
				entry.entry_id
			);
			dashboard = response.dashboard ?? dashboard;
			selectedEntry = (response.entry as LeadScoringEntry | undefined) ?? selectedEntry;
			notifications.success('Suggested reply generation started');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Suggested reply could not be generated.';
		} finally {
			generatingManualReply = false;
		}
	}

	function normalizeGrade(value: string): LeadGrade | null {
		const grade = value.trim().toUpperCase();
		if (grade === 'A' || grade === 'B' || grade === 'C') return grade;
		if (grade === 'F' || grade === 'REJECT' || grade === 'REJECTED') return 'Reject';
		return null;
	}

	function openRequestedEntry(candidates: LeadScoringEntry[]) {
		if (typeof window === 'undefined') return;
		const hashQuery = window.location.hash.includes('?')
			? (window.location.hash.split('?')[1] ?? '')
			: '';
		const params = new URLSearchParams(hashQuery || window.location.search);
		const requestedEntry = params.get('entry');
		if (!requestedEntry) return;
		const requestedFormSource = params.get('form_source');
		const requestedFormId = params.get('form_id');
		const match = candidates.find(
			(entry) =>
				String(entry.entry_id) === requestedEntry &&
				(!requestedFormSource || entry.form_source === requestedFormSource) &&
				(!requestedFormId || String(entry.form_id) === requestedFormId)
		);
		if (match) selectedEntry = match;
	}

	function clearEntryQueryParams() {
		if (typeof window === 'undefined') return;
		const url = new URL(window.location.href);
		if (url.hash.includes('?')) {
			const [hashPath, hashQuery = ''] = url.hash.split('?');
			const hashParams = new URLSearchParams(hashQuery);
			hashParams.delete('entry');
			hashParams.delete('form_source');
			hashParams.delete('form_id');
			const nextHashQuery = hashParams.toString();
			url.hash = nextHashQuery ? `${hashPath}?${nextHashQuery}` : hashPath;
		} else {
			url.searchParams.delete('entry');
			url.searchParams.delete('form_source');
			url.searchParams.delete('form_id');
		}
		window.history.replaceState(window.history.state, '', url);
	}
</script>

<Section
	heading="Lead Scoring"
	description="Review scored leads across every configured form, then open a form setup when scoring needs tuning."
>
	{#snippet actions()}
		<div class="sf:flex sf:min-w-max sf:flex-wrap sf:items-center sf:gap-3">
			<ButtonLink variant="secondary" href={appHref('/actions')}>All forms</ButtonLink>
			<Button variant="secondary" onclick={loadDashboard} disabled={loading}>
				<RefreshCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
				Refresh
			</Button>
		</div>
	{/snippet}

	{#if error}
		<Alert variant="danger" class="sf:mb-5">{error}</Alert>
	{/if}

	{#if loading}
		<div class="sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] sf:gap-4">
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
		</div>
	{:else}
		<SummaryStrip {dashboard} {gradeTotal} testId="lead-scoring-aggregate-summary" />

		<div class="sf:mt-12 sf:grid sf:gap-12 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
			<div class="sf:min-w-0 sf:space-y-6">
				<div>
					<h2 class="sf:text-3xl sf:font-bold sf:tracking-tight sf:text-slate-950">Scored entries</h2>
					<p class="sf:mt-3 sf:text-lg sf:text-slate-500">
						Justifications are clipped in the table so a long model response never stretches a row.
					</p>
				</div>

				<form
					class="sf:grid sf:gap-3 sf:lg:max-w-4xl sf:lg:grid-cols-[minmax(0,1fr)_9rem]"
					onsubmit={(event) => {
						event.preventDefault();
						void searchEntries();
					}}
				>
					<label class="sf:grid sf:gap-2">
						<span class="sf:text-base sf:font-medium sf:text-slate-800">Search entries</span>
						<div class="sf:relative">
							<SearchIcon
								class="sf:pointer-events-none sf:absolute sf:left-4 sf:top-1/2 sf:h-5 sf:w-5 sf:-translate-y-1/2 sf:text-slate-400"
								aria-hidden="true"
							/>
							<input
								class="sf:h-14 sf:w-full sf:rounded-lg sf:border sf:border-slate-300 sf:bg-white sf:pl-12 sf:pr-4 sf:text-lg sf:shadow-sm sf:placeholder:text-slate-400 sf:focus-visible:border-primary-500 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500/30"
								placeholder="Entry, form, grade, field, justification"
								bind:value={query}
							/>
						</div>
					</label>
					<Button variant="secondary" class="sf:h-14 sf:self-end" type="submit">Search</Button>
				</form>

				<EntriesTable
					{entries}
					basePath="/lead-scoring"
					showForm={true}
					onOpenDetail={openDetail}
					emptyMessage="No scored entries yet. Configure Lead Scoring on a form or run a historical score to populate this dashboard."
				/>

				<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-3">
					<p class="sf:text-sm sf:text-slate-500">
						Page {dashboard?.entry_page ?? 1} of {Math.max(1, dashboard?.entry_pages ?? 1)} · {dashboard?.entry_total ??
							0} entries
					</p>
					<div class="sf:flex sf:gap-2">
						<Button
							size="sm"
							variant="secondary"
							disabled={(dashboard?.entry_page ?? 1) <= 1}
							onclick={() => setPage((dashboard?.entry_page ?? 1) - 1)}
						>
							Previous
						</Button>
						<Button
							size="sm"
							variant="secondary"
							disabled={(dashboard?.entry_page ?? 1) >= (dashboard?.entry_pages ?? 1)}
							onclick={() => setPage((dashboard?.entry_page ?? 1) + 1)}
						>
							Next
						</Button>
					</div>
				</div>
			</div>

			<div class="sf:space-y-6">
				{#if (dashboard?.unconfigured_forms?.length ?? 0) > 0}
					<Card>
						<h2 class="sf:text-2xl sf:font-semibold sf:text-slate-950">Quick jump</h2>
						<p class="sf:mt-2 sf:text-base sf:text-slate-500">
							Forms that do not have Lead Scoring setup yet.
						</p>
						<div class="sf:mt-5 sf:space-y-3">
							{#each dashboard?.unconfigured_forms ?? [] as form (`unconfigured-${form.form_source}-${form.form_id}`)}
								<div
									class="sf:flex sf:items-center sf:justify-between sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-4"
								>
									<div class="sf:min-w-0">
										<p class="sf:truncate sf:text-base sf:font-semibold sf:text-slate-950">
											{form.form_title || `Form ${form.form_id}`}
										</p>
										<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
											{providerLabel(form.form_source, form.provider_label)}
										</p>
									</div>
									<ButtonLink size="sm" variant="secondary" class="sf:whitespace-nowrap" href={setupHref(form)}>
										<SettingsIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
										Set Up
									</ButtonLink>
								</div>
							{/each}
						</div>
					</Card>
				{/if}

				<Card>
					<h2 class="sf:text-2xl sf:font-semibold sf:text-slate-950">Configured forms</h2>
					<div class="sf:mt-5 sf:space-y-4">
						{#each dashboard?.forms ?? [] as form (`${form.form_source}-${form.form_id}`)}
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:p-5">
								<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-xl sf:font-semibold sf:text-slate-950">
											{form.form_title || `Form ${form.form_id}`}
										</p>
										<p class="sf:mt-4 sf:text-base sf:text-slate-500">
											{form.scored_leads} scored · {form.priority_leads} priority · {form.reply_drafts}
											drafts
										</p>
										{#if form.profile_id}
											<p class="sf:mt-3 sf:text-base sf:text-primary-600">
												Setup v{form.profile_version ?? 1} · {form.setup_status || 'draft'}
											</p>
										{/if}
									</div>
									<ButtonLink size="sm" variant="secondary" class="sf:whitespace-nowrap" href={setupHref(form)}>Setup</ButtonLink>
								</div>
							</div>
						{:else}
							<p class="sf:text-sm sf:text-slate-500">No forms have stored lead scoring results yet.</p>
						{/each}
					</div>
				</Card>

				<GradeDistribution grades={dashboard?.grades} />
			</div>
		</div>
	{/if}

	<DetailSheet
		entry={selectedEntry}
		onClose={closeDetail}
		onOpenCorrection={openCorrection}
		onGenerateReply={generateManualSuggestedReply}
		generatingReply={generatingManualReply}
	/>
	<CorrectionModal
		entry={correctionEntry}
		grade={correctionGrade}
		justification={correctionJustification}
		correcting={correcting}
		onClose={() => (correctionEntry = null)}
		onSave={correctEntry}
		onGradeChange={(grade) => (correctionGrade = grade)}
		onJustificationChange={(value) => (correctionJustification = value)}
	/>
</Section>
