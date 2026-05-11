<script lang="ts">
	import { onMount } from 'svelte';
	import { Alert, Badge, Button, Card, InputField, Section, SelectField, TextareaField } from '$lib/components/ui';
	import { createClientFromConfig } from '$lib/api/client';
	import { navigateToAppPath } from '$lib/navigation';
	import { notifications } from '$lib/stores/notifications';
	import type {
		LeadGrade,
		LeadValueEntrySearchEntry,
		LeadProfileReadinessRequirement,
		LeadProfileResponse,
		LeadValueDashboard,
		LeadValueHistoricalRun
	} from '$lib/api/types';

	type Props = { data: { formSourceSlug: string; formId: number } };
	type ViewKey = 'dashboard' | 'profile' | 'historical';
	type LeadExampleDraft = {
		entry_id: string;
		grade: LeadGrade;
		rationale: string;
		snapshot: {
			date_created?: string | null;
			status?: string | null;
			field_summary?: LeadValueEntrySearchEntry['field_summary'];
		};
	};

	let { data }: Props = $props();

	const client = createClientFromConfig();
	const gradeOrder: Array<LeadGrade | 'ungraded'> = ['A', 'B', 'C', 'Reject', 'ungraded'];
	const viewChoices: Array<{ key: ViewKey; label: string }> = [
		{ key: 'profile', label: 'Profile' },
		{ key: 'dashboard', label: 'Dashboard' },
		{ key: 'historical', label: 'Historical' }
	];

	let view = $state<ViewKey>('profile');
	let loading = $state(true);
	let saving = $state(false);
	let generating = $state(false);
	let runningId = $state<number | null>(null);
	let error = $state<string | null>(null);
	let profileResponse = $state<LeadProfileResponse | null>(null);
	let dashboard = $state<LeadValueDashboard | null>(null);
	let historicalRuns = $state<LeadValueHistoricalRun[]>([]);

	let consent = $state(false);
	let goodCriteria = $state('');
	let badCriteria = $state('');
	let emailRecipients = $state('');
	let webhookUrl = $state('');
	let selectedGrades = $state<Record<LeadGrade, boolean>>({
		A: true,
		B: true,
		C: false,
		Reject: false
	});
	let exampleEntries = $state<LeadExampleDraft[]>([]);
	let entrySearchQuery = $state('');
	let entrySearchLoading = $state(false);
	let entrySearchResults = $state<LeadValueEntrySearchEntry[]>([]);
	let historicalAction = $state('lead_grading_v1');
	let historicalEntryIds = $state('');

	const profile = $derived(profileResponse?.profile ?? null);
	const readiness = $derived(profileResponse?.readiness ?? null);
	const blockers = $derived(readiness?.blockers ?? []);
	const ready = $derived(Boolean(readiness?.ready));
	const gradeTotal = $derived(
		Object.values(dashboard?.grades ?? {}).reduce((total, count) => total + Number(count ?? 0), 0)
	);

	onMount(() => {
		void loadLeadValue();
	});

	async function loadLeadValue() {
		loading = true;
		error = null;
		try {
			const [profileData, dashboardData, runData] = await Promise.all([
				client.getLeadProfile(data.formSourceSlug, data.formId),
				client.getLeadValueDashboard(data.formSourceSlug, data.formId),
				client.listLeadValueHistoricalRuns(data.formSourceSlug, data.formId)
			]);
			profileResponse = profileData;
			dashboard = dashboardData;
			historicalRuns = runData.runs;
			applyDrafts(profileData);
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead value workspace failed to load.';
		} finally {
			loading = false;
		}
	}

	function applyDrafts(response: LeadProfileResponse) {
		const current = response.profile;
		consent = Boolean(current?.consented_at);
		goodCriteria = current?.good_lead_criteria?.summary_text ?? '';
		badCriteria = current?.bad_lead_criteria?.summary_text ?? '';
		emailRecipients = current?.handoff_rules?.email_recipients?.join(', ') ?? '';
		webhookUrl = current?.handoff_rules?.webhooks?.[0]?.url ?? '';
		exampleEntries = normalizeExampleEntries(current?.example_entries ?? []);
		const grades = new Set(current?.handoff_rules?.grades ?? ['A', 'B']);
		selectedGrades = {
			A: grades.has('A'),
			B: grades.has('B'),
			C: grades.has('C'),
			Reject: grades.has('Reject')
		};
	}

	async function saveProfile(showNotification = true) {
		saving = true;
		error = null;
		try {
			const response = await client.saveLeadProfile(data.formSourceSlug, data.formId, {
				lead_profile_consent: consent,
				good_lead_criteria: { summary_text: goodCriteria },
				bad_lead_criteria: { summary_text: badCriteria },
				example_entries: exampleEntries.map((example) => ({
					entry_id: example.entry_id,
					grade: example.grade,
					rationale: example.rationale,
					snapshot: example.snapshot
				})),
				handoff_rules: {
					email_recipients: splitList(emailRecipients),
					webhooks: webhookUrl.trim() ? [{ url: webhookUrl.trim(), method: 'POST' }] : [],
					grades: selectedGradeList()
				}
			});
			profileResponse = response;
			if (showNotification) notifications.success('Lead profile saved');
			return response;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead profile could not be saved.';
			return null;
		} finally {
			saving = false;
		}
	}

	async function searchEntries() {
		entrySearchLoading = true;
		error = null;
		try {
			const response = await client.searchLeadValueEntries(data.formSourceSlug, data.formId, {
				q: entrySearchQuery.trim(),
				limit: 8
			});
			entrySearchResults = response.entries;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Entry examples could not be searched.';
		} finally {
			entrySearchLoading = false;
		}
	}

	function addExampleEntry(entry: LeadValueEntrySearchEntry, grade: LeadGrade = 'B') {
		if (exampleEntries.some((example) => example.entry_id === entry.id)) return;
		exampleEntries = [
			...exampleEntries,
			{
				entry_id: entry.id,
				grade,
				rationale: '',
				snapshot: {
					date_created: entry.date_created ?? null,
					status: entry.status ?? null,
					field_summary: entry.field_summary
				}
			}
		];
	}

	function removeExampleEntry(entryId: string) {
		exampleEntries = exampleEntries.filter((example) => example.entry_id !== entryId);
	}

	function updateExampleGrade(entryId: string, grade: string) {
		const normalized = normalizeGrade(grade);
		if (!normalized) return;
		exampleEntries = exampleEntries.map((example) =>
			example.entry_id === entryId ? { ...example, grade: normalized } : example
		);
	}

	function updateExampleRationale(entryId: string, rationale: string) {
		exampleEntries = exampleEntries.map((example) =>
			example.entry_id === entryId ? { ...example, rationale } : example
		);
	}

	async function generateProfile() {
		generating = true;
		error = null;
		try {
			const saved = await saveProfile(false);
			const profileId = saved?.profile?.id;
			if (!profileId) throw new Error('Save the lead profile before generation.');
			const response = await client.generateLeadProfile(profileId, { lead_profile_consent: consent });
			profileResponse = response;
			dashboard = response.dashboard ?? dashboard;
			notifications.success('Lead grading profile generated');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead profile could not be generated.';
		} finally {
			generating = false;
		}
	}

	async function refreshAssistant() {
		const profileId = profile?.id;
		if (!profileId) return;
		saving = true;
		try {
			const response = await client.refreshLeadProfileAssistant(profileId);
			profileResponse = response;
			notifications.success('Setup questions refreshed');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Setup questions could not be refreshed.';
		} finally {
			saving = false;
		}
	}

	async function createHistoricalPreview() {
		saving = true;
		error = null;
		try {
			const response = await client.createLeadValueHistoricalRun(data.formSourceSlug, data.formId, {
				action_code: historicalAction,
				lead_profile_id: profile?.id ?? null,
				entry_ids: splitList(historicalEntryIds),
				dry_run: true
			});
			historicalRuns = [response.run, ...historicalRuns.filter((run) => run.id !== response.run.id)];
			dashboard = await client.getLeadValueDashboard(data.formSourceSlug, data.formId);
			notifications.success('Historical preview created');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Historical preview could not be created.';
		} finally {
			saving = false;
		}
	}

	async function startRun(run: LeadValueHistoricalRun) {
		runningId = run.id;
		error = null;
		try {
			let executableRun = run;
			if (run.dry_run) {
				const created = await client.createLeadValueHistoricalRun(data.formSourceSlug, data.formId, {
					action_code: run.action_code,
					lead_profile_id: run.lead_profile_id ?? profile?.id ?? null,
					entry_ids: run.selected_entry_ids,
					filters: run.filters,
					dry_run: false
				});
				executableRun = created.run;
				historicalRuns = [
					executableRun,
					...historicalRuns.filter((item) => item.id !== run.id && item.id !== executableRun.id)
				];
			}
			const response = await client.startLeadValueHistoricalRun(executableRun.id, { confirm_costs: true });
			historicalRuns = historicalRuns.map((item) => (item.id === run.id ? response.run : item));
			historicalRuns = historicalRuns.map((item) => (item.id === executableRun.id ? response.run : item));
			dashboard = await client.getLeadValueDashboard(data.formSourceSlug, data.formId);
			notifications.success('Historical run updated');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Historical run could not be started.';
		} finally {
			runningId = null;
		}
	}

	function splitList(value: string): string[] {
		return value
			.split(/[\n,]/)
			.map((item) => item.trim())
			.filter(Boolean);
	}

	function selectedGradeList(): LeadGrade[] {
		return (Object.entries(selectedGrades) as Array<[LeadGrade, boolean]>)
			.filter(([, enabled]) => enabled)
			.map(([grade]) => grade);
	}

	function setSelectedGrade(grade: LeadGrade, enabled: boolean) {
		selectedGrades = {
			...selectedGrades,
			[grade]: enabled
		};
	}

	function normalizeExampleEntries(rawExamples: Array<Record<string, unknown>>): LeadExampleDraft[] {
		return rawExamples
			.map((example) => {
				const entryId = typeof example.entry_id === 'string' ? example.entry_id : String(example.entry_id ?? '');
				const grade = normalizeGrade(typeof example.grade === 'string' ? example.grade : '');
				if (!entryId || !grade) return null;
				const snapshot =
					typeof example.snapshot === 'object' && example.snapshot !== null && !Array.isArray(example.snapshot)
						? (example.snapshot as LeadExampleDraft['snapshot'])
						: {};
				return {
					entry_id: entryId,
					grade,
					rationale: typeof example.rationale === 'string' ? example.rationale : '',
					snapshot
				};
			})
			.filter((example): example is LeadExampleDraft => Boolean(example));
	}

	function normalizeGrade(value: string): LeadGrade | null {
		const grade = value.trim().toUpperCase();
		if (grade === 'A' || grade === 'B' || grade === 'C') return grade;
		if (grade === 'F' || grade === 'REJECT' || grade === 'REJECTED') return 'Reject';
		return null;
	}

	function summarizeExample(example: LeadExampleDraft | LeadValueEntrySearchEntry) {
		const fields =
			'field_summary' in example
				? example.field_summary
				: (example.snapshot.field_summary ?? []);
		return fields
			.slice(0, 3)
			.map((field) => `${field.label}: ${field.value}`)
			.join(' · ');
	}

	function requirementVariant(requirement: LeadProfileReadinessRequirement) {
		if (requirement.met) return 'success';
		return requirement.severity === 'recommendation' ? 'warning' : 'danger';
	}

	function gradeWidth(grade: LeadGrade | 'ungraded') {
		const count = Number(dashboard?.grades?.[grade] ?? 0);
		return `${Math.max(gradeTotal > 0 ? (count / gradeTotal) * 100 : 0, count > 0 ? 8 : 0)}%`;
	}

	function gradeTone(grade: LeadGrade | 'ungraded') {
		if (grade === 'A') return 'sf:bg-emerald-500';
		if (grade === 'B') return 'sf:bg-sky-500';
		if (grade === 'C') return 'sf:bg-amber-500';
		if (grade === 'Reject') return 'sf:bg-rose-500';
		return 'sf:bg-slate-300';
	}

	function canStartHistoricalRun(run: LeadValueHistoricalRun) {
		return run.dry_run || ['queued', 'pending', 'preview_ready', 'failed'].includes(run.status);
	}

	const gradeChoices: LeadGrade[] = ['A', 'B', 'C', 'Reject'];
