<script lang="ts">
	import { onMount } from 'svelte';
	import { Badge, Button, ButtonLink, Card, Section, StateTemplate } from '$lib/components/ui';
	import { createClientFromConfig } from '$lib/api/client';
	import type {
		SubmissionLedgerRecord,
		SubmissionLedgerSettingsResponse
	} from '$lib/api/types';
	import { appHref } from '$lib/navigation';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		formatSubmissionLedgerFieldPreview,
		safeSubmissionNativeEntryUrl
	} from '$lib/utils/submission-ledger';

	type Props = { data: { formSourceSlug: string; formId: string } };

	let { data }: Props = $props();

	const client = createClientFromConfig();
	let loading = $state(true);
	let error = $state<string | null>(null);
	let settings = $state<SubmissionLedgerSettingsResponse | null>(null);
	let records = $state<SubmissionLedgerRecord[]>([]);
	let total = $state(0);

	const routeFormSourceSlug = $derived(encodeURIComponent(data.formSourceSlug));
	const routeFormId = $derived(encodeURIComponent(data.formId));
	const formDetailHref = $derived(appHref(`/actions/${routeFormSourceSlug}/${routeFormId}`));
	const formLabel = $derived(`${data.formSourceSlug.replaceAll('_', ' ')} #${data.formId}`);

	async function loadLedgerSubmissions() {
		loading = true;
		error = null;

		try {
			const [nextSettings, nextRecords] = await Promise.all([
				client.getSubmissionLedgerSettings(data.formSourceSlug, data.formId, {
					showNotifications: false
				}),
				client.getSubmissionLedgerRecords(data.formSourceSlug, data.formId, {
					perPage: 50,
					offset: 0,
					showNotifications: false
				})
			]);

			settings = nextSettings;
			records = Array.isArray(nextRecords.records) ? nextRecords.records : [];
			total = Number.isFinite(nextRecords.total) ? nextRecords.total : records.length;
		} catch (caught) {
			error = caught instanceof Error ? caught.message : 'Unable to load submission ledger.';
			records = [];
			total = 0;
		} finally {
			loading = false;
		}
	}

	onMount(() => {
		void loadLedgerSubmissions();
	});
</script>

{#snippet actions()}
	<ButtonLink variant="secondary" href={formDetailHref}>Back to form actions</ButtonLink>
	<Button variant="secondary" onclick={loadLedgerSubmissions}>Refresh</Button>
{/snippet}

<Section
	heading="Submission Ledger"
	description={`Stored Sentient Forms submission snapshots for ${formLabel}.`}
	{actions}
>
	<Card data-testid="submission-ledger-status-card">
		<div
			class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
		>
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-800">Ledger storage</p>
				<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
					{settings?.enabled
						? 'Logical field snapshots are enabled for this form.'
						: 'Logical field snapshots are not stored while this is off.'}
				</p>
			</div>
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<Badge variant={settings?.enabled ? 'success' : 'neutral'}>
					{settings?.enabled ? 'On' : 'Off'}
				</Badge>
				<Badge variant="neutral">{total.toLocaleString()} records</Badge>
			</div>
		</div>
	</Card>

	<Card data-testid="submission-ledger-records-card">
		{#if loading}
			<StateTemplate
				variant="loading"
				title="Loading submissions"
				message="Fetching stored submission snapshots."
				inline
				testId="submission-ledger-loading"
			/>
		{:else if error}
			<StateTemplate
				variant="error"
				title="Unable to load submissions"
				message={error}
				actionLabel="Retry"
				onAction={loadLedgerSubmissions}
				inline
				testId="submission-ledger-error"
			/>
		{:else if records.length === 0}
			<StateTemplate
				variant="empty"
				title="No stored submissions"
				message="New submitted forms will appear here after ledger storage is enabled and a form is submitted."
				inline
				testId="submission-ledger-empty"
			/>
		{:else}
			<div class="sf:overflow-x-auto" data-testid="submission-ledger-table-scroll">
				<table class="sf:min-w-full sf:divide-y sf:divide-slate-200" data-testid="submission-ledger-table">
					<thead class="sf:bg-slate-50">
						<tr
							class="sf:text-left sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600"
						>
							<th class="sf:px-4 sf:py-3">Submission</th>
							<th class="sf:px-4 sf:py-3">Captured</th>
							<th class="sf:px-4 sf:py-3">Fields</th>
							<th class="sf:px-4 sf:py-3 sf:text-right">Native entry</th>
						</tr>
					</thead>
					<tbody class="sf:divide-y sf:divide-slate-200">
						{#each records as record (record.submission_uuid)}
							{@const nativeEntryUrl = safeSubmissionNativeEntryUrl(record.native_entry_url)}
							<tr class="sf:text-sm sf:text-slate-700" data-testid="submission-ledger-row">
								<td class="sf:px-4 sf:py-3">
									<p class="sf:font-medium sf:text-slate-900">{record.submission_uuid}</p>
									{#if record.native_entry_id}
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Native entry {record.native_entry_id}
										</p>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3">
									{formatTimestamp(record.captured_at)}
									{#if record.expires_at}
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Expires {formatTimestamp(record.expires_at)}
										</p>
									{/if}
								</td>
								<td class="sf:max-w-md sf:px-4 sf:py-3">
									<p class="sf:line-clamp-2 sf:text-slate-700">
										{formatSubmissionLedgerFieldPreview(record)}
									</p>
								</td>
								<td class="sf:px-4 sf:py-3 sf:text-right">
									{#if nativeEntryUrl}
										<a
											href={nativeEntryUrl}
											class="sf:text-sm sf:font-medium sf:text-primary-700 hover:sf:text-primary-800"
											data-sveltekit-reload
											rel="external"
										>
											Open
										</a>
									{:else}
										<span class="sf:text-xs sf:text-slate-500">Unavailable</span>
									{/if}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</Card>
</Section>
