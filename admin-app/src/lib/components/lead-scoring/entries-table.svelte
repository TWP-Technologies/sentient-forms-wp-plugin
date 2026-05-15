<script lang="ts">
	import { appHref } from '$lib/navigation';
	import { Badge, ButtonLink } from '$lib/components/ui';
	import RelativeTime from '$lib/components/lead-scoring/relative-time.svelte';
	import type { LeadScoringEntry } from '$lib/api/types';
	import {
		detailPath,
		entryDateValue,
		gradeBadgeVariant,
		providerLabel,
		setupJumpPath
	} from '$lib/components/lead-scoring/utils';

	type Props = {
		entries: LeadScoringEntry[];
		basePath: string;
		showForm?: boolean;
		onOpenDetail: (entry: LeadScoringEntry, event?: MouseEvent) => void;
		emptyMessage?: string;
	};

	let {
		entries,
		basePath,
		showForm = true,
		onOpenDetail,
		emptyMessage = 'No scored entries yet.'
	}: Props = $props();

	function detailHref(entry: LeadScoringEntry): string {
		return appHref(detailPath(basePath, entry));
	}

	function setupHref(entry: LeadScoringEntry): string {
		return appHref(setupJumpPath(entry));
	}

	function openDetail(entry: LeadScoringEntry, event: MouseEvent) {
		if (
			event.defaultPrevented ||
			event.button > 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		event.preventDefault();
		onOpenDetail(entry, event);
	}
</script>

<div class="sf:hidden sf:overflow-x-auto sf:xl:block">
	<table
		class="sf:min-w-full sf:table-fixed sf:border-separate sf:border-spacing-0 sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:text-sm sf:shadow-sm"
	>
		<thead>
			<tr
				class="sf:bg-slate-50 sf:text-left sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.08em] sf:text-slate-500"
			>
				{#if showForm}
					<th class="sf:w-56 sf:border-b sf:border-slate-200 sf:px-8 sf:py-4">Form</th>
				{/if}
				<th class="sf:w-48 sf:border-b sf:border-slate-200 sf:px-8 sf:py-4">Entry</th>
				<th class="sf:w-32 sf:border-b sf:border-slate-200 sf:px-8 sf:py-4">Grade</th>
				<th class="sf:border-b sf:border-slate-200 sf:px-8 sf:py-4">Justification</th>
				<th class="sf:w-44 sf:border-b sf:border-slate-200 sf:px-8 sf:py-4 sf:text-right">
					Actions
				</th>
			</tr>
		</thead>
		<tbody>
			{#each entries as entry (`${entry.form_source}-${entry.form_id}-${entry.entry_id}`)}
				<tr class="sf:align-top sf:transition-colors sf:hover:bg-slate-50/70">
					{#if showForm}
						<td class="sf:border-b sf:border-slate-100 sf:px-8 sf:py-6">
							<p class="sf:line-clamp-2 sf:text-base sf:font-semibold sf:leading-snug sf:text-slate-950">
								{entry.form_title || `Form ${entry.form_id}`}
							</p>
							<p class="sf:mt-2 sf:text-base sf:text-slate-500">
								{providerLabel(entry.form_source, entry.provider_label)}
							</p>
						</td>
					{/if}
					<td class="sf:border-b sf:border-slate-100 sf:px-8 sf:py-6">
						<p class="sf:text-base sf:font-semibold sf:text-slate-950">#{entry.entry_id}</p>
						<RelativeTime value={entryDateValue(entry)} class="sf:mt-2 sf:text-sm sf:text-slate-500" />
					</td>
					<td class="sf:border-b sf:border-slate-100 sf:px-8 sf:py-6">
						<Badge variant={gradeBadgeVariant(entry.grade)}>{entry.grade || 'Ungraded'}</Badge>
						{#if typeof entry.confidence === 'number'}
							<p class="sf:mt-2 sf:text-xs sf:text-slate-500">
								{Math.round(entry.confidence * 100)}% confidence
							</p>
						{/if}
					</td>
					<td class="sf:border-b sf:border-slate-100 sf:px-8 sf:py-6">
						<p
							class="sf:line-clamp-4 sf:max-h-[7rem] sf:overflow-hidden sf:text-base sf:leading-relaxed sf:text-slate-800"
						>
							{entry.justification || entry.fit_summary || 'No justification stored yet.'}
						</p>
						{#if entry.next_best_action}
							<p class="sf:mt-4 sf:text-sm sf:font-medium sf:leading-relaxed sf:text-slate-500">
								Next step: {entry.next_best_action}
							</p>
						{/if}
					</td>
					<td class="sf:border-b sf:border-slate-100 sf:px-8 sf:py-6">
						<div class="sf:flex sf:flex-col sf:items-stretch sf:gap-3">
							<ButtonLink
								size="sm"
								variant="secondary"
								href={detailHref(entry)}
								onClick={(event) => openDetail(entry, event)}
							>
								Open detail
							</ButtonLink>
							<ButtonLink
								size="sm"
								variant="secondary"
								href={setupHref(entry)}
							>
								Setup
							</ButtonLink>
						</div>
					</td>
				</tr>
			{:else}
				<tr>
					<td colspan={showForm ? 5 : 4} class="sf:py-10 sf:text-center sf:text-sm sf:text-slate-500">
						{emptyMessage}
					</td>
				</tr>
			{/each}
		</tbody>
	</table>
</div>

<div class="sf:space-y-3 sf:xl:hidden">
	{#each entries as entry (`compact-${entry.form_source}-${entry.form_id}-${entry.entry_id}`)}
		<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:shadow-sm">
			<div
				class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
			>
				<div class="sf:min-w-0">
					<p class="sf:line-clamp-2 sf:text-sm sf:font-semibold sf:text-slate-950">
						{#if showForm}
							{entry.form_title || `Form ${entry.form_id}`} · Entry #{entry.entry_id}
						{:else}
							Entry #{entry.entry_id}
						{/if}
					</p>
					<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
						{#if showForm}
							{providerLabel(entry.form_source, entry.provider_label)} ·
						{/if}
						<RelativeTime value={entryDateValue(entry)} />
					</p>
				</div>
				<Badge variant={gradeBadgeVariant(entry.grade)}>{entry.grade || 'Ungraded'}</Badge>
			</div>
			<p
				class="sf:mt-3 sf:line-clamp-3 sf:max-h-[4.8rem] sf:overflow-hidden sf:text-sm sf:leading-relaxed sf:text-slate-700"
			>
				{entry.justification || entry.fit_summary || 'No justification stored yet.'}
			</p>
			{#if entry.next_best_action}
				<p class="sf:mt-2 sf:text-xs sf:font-medium sf:text-slate-500">
					Next step: {entry.next_best_action}
				</p>
			{/if}
			<div class="sf:mt-4 sf:flex sf:flex-wrap sf:gap-2">
				<ButtonLink
					size="sm"
					variant="secondary"
					href={detailHref(entry)}
					onClick={(event) => openDetail(entry, event)}
				>
					Open detail
				</ButtonLink>
				<ButtonLink
					size="sm"
					variant="secondary"
					href={setupHref(entry)}
				>
					Setup
				</ButtonLink>
			</div>
		</div>
	{:else}
		<p
			class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:text-center sf:text-sm sf:text-slate-500"
		>
			{emptyMessage}
		</p>
	{/each}
</div>
