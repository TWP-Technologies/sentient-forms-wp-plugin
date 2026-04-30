<script lang="ts">
	import FormField from './form-field.svelte';
	import Button from './button.svelte';

	interface MergeTagOption {
		token: string;
		label: string;
		group?: string;
		description?: string;
	}

	interface Props {
		id: string;
		label: string;
		value?: string;
		rows?: number;
		placeholder?: string;
		description?: string | null;
		tokens?: MergeTagOption[];
	}

	let {
		id,
		label,
		value = $bindable(),
		rows = 4,
		placeholder = '',
		description = null,
		tokens = []
	}: Props = $props();

	let textarea: HTMLTextAreaElement | null = $state(null);
	let tokenSearch = $state('');

	const groupedTokens = $derived.by(() => {
		const search = tokenSearch.trim().toLowerCase();
		const groups = new Map<string, MergeTagOption[]>();
		for (const token of tokens) {
			const haystack = `${token.token} ${token.label} ${token.description ?? ''}`.toLowerCase();
			if (search && !haystack.includes(search)) continue;
			const group = token.group ?? 'Common';
			groups.set(group, [...(groups.get(group) ?? []), token]);
		}

		return [...groups.entries()];
	});

	const usedTokens = $derived.by(() => {
		const matches = [...String(value ?? '').matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)];
		return [...new Set(matches.map((match) => match[1]?.trim()).filter(Boolean))];
	});

	function mergeTag(token: string): string {
		const trimmed = token.trim();
		return trimmed.startsWith('{{') ? trimmed : `{{${trimmed}}}`;
	}

	function insertToken(token: string) {
		const tag = mergeTag(token);
		const target = textarea;
		if (!target) {
			value = `${value ?? ''}${tag}`;
			return;
		}

		const current = target.value;
		const start = target.selectionStart ?? current.length;
		const end = target.selectionEnd ?? start;
		value = `${current.slice(0, start)}${tag}${current.slice(end)}`;

		queueMicrotask(() => {
			target.focus();
			const cursor = start + tag.length;
			target.setSelectionRange(cursor, cursor);
		});
	}
</script>

<div class="sf:space-y-2" data-testid={`merge-tag-field-${id}`}>
	<FormField {id} {label} {description}>
		<textarea
			bind:this={textarea}
			{id}
			bind:value
			{rows}
			{placeholder}
			class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:leading-6 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
		></textarea>
	</FormField>

	{#if usedTokens.length > 0}
		<div class="sf:flex sf:flex-wrap sf:gap-1" aria-label="Merge tags used">
			{#each usedTokens as token}
				<span
					class="sf:inline-flex sf:items-center sf:rounded-full sf:border sf:border-primary-200 sf:bg-primary-50 sf:px-2 sf:py-0.5 sf:font-mono sf:text-[11px] sf:font-semibold sf:text-primary-800"
				>
					{mergeTag(token)}
				</span>
			{/each}
		</div>
	{/if}

	<details class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3">
		<summary class="sf:cursor-pointer sf:text-xs sf:font-semibold sf:text-slate-700">
			Insert merge tag
		</summary>
		<div class="sf:mt-3 sf:space-y-3">
			<input
				class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				placeholder="Search tags..."
				bind:value={tokenSearch}
			/>
			{#each groupedTokens as [group, groupTokens]}
				<div>
					<p class="sf:mb-1 sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
						{group}
					</p>
					<div class="sf:flex sf:flex-wrap sf:gap-1.5">
						{#each groupTokens as token}
							<Button
								type="button"
								size="sm"
								variant="secondary"
								title={token.description ?? mergeTag(token.token)}
								onclick={() => insertToken(token.token)}
							>
								{token.label}
							</Button>
						{/each}
					</div>
				</div>
			{/each}
			{#if groupedTokens.length === 0}
				<p class="sf:text-xs sf:text-slate-500">No merge tags match this search.</p>
			{/if}
		</div>
	</details>
</div>
