<script lang="ts">
	import { onMount } from 'svelte';
	import { Badge, Button, Card, InputField, Section } from '$lib/components/ui';
	import { createClientFromConfig } from '$lib/api/client';
	import { navigateToAppPath } from '$lib/navigation';
	import type {
		LeadGrade,
		LeadScoringEntry,
		LeadScoringFormSummary,
		LeadValueDashboard
	} from '$lib/api/types';

	const client = createClientFromConfig();
	const gradeOrder: Array<LeadGrade | 'ungraded'> = ['A', 'B', 'C', 'Reject', 'ungraded'];

	let loading = $state(true);
	let error = $state<string | null>(null);
	let dashboard = $state<LeadValueDashboard | null>(null);
	let query = $state('');
	let page = $state(1);
	let selectedEntry = $state<LeadScoringEntry | null>(null);

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

	function setupPath(entry: Pick<LeadScoringEntry | LeadScoringFormSummary, 'form_source' | 'form_id'>) {
		return `/actions/${entry.form_source}/${entry.form_id}/lead-value`;
	}

	function setupJumpPath(entry: Pick<LeadScoringEntry | LeadScoringFormSummary, 'form_source' | 'form_id'>) {
		return `${setupPath(entry)}?view=setup`;
	}

	function providerLabel(source: string, label?: string | null) {
		if (label) return label;
		if (source === 'gravity_forms' || source === 'gravity-forms') return 'Gravity Forms';
		return source
			.split(/[_-]+/)
			.filter(Boolean)
			.map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`)
			.join(' ');
	}

	function entryDateValue(entry: LeadScoringEntry) {
		return entry.entry_snapshot?.date_created ?? entry.updated_at ?? '';
	}

	function parseDateTime(value?: string | null) {
		if (!value) return null;
		const normalized = value.includes('T') ? value : value.replace(' ', 'T');
		const hasZone = /(?:Z|[+-]\d\d:?\d\d)$/.test(normalized);
		const date = new Date(hasZone ? normalized : `${normalized}Z`);
		return Number.isNaN(date.getTime()) ? null : date;
	}

	function absoluteDateTime(value?: string | null) {
		const date = parseDateTime(value);
		return date
			? new Intl.DateTimeFormat(undefined, {
					dateStyle: 'medium',
					timeStyle: 'short'
				}).format(date)
			: '';
	}

	function relativeDateTime(value?: string | null) {
		const date = parseDateTime(value);
		if (!date) return '';
		const seconds = Math.round((date.getTime() - Date.now()) / 1000);
		const absSeconds = Math.abs(seconds);
		const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
		if (absSeconds < 60) return formatter.format(seconds, 'second');
		const minutes = Math.round(seconds / 60);
		if (Math.abs(minutes) < 60) return formatter.format(minutes, 'minute');
		const hours = Math.round(minutes / 60);
		if (Math.abs(hours) < 24) return formatter.format(hours, 'hour');
		const days = Math.round(hours / 24);
		if (Math.abs(days) < 30) return formatter.format(days, 'day');
		const months = Math.round(days / 30);
		if (Math.abs(months) < 12) return formatter.format(months, 'month');
		return formatter.format(Math.round(months / 12), 'year');
	}

	function gradeTone(grade: LeadGrade | 'ungraded') {
		if (grade === 'A') return 'sf:bg-emerald-500';
		if (grade === 'B') return 'sf:bg-sky-500';
		if (grade === 'C') return 'sf:bg-amber-500';
		if (grade === 'Reject') return 'sf:bg-rose-500';
		return 'sf:bg-slate-300';
	}

	function gradeWidth(grade: LeadGrade | 'ungraded') {
		const count = Number(dashboard?.grades?.[grade] ?? 0);
		return `${Math.max(gradeTotal > 0 ? (count / gradeTotal) * 100 : 0, count > 0 ? 8 : 0)}%`;
	}

	function closeEntryFromBackdrop(event: MouseEvent) {
		if (event.target === event.currentTarget) {
			selectedEntry = null;
		}
	}
</script>

<Section
	heading="Lead Scoring"
	description="Review scored leads across every configured form, then open a form setup when scoring needs tuning."
>
	{#snippet actions()}
		<div class="sf:flex sf:min-w-max sf:flex-wrap sf:items-center sf:gap-2">
			<Button variant="secondary" onclick={() => navigateToAppPath('/actions')}>All forms</Button>
			<Button variant="secondary" onclick={loadDashboard} disabled={loading}>Refresh</Button>
		</div>
	{/snippet}

	{#if error}
		<div
			class="sf:mb-4 sf:rounded sf:border sf:border-danger-200 sf:bg-danger-50 sf:p-3 sf:text-sm sf:text-danger-800"
		>
			{error}
		</div>
	{/if}

	{#if loading}
		<div class="sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] sf:gap-4">
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-32 sf:animate-pulse sf:bg-slate-50"></Card>
		</div>
	{:else}
		<div
			class="sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-sm sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]"
			data-testid="lead-scoring-aggregate-summary"
		>
			<div class="sf:border-l-4 sf:border-l-primary-500 sf:border-r sf:border-slate-100 sf:p-5">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Scored leads</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.scored_leads ?? gradeTotal}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Across all forms</p>
			</div>
			<div class="sf:border-r sf:border-slate-100 sf:p-5">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Priority leads</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.priority_leads ?? dashboard?.grades?.A ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">A grade or high priority</p>
			</div>
			<div class="sf:border-r sf:border-slate-100 sf:p-5">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Follow-up drafts</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.reply_drafts ?? dashboard?.suggested_replies ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Replies and next steps</p>
			</div>
			<div class="sf:p-5">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Rejected leads</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.rejected_leads ?? dashboard?.grades?.Reject ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Rejected lead submissions</p>
			</div>
		</div>

		<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
			<div class="sf:space-y-4">
				<Card class="sf:space-y-4">
					<div class="sf:flex sf:flex-col sf:gap-3">
						<div>
							<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Scored entries</h2>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
								Justifications are clipped in the table so a long model response never stretches a
								row.
							</p>
						</div>
						<div
							class="sf:grid sf:gap-2 sf:sm:grid-cols-[minmax(0,1fr)_auto] sf:sm:items-end sf:lg:max-w-xl"
						>
							<div class="sf:min-w-0">
								<InputField
									id="aggregate-lead-scoring-search"
									label="Search entries"
									placeholder="Entry, form, grade, field, justification"
									bind:value={query}
								/>
							</div>
							<Button variant="secondary" onclick={searchEntries}>Search</Button>
						</div>
					</div>
					<div class="sf:hidden sf:overflow-x-auto sf:2xl:block">
						<table
							class="sf:min-w-full sf:table-fixed sf:border-separate sf:border-spacing-0 sf:text-sm"
						>
							<thead>
								<tr class="sf:text-left sf:text-xs sf:uppercase sf:text-slate-500">
									<th class="sf:w-40 sf:border-b sf:border-slate-200 sf:py-2 sf:pr-3">Form</th>
									<th class="sf:w-24 sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Entry</th>
									<th class="sf:w-24 sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Grade</th>
									<th class="sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Justification</th>
									<th class="sf:w-40 sf:border-b sf:border-slate-200 sf:py-2 sf:pl-3">Actions</th>
								</tr>
							</thead>
							<tbody>
								{#each dashboard?.entries ?? [] as entry (`${entry.form_source}-${entry.form_id}-${entry.entry_id}`)}
									<tr class="sf:align-top">
										<td class="sf:border-b sf:border-slate-100 sf:py-3 sf:pr-3">
											<p class="sf:font-semibold sf:text-slate-900">
												{entry.form_title || `Form ${entry.form_id}`}
											</p>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												{providerLabel(entry.form_source, entry.provider_label)}
											</p>
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:px-3 sf:py-3">
											<p class="sf:font-semibold sf:text-slate-900">#{entry.entry_id}</p>
											<p
												class="sf:mt-1 sf:text-xs sf:text-slate-500"
												title={absoluteDateTime(entryDateValue(entry))}
											>
												{relativeDateTime(entryDateValue(entry))}
											</p>
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:px-3 sf:py-3">
											<Badge
												variant={entry.grade === 'Reject'
													? 'danger'
													: entry.grade === 'A'
														? 'success'
														: 'neutral'}
											>
												{entry.grade || 'Ungraded'}
											</Badge>
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:px-3 sf:py-3">
											<p
												class="sf:line-clamp-3 sf:max-h-[4.5rem] sf:overflow-hidden sf:text-slate-700"
											>
												{entry.justification || entry.fit_summary || 'No justification stored yet.'}
											</p>
											{#if entry.next_best_action}
												<p class="sf:mt-2 sf:text-xs sf:font-medium sf:text-slate-500">
													Next step: {entry.next_best_action}
												</p>
											{/if}
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:py-3 sf:pl-3">
											<div class="sf:flex sf:flex-col sf:items-start sf:gap-2">
												<Button
													size="sm"
													variant="secondary"
													onclick={() => (selectedEntry = entry)}
												>
													Open detail
												</Button>
												<Button
													size="sm"
													variant="ghost"
													class="sf:px-0"
													onclick={() => navigateToAppPath(setupJumpPath(entry))}
												>
													Setup
												</Button>
											</div>
										</td>
									</tr>
								{:else}
									<tr>
										<td colspan="5" class="sf:py-8 sf:text-center sf:text-sm sf:text-slate-500">
											No scored entries yet. Configure Lead Scoring on a form or run a historical
											score to populate this dashboard.
										</td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
					<div class="sf:space-y-3 sf:2xl:hidden">
						{#each dashboard?.entries ?? [] as entry (`compact-${entry.form_source}-${entry.form_id}-${entry.entry_id}`)}
							<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<div
									class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
								>
									<div class="sf:min-w-0">
										<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
											{entry.form_title || `Form ${entry.form_id}`} · Entry #{entry.entry_id}
										</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{providerLabel(entry.form_source, entry.provider_label)} ·
											<span title={absoluteDateTime(entryDateValue(entry))}>
												{relativeDateTime(entryDateValue(entry))}
											</span>
										</p>
									</div>
									<Badge
										variant={entry.grade === 'Reject'
											? 'danger'
											: entry.grade === 'A'
												? 'success'
												: 'neutral'}
									>
										{entry.grade || 'Ungraded'}
									</Badge>
								</div>
								<p
									class="sf:mt-3 sf:line-clamp-3 sf:max-h-[4.5rem] sf:overflow-hidden sf:text-sm sf:text-slate-700"
								>
									{entry.justification || entry.fit_summary || 'No justification stored yet.'}
								</p>
								{#if entry.next_best_action}
									<p class="sf:mt-2 sf:text-xs sf:font-medium sf:text-slate-500">
										Next step: {entry.next_best_action}
									</p>
								{/if}
								<div class="sf:mt-3 sf:flex sf:flex-wrap sf:gap-2">
									<Button size="sm" variant="secondary" onclick={() => (selectedEntry = entry)}>
										Open detail
									</Button>
									<Button
										size="sm"
										variant="ghost"
										onclick={() => navigateToAppPath(setupJumpPath(entry))}
									>
										Setup
									</Button>
								</div>
							</div>
						{:else}
							<p
								class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:text-center sf:text-sm sf:text-slate-500"
							>
								No scored entries yet. Configure Lead Scoring on a form or run a historical score to
								populate this dashboard.
							</p>
						{/each}
					</div>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-3">
						<p class="sf:text-xs sf:text-slate-500">
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
				</Card>
			</div>

			<div class="sf:space-y-4">
				{#if (dashboard?.unconfigured_forms?.length ?? 0) > 0}
					<Card>
						<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Quick jump</h2>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
							Forms that do not have Lead Scoring setup yet.
						</p>
						<div class="sf:mt-4 sf:space-y-3">
							{#each dashboard?.unconfigured_forms ?? [] as form (`unconfigured-${form.form_source}-${form.form_id}`)}
								<div class="sf:flex sf:items-center sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:p-3">
									<div class="sf:min-w-0">
										<p class="sf:truncate sf:text-sm sf:font-semibold sf:text-slate-900">
											{form.form_title || `Form ${form.form_id}`}
										</p>
										<p class="sf:text-xs sf:text-slate-500">
											{providerLabel(form.form_source, form.provider_label)}
										</p>
									</div>
									<Button
										size="sm"
										variant="secondary"
										onclick={() => navigateToAppPath(setupJumpPath(form))}
									>
										Set Up
									</Button>
								</div>
							{/each}
						</div>
					</Card>
				{/if}

				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Configured forms</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each dashboard?.forms ?? [] as form (`${form.form_source}-${form.form_id}`)}
							<div class="sf:rounded sf:border sf:border-slate-200 sf:p-3">
								<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
											{form.form_title || `Form ${form.form_id}`}
										</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{providerLabel(form.form_source, form.provider_label)}
										</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{form.scored_leads} scored · {form.priority_leads} priority · {form.reply_drafts}
											drafts
										</p>
										{#if form.profile_id}
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												Setup v{form.profile_version ?? 1} · {form.setup_status || 'draft'}
											</p>
										{/if}
									</div>
									<Button
										size="sm"
										variant="secondary"
										onclick={() => navigateToAppPath(setupJumpPath(form))}
									>
										Setup
									</Button>
								</div>
							</div>
						{:else}
							<p class="sf:text-sm sf:text-slate-500">
								No forms have stored lead scoring results yet.
							</p>
						{/each}
					</div>
				</Card>

				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Grade distribution</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each gradeOrder as grade}
							<div>
								<div class="sf:flex sf:items-center sf:justify-between sf:text-sm">
									<span class="sf:font-medium sf:text-slate-700">{grade}</span>
									<span class="sf:text-slate-500">{dashboard?.grades?.[grade] ?? 0}</span>
								</div>
								<div class="sf:mt-1 sf:h-3 sf:overflow-hidden sf:rounded sf:bg-slate-100">
									<div
										class={`sf:h-full ${gradeTone(grade)}`}
										style={`width: ${gradeWidth(grade)}`}
									></div>
								</div>
							</div>
						{/each}
					</div>
				</Card>
			</div>
		</div>
	{/if}

	{#if selectedEntry}
		<div
			class="sf:fixed sf:inset-0 sf:z-[1300] sf:flex sf:justify-end sf:bg-slate-950/35"
			role="presentation"
			onclick={closeEntryFromBackdrop}
		>
			<div
				class="sf:h-full sf:w-full sf:max-w-2xl sf:overflow-y-auto sf:bg-white sf:p-5 sf:shadow-2xl sf:sm:p-6"
				role="dialog"
				aria-modal="true"
				aria-labelledby="aggregate-lead-scoring-entry-detail-title"
			>
				<div class="sf:flex sf:items-start sf:justify-between sf:gap-4">
					<div>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">
							Lead Scoring detail
						</p>
						<h2
							id="aggregate-lead-scoring-entry-detail-title"
							class="sf:mt-1 sf:text-xl sf:font-semibold sf:text-slate-900"
						>
							{selectedEntry.form_title || `Form ${selectedEntry.form_id}`} · Entry #{selectedEntry.entry_id}
						</h2>
					</div>
					<Button variant="secondary" size="sm" onclick={() => (selectedEntry = null)}>Close</Button
					>
				</div>

				<div class="sf:mt-5 sf:grid sf:gap-3 sf:sm:grid-cols-3">
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Grade</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							{selectedEntry.grade || 'Ungraded'}
						</p>
					</Card>
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Priority</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							{selectedEntry.priority || 'normal'}
						</p>
					</Card>
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Setup</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							v{selectedEntry.profile_version ?? '-'}
						</p>
					</Card>
				</div>

				<div class="sf:mt-6 sf:space-y-5">
					<section>
						<h3 class="sf:text-sm sf:font-semibold sf:text-slate-900">Justification</h3>
						<p
							class="sf:mt-2 sf:whitespace-pre-wrap sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:text-sm sf:text-slate-700"
						>
							{selectedEntry.justification ||
								selectedEntry.fit_summary ||
								'No justification stored.'}
						</p>
					</section>

					<section>
						<h3 class="sf:text-sm sf:font-semibold sf:text-slate-900">
							Suggested reply and next best action
						</h3>
						<div class="sf:mt-2 sf:space-y-3 sf:rounded sf:border sf:border-slate-200 sf:p-3">
							<p class="sf:text-sm sf:text-slate-700">
								<strong>Next best action:</strong>
								{selectedEntry.next_best_action || 'No recommendation stored.'}
							</p>
							<p class="sf:whitespace-pre-wrap sf:text-sm sf:text-slate-700">
								{selectedEntry.suggested_reply_draft || 'No suggested reply draft stored.'}
							</p>
							{#if selectedEntry.reply_rationale}
								<p class="sf:text-xs sf:text-slate-500">{selectedEntry.reply_rationale}</p>
							{/if}
						</div>
					</section>

					<section>
						<h3 class="sf:text-sm sf:font-semibold sf:text-slate-900">Entry preview</h3>
						<div class="sf:mt-2 sf:space-y-2">
							{#each selectedEntry.entry_snapshot?.field_summary ?? [] as field (`${field.field_id}-${field.label}`)}
								<div class="sf:rounded sf:border sf:border-slate-100 sf:bg-white sf:p-2">
									<p class="sf:text-xs sf:font-medium sf:text-slate-500">{field.label}</p>
									<p class="sf:text-sm sf:text-slate-800">{field.value}</p>
								</div>
							{:else}
								<p class="sf:text-sm sf:text-slate-500">No entry preview fields stored.</p>
							{/each}
						</div>
					</section>
				</div>
			</div>
		</div>
	{/if}
</Section>