</script>

<Section
	heading="Lead Value"
	description="Configure profile-backed grading, handoff rules, historical scoring, and proof-of-value reporting for this form."
>
	{#snippet actions()}
		<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
			<Button
				variant="secondary"
				onclick={() => navigateToAppPath(`/actions/${data.formSourceSlug}/${data.formId}`)}
			>
				Back to actions
			</Button>
			<Button variant="secondary" onclick={loadLeadValue} disabled={loading}>Refresh</Button>
		</div>
	{/snippet}

	{#if error}
		<Alert variant="danger" class="sf:mb-4">{error}</Alert>
	{/if}

	{#if loading}
		<div class="sf:grid sf:gap-4 sf:lg:grid-cols-3">
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
		</div>
	{:else}
		<div class="sf:grid sf:gap-3 sf:lg:grid-cols-4" data-testid="lead-value-summary">
			<Card class="sf:border-l-4 sf:border-l-primary-500">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Profile</p>
				<div class="sf:mt-2 sf:flex sf:items-center sf:justify-between sf:gap-3">
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900">
						{profile?.status ?? 'draft'}
					</p>
					<Badge variant={ready ? 'success' : 'warning'}>{ready ? 'Ready' : 'Blocked'}</Badge>
				</div>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">v{profile?.profile_version ?? 0}</p>
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Site Context</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{readiness?.site_context.word_count ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">words available</p>
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Spam Guidance</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{readiness?.spam_guidance.positive_count ?? 0}/{readiness?.spam_guidance.negative_count ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">legitimate/spam examples</p>
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Proof Events</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.event_count ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">
					{dashboard?.suggested_replies ?? 0} reply drafts
				</p>
			</Card>
		</div>

		<div
			class="sf:mt-5 sf:flex sf:flex-wrap sf:gap-1 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-1"
			aria-label="Lead value views"
		>
			{#each viewChoices as item}
				<Button
					variant={view === item.key ? 'secondary' : 'ghost'}
					size="sm"
					class="sf:min-h-9 sf:border-transparent sf:px-3"
					onclick={() => (view = item.key)}
				>
					{item.label}
				</Button>
			{/each}
		</div>

		{#if view === 'profile'}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<div class="sf:space-y-4">
					<Card>
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-4">
							<div>
								<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Setup Gate</h2>
								<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
									{blockers.length} blocker{blockers.length === 1 ? '' : 's'} remaining
								</p>
							</div>
							<Badge variant={ready ? 'success' : 'warning'}>{ready ? 'Ready' : 'Needs input'}</Badge>
						</div>
						<div class="sf:mt-4 sf:grid sf:gap-2">
							{#each readiness?.requirements ?? [] as requirement (requirement.key)}
								<div class="sf:flex sf:items-start sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:p-3">
									<div>
										<p class="sf:text-sm sf:font-medium sf:text-slate-800">{requirement.label}</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">{requirement.detail}</p>
									</div>
									<Badge variant={requirementVariant(requirement)}>
										{requirement.met ? 'Met' : requirement.severity}
									</Badge>
								</div>
							{/each}
						</div>
					</Card>

					<Card class="sf:space-y-4">
						<label class="sf:flex sf:items-start sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3">
							<input
								type="checkbox"
								class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
								bind:checked={consent}
							/>
							<span>
								<span class="sf:block sf:text-sm sf:font-medium sf:text-slate-900">
									Use saved context and examples for this form's lead profile
								</span>
								<span class="sf:mt-1 sf:block sf:text-xs sf:text-slate-500">
									Consent is stored with the local profile and required before generation.
								</span>
							</span>
						</label>
						<TextareaField
							id="good-lead-criteria"
							label="Good lead criteria"
							rows={6}
							bind:value={goodCriteria}
							placeholder="Describe fit, intent, urgency, service-area, budget, use case, and handoff signals."
						/>
						<TextareaField
							id="bad-lead-criteria"
							label="Bad lead criteria"
							rows={6}
							bind:value={badCriteria}
							placeholder="Describe disqualifiers, irrelevant requests, risky patterns, poor-fit inquiries, and spam-adjacent cases."
						/>
					</Card>

					<Card class="sf:space-y-4">
						<div>
							<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Entry Examples</h2>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
								{exampleEntries.length} selected for profile calibration
							</p>
						</div>
						<div class="sf:grid sf:gap-3 sf:md:grid-cols-[minmax(0,1fr)_auto] sf:md:items-end">
							<div>
								<InputField
									id="lead-entry-search"
									label="Search form entries"
									placeholder="Name, email, company, or entry ID"
									bind:value={entrySearchQuery}
								/>
							</div>
							<Button
								variant="secondary"
								class="sf:w-full sf:md:w-auto"
								onclick={searchEntries}
								disabled={entrySearchLoading}
							>
								{entrySearchLoading ? 'Searching...' : 'Search entries'}
							</Button>
						</div>
						{#if entrySearchResults.length}
							<div class="sf:grid sf:gap-2">
								{#each entrySearchResults as entry (entry.id)}
									<div class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
										<div class="sf:min-w-0 sf:flex-1">
											<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
												<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Entry #{entry.id}</p>
												{#if entry.status}
													<Badge variant="neutral">{entry.status}</Badge>
												{/if}
											</div>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">{summarizeExample(entry)}</p>
										</div>
										<div class="sf:flex sf:flex-wrap sf:gap-2">
											{#each gradeChoices as grade}
												<Button
													size="sm"
													variant="secondary"
													onclick={() => addExampleEntry(entry, grade)}
													disabled={exampleEntries.some((example) => example.entry_id === entry.id)}
												>
													Mark {grade}
												</Button>
											{/each}
										</div>
									</div>
								{/each}
							</div>
						{/if}
						<div class="sf:grid sf:gap-3">
							{#each exampleEntries as example (example.entry_id)}
								<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3">
									<div class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3">
										<div class="sf:min-w-0 sf:flex-1">
											<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Entry #{example.entry_id}</p>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">{summarizeExample(example)}</p>
										</div>
										<Button size="sm" variant="ghost" onclick={() => removeExampleEntry(example.entry_id)}>
											Remove
										</Button>
									</div>
									<div class="sf:mt-3 sf:grid sf:gap-3 sf:md:grid-cols-[10rem_minmax(0,1fr)]">
										<SelectField
											id={`lead-example-grade-${example.entry_id}`}
											label="Grade"
											value={example.grade}
											options={gradeChoices.map((grade) => ({ value: grade, label: grade }))}
											onchange={(event) => updateExampleGrade(example.entry_id, event.currentTarget.value)}
										/>
										<InputField
											id={`lead-example-rationale-${example.entry_id}`}
											label="Why this grade?"
											value={example.rationale}
											placeholder="Short calibration note"
											oninput={(event) => updateExampleRationale(example.entry_id, event.currentTarget.value)}
										/>
									</div>
								</div>
							{:else}
								<p class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:p-3 sf:text-sm sf:text-slate-500">
									No selected examples yet.
								</p>
							{/each}
						</div>
					</Card>

					<Card class="sf:space-y-4">
						<div class="sf:grid sf:gap-4 sf:lg:grid-cols-2">
							<InputField
								id="lead-handoff-emails"
								label="Handoff emails"
								placeholder="sales@example.com, owner@example.com"
								bind:value={emailRecipients}
							/>
							<InputField
								id="lead-handoff-webhook"
								label="Handoff webhook"
								placeholder="https://example.com/webhook"
								bind:value={webhookUrl}
							/>
						</div>
						<div>
							<p class="sf:text-sm sf:font-medium sf:text-slate-800">Handoff grades</p>
							<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
								{#each gradeChoices as grade}
									<label class="sf:inline-flex sf:items-center sf:gap-2 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:px-3 sf:py-2 sf:text-sm">
										<input
											type="checkbox"
											class="sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
											checked={selectedGrades[grade]}
											onchange={(event) => setSelectedGrade(grade, event.currentTarget.checked)}
										/>
										{grade}
									</label>
								{/each}
							</div>
						</div>
						<div class="sf:flex sf:flex-wrap sf:gap-2">
							<Button onclick={() => saveProfile()} disabled={saving || generating}>
								{saving ? 'Saving...' : 'Save profile'}
							</Button>
							<Button variant="secondary" onclick={generateProfile} disabled={saving || generating}>
								{generating ? 'Generating...' : 'Generate grading profile'}
							</Button>
							<Button variant="secondary" onclick={refreshAssistant} disabled={!profile?.id || saving}>
								Refresh setup questions
							</Button>
						</div>
					</Card>
				</div>

				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Setup Questions</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#if profile?.assistant?.questions?.length}
							{#each profile.assistant.questions as question}
								<div class="sf:rounded sf:border sf:border-slate-200 sf:p-3">
									<p class="sf:text-sm sf:font-medium sf:text-slate-900">{question.question}</p>
									<p class="sf:mt-1 sf:text-xs sf:text-slate-500">{question.why}</p>
								</div>
							{/each}
						{:else}
							<p class="sf:text-sm sf:text-slate-500">
								Questions appear after the profile is saved or generated.
							</p>
						{/if}
					</div>
				</Card>
			</div>
		{:else if view === 'dashboard'}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Grade Distribution</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each gradeOrder as grade}
							<div>
								<div class="sf:flex sf:items-center sf:justify-between sf:text-sm">
									<span class="sf:font-medium sf:text-slate-700">{grade}</span>
									<span class="sf:text-slate-500">{dashboard?.grades?.[grade] ?? 0}</span>
								</div>
								<div class="sf:mt-1 sf:h-3 sf:overflow-hidden sf:rounded sf:bg-slate-100">
									<div class={`sf:h-full ${gradeTone(grade)}`} style={`width: ${gradeWidth(grade)}`}></div>
								</div>
							</div>
						{/each}
					</div>
				</Card>
				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Recent Historical Runs</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each historicalRuns.slice(0, 5) as run}
							<div class="sf:rounded sf:border sf:border-slate-200 sf:p-3">
								<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
									<p class="sf:text-sm sf:font-medium sf:text-slate-900">{run.action_code}</p>
									<Badge variant={run.status === 'completed' ? 'success' : 'neutral'}>{run.status}</Badge>
								</div>
								<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
									{run.estimated_entry_count} entries · {run.estimated_managed_credits ?? 0} managed credits
								</p>
							</div>
						{:else}
							<p class="sf:text-sm sf:text-slate-500">No historical runs yet.</p>
						{/each}
					</div>
				</Card>
			</div>
		{:else}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<Card class="sf:space-y-4">
					<SelectField
						id="historical-action"
						label="Action"
						bind:value={historicalAction}
						options={[
							{ value: 'lead_grading_v1', label: 'Lead grading' },
							{ value: 'suggested_reply_v1', label: 'Suggested reply' }
						]}
					/>
					<TextareaField
						id="historical-entry-ids"
						label="Entry IDs"
						rows={5}
						bind:value={historicalEntryIds}
						placeholder="1001, 1002, 1003"
					/>
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						<Button onclick={createHistoricalPreview} disabled={saving}>Create dry-run preview</Button>
					</div>
				</Card>
				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Runs</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each historicalRuns as run}
							<div class="sf:rounded sf:border sf:border-slate-200 sf:p-3">
								<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-medium sf:text-slate-900">{run.action_code}</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{run.estimated_entry_count} entries · {run.estimated_managed_credits ?? 0} managed credits
										</p>
										{#if run.progress}
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												{run.progress.processed ?? 0}/{run.progress.total ?? 0} processed
											</p>
										{/if}
									</div>
									<Badge variant={run.status === 'completed' ? 'success' : 'neutral'}>{run.status}</Badge>
								</div>
								{#if canStartHistoricalRun(run)}
									<div class="sf:mt-3">
										<Button size="sm" variant="secondary" onclick={() => startRun(run)} disabled={runningId === run.id}>
											{runningId === run.id ? 'Updating...' : run.dry_run ? 'Run confirmed' : 'Start'}
										</Button>
									</div>
								{/if}
							</div>
						{:else}
							<p class="sf:text-sm sf:text-slate-500">No historical runs yet.</p>
						{/each}
					</div>
				</Card>
			</div>
		{/if}
	{/if}
</Section>
