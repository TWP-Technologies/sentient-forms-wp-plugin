<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Alert,
		Badge,
		Button,
		Card,
		InputField,
		Section,
		SelectField,
		TextareaField
	} from '$lib/components/ui';
	import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { navigateToAppPath } from '$lib/navigation';
	import {
		parseDelimitedList,
		validateEmailTags,
		validateWebhookTags
	} from '$lib/schemas/lead-scoring';
	import { notifications } from '$lib/stores/notifications';
	import type {
		LeadGrade,
		LeadValueEntrySearchEntry,
		LeadProfileReadinessRequirement,
		LeadProfileResponse,
		LeadValueDashboard,
		LeadValueHistoricalRun,
		LeadScoringEntry,
		FormActionConfig,
		SpamGuidanceExample
	} from '$lib/api/types';

	type Props = { data: { formSourceSlug: string; formId: number } };
	type ViewKey = 'dashboard' | 'setup' | 'historical';
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
		{ key: 'dashboard', label: 'Dashboard' },
		{ key: 'setup', label: 'Setup' },
		{ key: 'historical', label: 'Historical' }
	];

	let view = $state<ViewKey>('dashboard');
	let loading = $state(true);
	let saving = $state(false);
	let generating = $state(false);
	let runningId = $state<number | null>(null);
	let error = $state<string | null>(null);
	let profileResponse = $state<LeadProfileResponse | null>(null);
	let dashboard = $state<LeadValueDashboard | null>(null);
	let historicalRuns = $state<LeadValueHistoricalRun[]>([]);
	let globalSpamConfig = $state<FormActionConfig>({});
	let formSpamConfig = $state<FormActionConfig>({});

	let consent = $state(false);
	let goodCriteria = $state('');
	let badCriteria = $state('');
	let emailRecipients = $state<string[]>([]);
	let emailDraft = $state('');
	let emailErrors = $state<string[]>([]);
	let webhookUrls = $state<string[]>([]);
	let webhookDraft = $state('');
	let webhookErrors = $state<string[]>([]);
	let writeLeadGradeNote = $state(true);
	let writeSuggestedReplyNote = $state(true);
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
	let historicalBulkMode = $state(false);
	let historicalSelectedEntries = $state<LeadValueEntrySearchEntry[]>([]);
	let dashboardQuery = $state('');
	let dashboardPage = $state(1);
	let selectedEntryDetail = $state<LeadScoringEntry | null>(null);
	let importProfileId = $state('');
	let importExamples = $state(false);
	let globalSpamPositive = $state<SpamGuidanceExample[]>([]);
	let globalSpamNegative = $state<SpamGuidanceExample[]>([]);
	let formSpamPositive = $state<SpamGuidanceExample[]>([]);
	let formSpamNegative = $state<SpamGuidanceExample[]>([]);
	let savingSpamScope = $state<'global' | 'form' | null>(null);

	const profile = $derived(profileResponse?.profile ?? null);
	const readiness = $derived(profileResponse?.readiness ?? null);
	const blockers = $derived(readiness?.blockers ?? []);
	const ready = $derived(Boolean(readiness?.ready));
	const generationStatus = $derived(resolveGenerationStatus(profile?.generation_metadata));
	const generationPending = $derived(isGenerationPending(generationStatus));
	const gradeTotal = $derived(
		Object.values(dashboard?.grades ?? {}).reduce((total, count) => total + Number(count ?? 0), 0)
	);
	let generationPollToken = 0;

	onMount(() => {
		void loadLeadValue();
	});

	async function loadLeadValue() {
		loading = true;
		error = null;
		try {
			const [profileData, dashboardData, runData] = await Promise.all([
				client.getLeadProfile(data.formSourceSlug, data.formId),
				client.getLeadValueDashboard(data.formSourceSlug, data.formId, {
					page: dashboardPage,
					per_page: 10,
					q: dashboardQuery.trim()
				}),
				client.listLeadValueHistoricalRuns(data.formSourceSlug, data.formId)
			]);
			profileResponse = profileData;
			dashboard = dashboardData;
			historicalRuns = runData.runs;
			applyDrafts(profileData);
			const profileId = profileData.profile?.id;
			if (
				profileId &&
				isGenerationPending(resolveGenerationStatus(profileData.profile.generation_metadata))
			) {
				void pollProfileGeneration(profileId);
			}
			void loadSpamGuidance();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead scoring workspace failed to load.';
		} finally {
			loading = false;
		}
	}

	async function loadSpamGuidance() {
		try {
			const [globalConfig, formConfig] = await Promise.all([
				client.getActionDefaults('spam_detection_v1', { showNotifications: false }),
				client.getFormActionConfig(data.formSourceSlug, data.formId, 'spam_detection_v1', {
					showNotifications: false
				})
			]);
			globalSpamConfig = globalConfig;
			formSpamConfig = formConfig;
			globalSpamPositive = globalConfig.spam_positive_examples ?? [];
			globalSpamNegative = globalConfig.spam_negative_examples ?? [];
			formSpamPositive = formConfig.spam_positive_examples ?? [];
			formSpamNegative = formConfig.spam_negative_examples ?? [];
		} catch (e) {
			console.error('Lead scoring Spam Guidance failed to load', e);
		}
	}

	function applyDrafts(response: LeadProfileResponse) {
		const current = response.profile;
		consent = Boolean(current?.consented_at);
		goodCriteria = current?.good_lead_criteria?.summary_text ?? '';
		badCriteria = current?.bad_lead_criteria?.summary_text ?? '';
		emailRecipients = current?.handoff_rules?.email_recipients ?? [];
		webhookUrls =
			current?.handoff_rules?.webhooks?.map((webhook) => webhook.url).filter(Boolean) ?? [];
		writeLeadGradeNote = current?.handoff_rules?.entry_notes?.lead_grade ?? true;
		writeSuggestedReplyNote = current?.handoff_rules?.entry_notes?.suggested_reply ?? true;
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
			const emailValidation = validateEmailTags([
				...emailRecipients,
				...parseDelimitedList(emailDraft)
			]);
			const webhookValidation = validateWebhookTags([
				...webhookUrls,
				...parseDelimitedList(webhookDraft)
			]);
			emailRecipients = emailValidation.values;
			webhookUrls = webhookValidation.values;
			emailErrors = emailValidation.errors;
			webhookErrors = webhookValidation.errors;
			emailDraft = '';
			webhookDraft = '';
			if (emailValidation.errors.length > 0 || webhookValidation.errors.length > 0) {
				throw new Error('Fix invalid handoff emails or webhooks before saving.');
			}

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
					email_recipients: emailRecipients,
					webhooks: webhookUrls.map((url) => ({ url, method: 'POST' })),
					grades: selectedGradeList(),
					entry_notes: {
						lead_grade: writeLeadGradeNote,
						suggested_reply: writeSuggestedReplyNote
					}
				}
			});
			profileResponse = response;
			if (showNotification) notifications.success('Lead scoring setup saved');
			return response;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead scoring setup could not be saved.';
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
			if (!profileId) throw new Error('Save the lead scoring setup before generation.');
			const response = await client.generateLeadProfile(profileId, {
				lead_profile_consent: consent,
				async: true
			});
			profileResponse = response;
			dashboard = response.dashboard ?? dashboard;
			if (isGenerationPending(resolveGenerationStatus(response.profile?.generation_metadata))) {
				notifications.success('Lead scoring setup generation queued');
				await pollProfileGeneration(profileId);
			} else {
				notifications.success('Lead scoring setup generated');
			}
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead scoring setup could not be generated.';
		} finally {
			generating = false;
		}
	}

	async function pollProfileGeneration(profileId: number) {
		const token = ++generationPollToken;
		generating = true;
		try {
			for (let attempt = 0; attempt < 36; attempt += 1) {
				await delay(attempt === 0 ? 2500 : 5000);
				if (token !== generationPollToken) return;

				const response = await client.getLeadProfile(data.formSourceSlug, data.formId, {
					showNotifications: false
				});
				profileResponse = response;
				const status = resolveGenerationStatus(response.profile?.generation_metadata);
				if (!isGenerationPending(status)) {
					if (status === 'succeeded') {
						notifications.success('Lead scoring setup generated');
					} else if (status === 'failed') {
						error = 'Managed setup generation failed; the saved local fallback remains available.';
					}
					return;
				}
			}

			error = `Lead scoring setup generation is still running for setup #${profileId}. Refresh this page to check the latest status.`;
		} finally {
			if (token === generationPollToken) generating = false;
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
				entry_ids: historicalBulkMode
					? splitList(historicalEntryIds)
					: historicalSelectedEntries.map((entry) => entry.id),
				dry_run: true
			});
			historicalRuns = [
				response.run,
				...historicalRuns.filter((run) => run.id !== response.run.id)
			];
			dashboard = await client.getLeadValueDashboard(data.formSourceSlug, data.formId, {
				page: dashboardPage,
				per_page: 10,
				q: dashboardQuery.trim()
			});
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
				const created = await client.createLeadValueHistoricalRun(
					data.formSourceSlug,
					data.formId,
					{
						action_code: run.action_code,
						lead_profile_id: run.lead_profile_id ?? profile?.id ?? null,
						entry_ids: run.selected_entry_ids,
						filters: run.filters,
						dry_run: false
					}
				);
				executableRun = created.run;
				historicalRuns = [
					executableRun,
					...historicalRuns.filter((item) => item.id !== run.id && item.id !== executableRun.id)
				];
			}
			const response = await client.startLeadValueHistoricalRun(executableRun.id, {
				confirm_costs: true
			});
			historicalRuns = historicalRuns.map((item) => (item.id === run.id ? response.run : item));
			historicalRuns = historicalRuns.map((item) =>
				item.id === executableRun.id ? response.run : item
			);
			dashboard = await client.getLeadValueDashboard(data.formSourceSlug, data.formId, {
				page: dashboardPage,
				per_page: 10,
				q: dashboardQuery.trim()
			});
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

	async function refreshDashboard() {
		dashboard = await client.getLeadValueDashboard(data.formSourceSlug, data.formId, {
			page: dashboardPage,
			per_page: 10,
			q: dashboardQuery.trim()
		});
	}

	function commitEmailDraft() {
		const parsed = parseDelimitedList(emailDraft);
		if (parsed.length === 0) return;
		const validation = validateEmailTags([...emailRecipients, ...parsed]);
		emailRecipients = validation.values;
		emailErrors = validation.errors;
		emailDraft = '';
	}

	function removeEmailTag(value: string) {
		emailRecipients = emailRecipients.filter((item) => item !== value);
	}

	function commitWebhookDraft() {
		const parsed = parseDelimitedList(webhookDraft);
		if (parsed.length === 0) return;
		const validation = validateWebhookTags([...webhookUrls, ...parsed]);
		webhookUrls = validation.values;
		webhookErrors = validation.errors;
		webhookDraft = '';
	}

	function removeWebhookTag(value: string) {
		webhookUrls = webhookUrls.filter((item) => item !== value);
	}

	function addHistoricalEntry(entry: LeadValueEntrySearchEntry) {
		if (historicalSelectedEntries.some((selected) => selected.id === entry.id)) return;
		historicalSelectedEntries = [...historicalSelectedEntries, entry];
	}

	function removeHistoricalEntry(entryId: string) {
		historicalSelectedEntries = historicalSelectedEntries.filter((entry) => entry.id !== entryId);
	}

	async function searchDashboardEntries() {
		dashboardPage = 1;
		await refreshDashboard();
	}

	async function setDashboardPage(page: number) {
		dashboardPage = Math.max(1, page);
		await refreshDashboard();
	}

	async function importLeadProfile() {
		const sourceProfileId = Number.parseInt(importProfileId, 10);
		if (!Number.isFinite(sourceProfileId) || sourceProfileId <= 0) {
			notifications.warning('Enter a source setup ID to import.');
			return;
		}

		saving = true;
		try {
			const response = await client.importLeadProfile(data.formSourceSlug, data.formId, {
				source_profile_id: sourceProfileId,
				include_examples: importExamples
			});
			profileResponse = response;
			applyDrafts(response);
			notifications.success('Lead scoring setup imported');
		} catch (e) {
			error = e instanceof Error ? e.message : 'Lead scoring setup could not be imported.';
		} finally {
			saving = false;
		}
	}

	async function saveSpamGuidance(scope: 'global' | 'form') {
		savingSpamScope = scope;
		error = null;
		try {
			if (scope === 'global') {
				const saved = await client.updateActionDefaults('spam_detection_v1', {
					...globalSpamConfig,
					spam_positive_examples: globalSpamPositive,
					spam_negative_examples: globalSpamNegative
				});
				globalSpamConfig = saved;
				globalSpamPositive = saved.spam_positive_examples ?? [];
				globalSpamNegative = saved.spam_negative_examples ?? [];
				notifications.success('Global Spam Guidance saved');
			} else {
				const saved = await client.updateFormActionConfig(
					data.formSourceSlug,
					data.formId,
					'spam_detection_v1',
					{
						...formSpamConfig,
						spam_positive_examples: formSpamPositive,
						spam_negative_examples: formSpamNegative
					}
				);
				formSpamConfig = saved;
				formSpamPositive = saved.spam_positive_examples ?? [];
				formSpamNegative = saved.spam_negative_examples ?? [];
				notifications.success('Form Spam Guidance saved');
			}
			profileResponse = await client.getLeadProfile(data.formSourceSlug, data.formId, {
				showNotifications: false
			});
		} catch (e) {
			error = e instanceof Error ? e.message : 'Spam Guidance could not be saved.';
		} finally {
			savingSpamScope = null;
		}
	}

	function selectedGradeList(): LeadGrade[] {
		return (Object.entries(selectedGrades) as Array<[LeadGrade, boolean]>)
			.filter(([, enabled]) => enabled)
			.map(([grade]) => grade);
	}

	function delay(ms: number): Promise<void> {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	function resolveGenerationStatus(metadata: Record<string, unknown> | null | undefined): string {
		const augmentation =
			metadata && typeof metadata === 'object' && 'llm_augmentation' in metadata
				? metadata.llm_augmentation
				: null;
		if (augmentation && typeof augmentation === 'object' && 'status' in augmentation) {
			const value = augmentation.status;
			if (typeof value === 'string' && value.trim()) return value.trim();
		}
		return '';
	}

	function isGenerationPending(status: string): boolean {
		return status === 'queued' || status === 'running';
	}

	function setSelectedGrade(grade: LeadGrade, enabled: boolean) {
		selectedGrades = {
			...selectedGrades,
			[grade]: enabled
		};
	}

	function normalizeExampleEntries(
		rawExamples: Array<Record<string, unknown>>
	): LeadExampleDraft[] {
		return rawExamples
			.map((example) => {
				const entryId =
					typeof example.entry_id === 'string' ? example.entry_id : String(example.entry_id ?? '');
				const grade = normalizeGrade(typeof example.grade === 'string' ? example.grade : '');
				if (!entryId || !grade) return null;
				const snapshot =
					typeof example.snapshot === 'object' &&
					example.snapshot !== null &&
					!Array.isArray(example.snapshot)
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
			'field_summary' in example ? example.field_summary : (example.snapshot.field_summary ?? []);
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

	function actionLabel(actionCode: string) {
		return actionCode === 'suggested_reply_v1' ? 'Suggested replies' : 'Lead scoring';
	}

	function closeEntryDetailFromBackdrop(event: MouseEvent) {
		if (event.target === event.currentTarget) {
			selectedEntryDetail = null;
		}
	}

	const gradeChoices: LeadGrade[] = ['A', 'B', 'C', 'Reject'];
</script>

<Section
	heading="Lead Scoring"
	description="Review scored form entries, tune the setup, run historical scoring, and hand off qualified leads from this form."
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
		<div class="sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] sf:gap-4">
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
			<Card class="sf:h-36 sf:animate-pulse sf:bg-slate-50"></Card>
		</div>
	{:else}
		<div
			class="sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] sf:gap-3"
			data-testid="lead-scoring-summary"
		>
			<Card class="sf:border-l-4 sf:border-l-primary-500">
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Scored leads</p>
				<div class="sf:mt-2 sf:flex sf:items-center sf:justify-between sf:gap-3">
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900">
						{dashboard?.metrics?.scored_leads ?? gradeTotal}
					</p>
					<Badge variant={ready ? 'success' : 'warning'}>{ready ? 'Ready' : 'Blocked'}</Badge>
				</div>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Setup v{profile?.profile_version ?? 0}</p>
				{#if generationStatus}
					<p class="sf:mt-1 sf:text-xs sf:text-slate-500">Generation: {generationStatus}</p>
				{/if}
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Priority leads</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.priority_leads ?? dashboard?.grades?.A ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">A grade or high priority</p>
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Follow-up drafts</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.reply_drafts ?? dashboard?.suggested_replies ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Suggested replies or next steps</p>
			</Card>
			<Card>
				<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Rejected or low-fit</p>
				<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
					{dashboard?.metrics?.rejected_leads ?? dashboard?.grades?.Reject ?? 0}
				</p>
				<p class="sf:mt-2 sf:text-xs sf:text-slate-500">Reject grade entries</p>
			</Card>
		</div>

		<div
			class="sf:mt-5 sf:flex sf:flex-wrap sf:gap-1 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-1"
			aria-label="Lead scoring views"
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

		{#if view === 'setup'}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<div class="sf:space-y-4">
					<Card>
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-4">
							<div>
								<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Setup checklist</h2>
								<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
									{blockers.length} blocker{blockers.length === 1 ? '' : 's'} remaining
								</p>
							</div>
							<Badge variant={ready ? 'success' : 'warning'}
								>{ready ? 'Ready' : 'Needs input'}</Badge
							>
						</div>
						<div class="sf:mt-4 sf:grid sf:gap-2 sf:lg:grid-cols-2">
							{#each readiness?.requirements ?? [] as requirement (requirement.key)}
								<div
									class="sf:flex sf:items-start sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:p-3"
								>
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
						<div
							class="sf:flex sf:flex-col sf:gap-3 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between"
						>
							<div>
								<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Spam Guidance</h2>
								<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
									Lead Scoring uses the same legitimate and spam examples as the spam action. Global
									defaults count unless this form has its own override.
								</p>
							</div>
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								<Badge
									variant={(readiness?.spam_guidance?.positive_count ?? 0) >= 3
										? 'success'
										: 'warning'}
								>
									{readiness?.spam_guidance?.positive_count ?? 0} legitimate
								</Badge>
								<Badge
									variant={(readiness?.spam_guidance?.negative_count ?? 0) >= 3
										? 'success'
										: 'warning'}
								>
									{readiness?.spam_guidance?.negative_count ?? 0} spam
								</Badge>
							</div>
						</div>
						<div class="sf:grid sf:gap-4 sf:xl:grid-cols-2">
							<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Global defaults</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Used by every form that has no local Spam Guidance override.
										</p>
									</div>
									<Button
										size="sm"
										variant="secondary"
										onclick={() => saveSpamGuidance('global')}
										disabled={savingSpamScope !== null}
									>
										{savingSpamScope === 'global' ? 'Saving...' : 'Save global'}
									</Button>
								</div>
								<SpamCriteriaEditor
									positiveExamples={globalSpamPositive}
									negativeExamples={globalSpamNegative}
									initiallyExpanded={false}
									onchange={(details) => {
										globalSpamPositive = details.positive;
										globalSpamNegative = details.negative;
									}}
								/>
							</div>
							<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-900">This form</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Override only when this form attracts different lead or spam patterns.
										</p>
									</div>
									<Button
										size="sm"
										variant="secondary"
										onclick={() => saveSpamGuidance('form')}
										disabled={savingSpamScope !== null}
									>
										{savingSpamScope === 'form' ? 'Saving...' : 'Save form'}
									</Button>
								</div>
								<SpamCriteriaEditor
									positiveExamples={formSpamPositive}
									negativeExamples={formSpamNegative}
									inheritedPositive={globalSpamPositive}
									inheritedNegative={globalSpamNegative}
									inheritanceSource="action"
									initiallyExpanded={false}
									onchange={(details) => {
										formSpamPositive = details.positive;
										formSpamNegative = details.negative;
									}}
								/>
							</div>
						</div>
					</Card>

					<Card class="sf:space-y-4">
						<label
							class="sf:flex sf:items-start sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3"
						>
							<input
								type="checkbox"
								class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
								bind:checked={consent}
							/>
							<span>
								<span class="sf:block sf:text-sm sf:font-medium sf:text-slate-900">
									Use saved context and examples for this form's lead scoring setup
								</span>
								<span class="sf:mt-1 sf:block sf:text-xs sf:text-slate-500">
									Consent is stored with the local setup and required before generation.
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
								{exampleEntries.length} selected for setup calibration
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
									<div
										class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3"
									>
										<div class="sf:min-w-0 sf:flex-1">
											<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
												<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
													Entry #{entry.id}
												</p>
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
											<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
												Entry #{example.entry_id}
											</p>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												{summarizeExample(example)}
											</p>
										</div>
										<Button
											size="sm"
											variant="ghost"
											onclick={() => removeExampleEntry(example.entry_id)}
										>
											Remove
										</Button>
									</div>
									<div class="sf:mt-3 sf:grid sf:gap-3 sf:md:grid-cols-[10rem_minmax(0,1fr)]">
										<SelectField
											id={`lead-example-grade-${example.entry_id}`}
											label="Grade"
											value={example.grade}
											options={gradeChoices.map((grade) => ({ value: grade, label: grade }))}
											onchange={(event) =>
												updateExampleGrade(example.entry_id, event.currentTarget.value)}
										/>
										<InputField
											id={`lead-example-rationale-${example.entry_id}`}
											label="Why this grade?"
											value={example.rationale}
											placeholder="Short calibration note"
											oninput={(event) =>
												updateExampleRationale(example.entry_id, event.currentTarget.value)}
										/>
									</div>
								</div>
							{:else}
								<p
									class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:p-3 sf:text-sm sf:text-slate-500"
								>
									No selected examples yet.
								</p>
							{/each}
						</div>
					</Card>

					<Card class="sf:space-y-4">
						<div>
							<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Import setup</h2>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
								Copy criteria and handoff settings from another form, then tune them for this form
								before regenerating.
							</p>
						</div>
						<div class="sf:grid sf:gap-3 sf:md:grid-cols-[minmax(0,1fr)_auto] sf:md:items-end">
							<InputField
								id="lead-profile-import-source"
								label="Source setup ID"
								placeholder="Setup ID from another Gravity Form"
								bind:value={importProfileId}
							/>
							<Button variant="secondary" onclick={importLeadProfile} disabled={saving}>
								Import setup
							</Button>
						</div>
						<label class="sf:flex sf:items-start sf:gap-2 sf:text-sm sf:text-slate-700">
							<input
								type="checkbox"
								class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
								bind:checked={importExamples}
							/>
							<span>Also copy calibration examples from the source form</span>
						</label>
					</Card>

					<Card class="sf:space-y-4">
						<div class="sf:grid sf:gap-4 sf:lg:grid-cols-2">
							<div>
								<label
									for="lead-handoff-emails"
									class="sf:text-sm sf:font-medium sf:text-slate-800"
								>
									Handoff emails
								</label>
								<div class="sf:mt-1 sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:p-2">
									<div class="sf:flex sf:flex-wrap sf:gap-2">
										{#each emailRecipients as email}
											<span
												class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:bg-primary-50 sf:px-2 sf:py-1 sf:text-xs sf:font-medium sf:text-primary-800"
											>
												{email}
												<Button
													size="sm"
													variant="ghost"
													class="sf:h-auto sf:min-h-0 sf:p-0 sf:px-1 sf:text-primary-700"
													onclick={() => removeEmailTag(email)}
													aria-label={`Remove ${email}`}
												>
													x
												</Button>
											</span>
										{/each}
										<input
											id="lead-handoff-emails"
											class="sf:min-w-48 sf:flex-1 sf:border-0 sf:bg-transparent sf:px-1 sf:py-1 sf:text-sm sf:outline-none"
											placeholder="Paste emails separated by commas, tabs, pipes, or new lines"
											bind:value={emailDraft}
											onkeydown={(event) => {
												if (
													event.key === 'Enter' ||
													event.key === ',' ||
													event.key === ';' ||
													event.key === 'Tab'
												) {
													event.preventDefault();
													commitEmailDraft();
												}
											}}
											onblur={commitEmailDraft}
										/>
									</div>
								</div>
								{#each emailErrors as error}
									<p class="sf:mt-1 sf:text-xs sf:text-danger-600">{error}</p>
								{/each}
							</div>
							<div>
								<label
									for="lead-handoff-webhooks"
									class="sf:text-sm sf:font-medium sf:text-slate-800"
								>
									Handoff webhooks
								</label>
								<div class="sf:mt-1 sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:p-2">
									<div class="sf:flex sf:flex-wrap sf:gap-2">
										{#each webhookUrls as webhook}
											<span
												class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:bg-slate-100 sf:px-2 sf:py-1 sf:text-xs sf:font-medium sf:text-slate-700"
											>
												{webhook}
												<Button
													size="sm"
													variant="ghost"
													class="sf:h-auto sf:min-h-0 sf:p-0 sf:px-1 sf:text-slate-600"
													onclick={() => removeWebhookTag(webhook)}
													aria-label={`Remove ${webhook}`}
												>
													x
												</Button>
											</span>
										{/each}
										<input
											id="lead-handoff-webhooks"
											class="sf:min-w-48 sf:flex-1 sf:border-0 sf:bg-transparent sf:px-1 sf:py-1 sf:text-sm sf:outline-none"
											placeholder="Paste webhook URLs"
											bind:value={webhookDraft}
											onkeydown={(event) => {
												if (
													event.key === 'Enter' ||
													event.key === ',' ||
													event.key === ';' ||
													event.key === 'Tab'
												) {
													event.preventDefault();
													commitWebhookDraft();
												}
											}}
											onblur={commitWebhookDraft}
										/>
									</div>
								</div>
								{#each webhookErrors as error}
									<p class="sf:mt-1 sf:text-xs sf:text-danger-600">{error}</p>
								{/each}
							</div>
						</div>
						<div>
							<p class="sf:text-sm sf:font-medium sf:text-slate-800">Handoff grades</p>
							<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
								{#each gradeChoices as grade}
									<label
										class="sf:inline-flex sf:items-center sf:gap-2 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:px-3 sf:py-2 sf:text-sm"
									>
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
						<div
							class="sf:grid sf:gap-2 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:sm:grid-cols-2"
						>
							<label class="sf:flex sf:items-start sf:gap-2 sf:text-sm sf:text-slate-700">
								<input
									type="checkbox"
									class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
									bind:checked={writeLeadGradeNote}
								/>
								<span>Record lead grade and justification as a Gravity Forms entry note</span>
							</label>
							<label class="sf:flex sf:items-start sf:gap-2 sf:text-sm sf:text-slate-700">
								<input
									type="checkbox"
									class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
									bind:checked={writeSuggestedReplyNote}
								/>
								<span
									>Record suggested reply and next best action as a Gravity Forms entry note</span
								>
							</label>
						</div>
						<div class="sf:flex sf:flex-wrap sf:gap-2 sf:pt-2">
							<Button
								onclick={() => saveProfile()}
								disabled={saving || generating || generationPending}
							>
								{saving ? 'Saving...' : 'Save setup'}
							</Button>
							<Button
								variant="secondary"
								onclick={generateProfile}
								disabled={saving || generating || generationPending}
							>
								{generating || generationPending ? 'Generating...' : 'Generate scoring setup'}
							</Button>
							<Button
								variant="secondary"
								onclick={refreshAssistant}
								disabled={!profile?.id || saving}
							>
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
								Questions appear after the setup is saved or generated.
							</p>
						{/if}
					</div>
				</Card>
			</div>
		{:else if view === 'dashboard'}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<Card class="sf:space-y-4">
					<div class="sf:flex sf:flex-col sf:gap-3">
						<div>
							<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Scored form entries</h2>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
								Review grades, justification, follow-up drafts, and next best actions without
								stretching table rows.
							</p>
						</div>
						<div
							class="sf:grid sf:gap-2 sf:sm:grid-cols-[minmax(0,1fr)_auto] sf:sm:items-end sf:lg:max-w-xl"
						>
							<div class="sf:min-w-0">
								<InputField
									id="lead-scoring-dashboard-search"
									label="Search entries"
									placeholder="Entry ID, grade, company, justification"
									bind:value={dashboardQuery}
								/>
							</div>
							<Button variant="secondary" onclick={searchDashboardEntries}>Search</Button>
						</div>
					</div>
					<div class="sf:hidden sf:overflow-x-auto sf:2xl:block">
						<table
							class="sf:min-w-full sf:table-fixed sf:border-separate sf:border-spacing-0 sf:text-sm"
						>
							<thead>
								<tr class="sf:text-left sf:text-xs sf:uppercase sf:text-slate-500">
									<th class="sf:w-28 sf:border-b sf:border-slate-200 sf:py-2 sf:pr-3">Entry</th>
									<th class="sf:w-24 sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Grade</th>
									<th class="sf:w-32 sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Priority</th>
									<th class="sf:border-b sf:border-slate-200 sf:px-3 sf:py-2">Justification</th>
									<th class="sf:w-40 sf:border-b sf:border-slate-200 sf:py-2 sf:pl-3">Follow-up</th>
								</tr>
							</thead>
							<tbody>
								{#each dashboard?.entries ?? [] as entry (`${entry.form_source}-${entry.form_id}-${entry.entry_id}`)}
									<tr class="sf:align-top">
										<td class="sf:border-b sf:border-slate-100 sf:py-3 sf:pr-3">
											<p class="sf:font-semibold sf:text-slate-900">#{entry.entry_id}</p>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												{entry.entry_snapshot?.date_created ?? entry.updated_at ?? ''}
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
											{#if typeof entry.confidence === 'number'}
												<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
													{Math.round(entry.confidence * 100)}% confidence
												</p>
											{/if}
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:px-3 sf:py-3 sf:text-slate-700">
											{entry.priority || 'normal'}
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:px-3 sf:py-3">
											<p
												class="sf:line-clamp-3 sf:max-h-[4.5rem] sf:overflow-hidden sf:text-slate-700"
											>
												{entry.justification || entry.fit_summary || 'No justification stored yet.'}
											</p>
											<Button
												size="sm"
												variant="ghost"
												class="sf:mt-2 sf:px-0"
												onclick={() => (selectedEntryDetail = entry)}
											>
												View full detail
											</Button>
										</td>
										<td class="sf:border-b sf:border-slate-100 sf:py-3 sf:pl-3">
											{#if entry.suggested_reply_draft || entry.next_best_action}
												<Badge variant={entry.do_not_send ? 'warning' : 'info'}>
													{entry.do_not_send ? 'Review only' : 'Draft ready'}
												</Badge>
											{:else}
												<span class="sf:text-xs sf:text-slate-500">No draft</span>
											{/if}
										</td>
									</tr>
								{:else}
									<tr>
										<td colspan="5" class="sf:py-8 sf:text-center sf:text-sm sf:text-slate-500">
											No scored entries yet. Run lead scoring on new or historical entries to
											populate this table.
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
											Entry #{entry.entry_id}
										</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{entry.entry_snapshot?.date_created ?? entry.updated_at ?? ''}
										</p>
									</div>
									<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
										<Badge
											variant={entry.grade === 'Reject'
												? 'danger'
												: entry.grade === 'A'
													? 'success'
													: 'neutral'}
										>
											{entry.grade || 'Ungraded'}
										</Badge>
										{#if entry.suggested_reply_draft || entry.next_best_action}
											<Badge variant={entry.do_not_send ? 'warning' : 'info'}>
												{entry.do_not_send ? 'Review only' : 'Draft ready'}
											</Badge>
										{/if}
									</div>
								</div>
								<div class="sf:mt-3 sf:grid sf:gap-2 sf:sm:grid-cols-[8rem_minmax(0,1fr)]">
									<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Priority</p>
									<p class="sf:text-sm sf:text-slate-700">{entry.priority || 'normal'}</p>
									<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">
										Justification
									</p>
									<p
										class="sf:line-clamp-3 sf:max-h-[4.5rem] sf:overflow-hidden sf:text-sm sf:text-slate-700"
									>
										{entry.justification || entry.fit_summary || 'No justification stored yet.'}
									</p>
								</div>
								<Button
									size="sm"
									variant="ghost"
									class="sf:mt-3 sf:px-0"
									onclick={() => (selectedEntryDetail = entry)}
								>
									View full detail
								</Button>
							</div>
						{:else}
							<p
								class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:text-center sf:text-sm sf:text-slate-500"
							>
								No scored entries yet. Run lead scoring on new or historical entries to populate
								this table.
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
								onclick={() => setDashboardPage((dashboard?.entry_page ?? 1) - 1)}
							>
								Previous
							</Button>
							<Button
								size="sm"
								variant="secondary"
								disabled={(dashboard?.entry_page ?? 1) >= (dashboard?.entry_pages ?? 1)}
								onclick={() => setDashboardPage((dashboard?.entry_page ?? 1) + 1)}
							>
								Next
							</Button>
						</div>
					</div>
				</Card>
				<div class="sf:space-y-4">
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
										<div
											class={`sf:h-full ${gradeTone(grade)}`}
											style={`width: ${gradeWidth(grade)}`}
										></div>
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
										<p class="sf:text-sm sf:font-medium sf:text-slate-900">
											{actionLabel(run.action_code)}
										</p>
										<Badge variant={run.status === 'completed' ? 'success' : 'neutral'}
											>{run.status}</Badge
										>
									</div>
									<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
										{run.estimated_entry_count} entries · {run.selected_entry_ids
											?.slice(0, 4)
											.map((id) => `#${id}`)
											.join(', ') || 'selection pending'}
									</p>
								</div>
							{:else}
								<p class="sf:text-sm sf:text-slate-500">No historical runs yet.</p>
							{/each}
						</div>
					</Card>
				</div>
			</div>
		{:else}
			<div class="sf:mt-5 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(0,1fr)_24rem]">
				<Card class="sf:space-y-4">
					<div>
						<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Select entries to score</h2>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
							Search actual Gravity Forms entries, preview their submitted fields, and select one or
							more entries for a historical run.
						</p>
					</div>
					<SelectField
						id="historical-action"
						label="Action"
						bind:value={historicalAction}
						options={[
							{ value: 'lead_grading_v1', label: 'Lead scoring' },
							{ value: 'suggested_reply_v1', label: 'Suggested reply' }
						]}
					/>
					<div class="sf:grid sf:gap-3 sf:md:grid-cols-[minmax(0,1fr)_auto] sf:md:items-end">
						<InputField
							id="historical-entry-search"
							label="Search entries"
							placeholder="Name, email, company, message, or entry ID"
							bind:value={entrySearchQuery}
						/>
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
								<div
									class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3"
								>
									<div class="sf:min-w-0 sf:flex-1">
										<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Entry #{entry.id}</p>
										<p class="sf:mt-1 sf:line-clamp-2 sf:text-xs sf:text-slate-500">
											{summarizeExample(entry)}
										</p>
									</div>
									<Button
										size="sm"
										variant="secondary"
										onclick={() => addHistoricalEntry(entry)}
										disabled={historicalSelectedEntries.some(
											(selected) => selected.id === entry.id
										)}
									>
										Select
									</Button>
								</div>
							{/each}
						</div>
					{/if}
					<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3">
						<p class="sf:text-sm sf:font-medium sf:text-slate-800">
							Selected entries ({historicalSelectedEntries.length})
						</p>
						<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
							{#each historicalSelectedEntries as entry (entry.id)}
								<span
									class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:bg-white sf:px-2 sf:py-1 sf:text-xs sf:font-medium sf:text-slate-700 sf:ring-1 sf:ring-slate-200"
								>
									#{entry.id}
									<Button
										size="sm"
										variant="ghost"
										class="sf:h-auto sf:min-h-0 sf:p-0 sf:px-1 sf:text-slate-500"
										onclick={() => removeHistoricalEntry(entry.id)}
										aria-label={`Remove entry ${entry.id}`}
									>
										x
									</Button>
								</span>
							{:else}
								<span class="sf:text-xs sf:text-slate-500">No entries selected.</span>
							{/each}
						</div>
					</div>
					<label class="sf:flex sf:items-start sf:gap-2 sf:text-sm sf:text-slate-700">
						<input
							type="checkbox"
							class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
							bind:checked={historicalBulkMode}
						/>
						<span>Use advanced pasted entry IDs instead</span>
					</label>
					{#if historicalBulkMode}
						<TextareaField
							id="historical-entry-ids"
							label="Entry IDs"
							rows={5}
							bind:value={historicalEntryIds}
							placeholder="1001, 1002, 1003"
						/>
					{/if}
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						<Button
							onclick={createHistoricalPreview}
							disabled={saving || (!historicalBulkMode && historicalSelectedEntries.length === 0)}
						>
							Create dry-run preview
						</Button>
					</div>
				</Card>
				<Card>
					<h2 class="sf:text-base sf:font-semibold sf:text-slate-900">Runs</h2>
					<div class="sf:mt-4 sf:space-y-3">
						{#each historicalRuns as run}
							<div class="sf:rounded sf:border sf:border-slate-200 sf:p-3">
								<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-medium sf:text-slate-900">
											{actionLabel(run.action_code)}
										</p>
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											{run.estimated_entry_count} entries · {run.estimated_managed_credits ?? 0} managed
											credits
										</p>
										{#if run.selected_entry_ids?.length}
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												Entries {run.selected_entry_ids
													.slice(0, 6)
													.map((id) => `#${id}`)
													.join(', ')}
											</p>
										{/if}
										{#if run.progress}
											<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
												{run.progress.processed ?? 0}/{run.progress.total ?? 0} processed
											</p>
										{/if}
									</div>
									<Badge variant={run.status === 'completed' ? 'success' : 'neutral'}
										>{run.status}</Badge
									>
								</div>
								{#if canStartHistoricalRun(run)}
									<div class="sf:mt-3">
										<Button
											size="sm"
											variant="secondary"
											onclick={() => startRun(run)}
											disabled={runningId === run.id}
										>
											{runningId === run.id
												? 'Updating...'
												: run.dry_run
													? 'Run confirmed'
													: 'Start'}
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

	{#if selectedEntryDetail}
		<div
			class="sf:fixed sf:inset-0 sf:z-[1300] sf:flex sf:justify-end sf:bg-slate-950/35"
			role="presentation"
			onclick={closeEntryDetailFromBackdrop}
		>
			<div
				class="sf:h-full sf:w-full sf:max-w-2xl sf:overflow-y-auto sf:bg-white sf:p-5 sf:shadow-2xl sf:sm:p-6"
				role="dialog"
				aria-modal="true"
				aria-labelledby="lead-scoring-entry-detail-title"
			>
				<div class="sf:flex sf:items-start sf:justify-between sf:gap-4">
					<div>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">
							Lead Scoring detail
						</p>
						<h2
							id="lead-scoring-entry-detail-title"
							class="sf:mt-1 sf:text-xl sf:font-semibold sf:text-slate-900"
						>
							Entry #{selectedEntryDetail.entry_id}
						</h2>
					</div>
					<Button variant="secondary" size="sm" onclick={() => (selectedEntryDetail = null)}
						>Close</Button
					>
				</div>

				<div class="sf:mt-5 sf:grid sf:gap-3 sf:sm:grid-cols-3">
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Grade</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							{selectedEntryDetail.grade || 'Ungraded'}
						</p>
					</Card>
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Priority</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							{selectedEntryDetail.priority || 'normal'}
						</p>
					</Card>
					<Card>
						<p class="sf:text-xs sf:font-medium sf:uppercase sf:text-slate-500">Setup</p>
						<p class="sf:mt-2 sf:text-2xl sf:font-semibold sf:text-slate-900">
							v{selectedEntryDetail.profile_version ?? '-'}
						</p>
					</Card>
				</div>

				<div class="sf:mt-6 sf:space-y-5">
					<section>
						<h3 class="sf:text-sm sf:font-semibold sf:text-slate-900">Justification</h3>
						<p
							class="sf:mt-2 sf:whitespace-pre-wrap sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:text-sm sf:text-slate-700"
						>
							{selectedEntryDetail.justification ||
								selectedEntryDetail.fit_summary ||
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
								{selectedEntryDetail.next_best_action || 'No recommendation stored.'}
							</p>
							<p class="sf:whitespace-pre-wrap sf:text-sm sf:text-slate-700">
								{selectedEntryDetail.suggested_reply_draft || 'No suggested reply draft stored.'}
							</p>
							{#if selectedEntryDetail.reply_rationale}
								<p class="sf:text-xs sf:text-slate-500">{selectedEntryDetail.reply_rationale}</p>
							{/if}
						</div>
					</section>

					<section>
						<h3 class="sf:text-sm sf:font-semibold sf:text-slate-900">Entry preview</h3>
						<div class="sf:mt-2 sf:space-y-2">
							{#each selectedEntryDetail.entry_snapshot?.field_summary ?? [] as field (`${field.field_id}-${field.label}`)}
								<div class="sf:rounded sf:border sf:border-slate-100 sf:bg-white sf:p-2">
									<p class="sf:text-xs sf:font-medium sf:text-slate-500">{field.label}</p>
									<p class="sf:text-sm sf:text-slate-800">{field.value}</p>
								</div>
							{:else}
								<p class="sf:text-sm sf:text-slate-500">No entry preview fields stored.</p>
							{/each}
						</div>
					</section>

					<section class="sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3">
						<p class="sf:text-xs sf:text-slate-500">
							Lead execution: {selectedEntryDetail.lead_execution_id || 'not stored'} · Reply execution:
							{selectedEntryDetail.reply_execution_id || 'not stored'} · Historical run:
							{selectedEntryDetail.historical_run_id ?? 'none'}
						</p>
					</section>
				</div>
			</div>
		</div>
	{/if}
</Section>
