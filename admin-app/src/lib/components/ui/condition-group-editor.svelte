<script lang="ts">
	import type {
		ConditionGroup,
		ConditionNode,
		ConditionOperator,
		ConditionRule,
		FormFieldInfo
	} from '$lib/api/types';
	import ConditionGroupEditor from './condition-group-editor.svelte';
	import {
		CONDITION_OPERATORS,
		MAX_CONDITION_DEPTH,
		createDefaultConditionGroup,
		createDefaultConditionRule
	} from '$lib/utils/conditions';
	import Button from './button.svelte';

	interface Props {
		group: ConditionGroup;
		fields: FormFieldInfo[];
		depth: number;
		disabled?: boolean;
		onchange: (group: ConditionGroup) => void;
		onremove?: (() => void) | undefined;
	}

	let { group, fields, depth, disabled = false, onchange, onremove }: Props = $props();

	const operatorLabels: Record<ConditionOperator, string> = {
		eq: 'Equals',
		neq: 'Does not equal',
		contains: 'Contains',
		not_contains: 'Does not contain',
		starts_with: 'Starts with',
		ends_with: 'Ends with',
		in: 'In list',
		not_in: 'Not in list',
		is_empty: 'Is empty',
		is_not_empty: 'Is not empty',
		gt: 'Greater than',
		gte: 'Greater than or equal',
		lt: 'Less than',
		lte: 'Less than or equal'
	};

	function operatorNeedsValue(operator: ConditionOperator): boolean {
		return operator !== 'is_empty' && operator !== 'is_not_empty';
	}

	function operatorNeedsList(operator: ConditionOperator): boolean {
		return operator === 'in' || operator === 'not_in';
	}

	function operatorNeedsNumeric(operator: ConditionOperator): boolean {
		return operator === 'gt' || operator === 'gte' || operator === 'lt' || operator === 'lte';
	}

	function updateGroup(next: ConditionGroup) {
		onchange(next);
	}

	function updateLogic(logic: ConditionGroup['logic']) {
		updateGroup({ ...group, logic });
	}

	function updateNode(index: number, node: ConditionNode) {
		const rules = [...group.rules];
		rules[index] = node;
		updateGroup({ ...group, rules });
	}

	function removeNode(index: number) {
		const rules = group.rules.filter((_, i) => i !== index);
		updateGroup({ ...group, rules });
	}

	function addRule() {
		const rules = [...group.rules, createDefaultConditionRule(fields[0]?.id ?? '')];
		updateGroup({ ...group, rules });
	}

	function addGroup() {
		const rules = [...group.rules, createDefaultConditionGroup()];
		updateGroup({ ...group, rules });
	}

	function updateRule(index: number, partial: Partial<ConditionRule>) {
		const node = group.rules[index];
		if (!node || node.type !== 'rule') {
			return;
		}

		const nextRule: ConditionRule = { ...node, ...partial };
		updateNode(index, nextRule);
	}

	function updateRuleOperator(index: number, operator: ConditionOperator) {
		const node = group.rules[index];
		if (!node || node.type !== 'rule') {
			return;
		}

		let value: ConditionRule['value'] = node.value;

		if (!operatorNeedsValue(operator)) {
			value = undefined;
		} else if (operatorNeedsList(operator)) {
			value = Array.isArray(node.value)
				? node.value
				: typeof node.value === 'string' && node.value.trim()
					? node.value
							.split(',')
							.map((item) => item.trim())
							.filter(Boolean)
					: [];
		} else if (operatorNeedsNumeric(operator)) {
			const nextNumber = Number(node.value);
			value = Number.isFinite(nextNumber) ? nextNumber : 0;
		} else if (Array.isArray(node.value)) {
			value = '';
		}

		updateRule(index, { operator, value });
	}

	function listText(value: ConditionRule['value']): string {
		if (Array.isArray(value)) {
			return value.map((item) => String(item)).join(', ');
		}

		return typeof value === 'string' ? value : '';
	}

	function setListValue(index: number, raw: string) {
		const value = raw
			.split(',')
			.map((item) => item.trim())
			.filter(Boolean);
		updateRule(index, { value });
	}
</script>

<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:space-y-3">
	<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
		<div class="sf:flex sf:items-center sf:gap-2">
			<span class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
				Logic
			</span>
			<select
				class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				value={group.logic}
				onchange={(event) => updateLogic((event.target as HTMLSelectElement).value as ConditionGroup['logic'])}
				{disabled}
			>
				<option value="all">All rules must match</option>
				<option value="any">Any rule can match</option>
			</select>
		</div>
		{#if onremove}
			<Button
				type="button"
				variant="ghost"
				size="sm"
				class="sf:h-auto sf:border-transparent sf:bg-transparent sf:px-1 sf:py-0 sf:text-xs sf:text-rose-700 sf:underline hover:sf:bg-rose-50 hover:sf:text-rose-800"
				onclick={() => onremove?.()}
				{disabled}
			>
				Remove group
			</Button>
		{/if}
	</div>

	{#if group.rules.length === 0}
		<p class="sf:text-sm sf:text-slate-500">No rules in this group yet.</p>
	{/if}

	{#each group.rules as node, index (index)}
		{#if node.type === 'group'}
			<ConditionGroupEditor
				group={node}
				{fields}
				depth={depth + 1}
				{disabled}
				onchange={(next) => updateNode(index, next)}
				onremove={() => removeNode(index)}
			/>
		{:else}
			<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-2">
				<div class="sf:grid sf:gap-2 sf:md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sf:items-end">
					<label class="sf:flex sf:flex-col sf:gap-1">
						<span class="sf:text-xs sf:text-slate-500">Field</span>
						<select
							class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							value={node.field_id}
							onchange={(event) =>
								updateRule(index, { field_id: (event.target as HTMLSelectElement).value })}
							{disabled}
						>
							<option value="">Select field</option>
							{#each fields as field (field.id)}
								<option value={field.id}>{field.adminLabel || field.label} ({field.type})</option>
							{/each}
						</select>
					</label>

					<label class="sf:flex sf:flex-col sf:gap-1">
						<span class="sf:text-xs sf:text-slate-500">Operator</span>
						<select
							class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							value={node.operator}
							onchange={(event) =>
								updateRuleOperator(
									index,
									(event.target as HTMLSelectElement).value as ConditionOperator
								)}
							{disabled}
						>
							{#each CONDITION_OPERATORS as operator (operator)}
								<option value={operator}>{operatorLabels[operator]}</option>
							{/each}
						</select>
					</label>

					<Button
						type="button"
						variant="ghost"
						size="sm"
						class="sf:h-auto sf:border-transparent sf:bg-transparent sf:px-1 sf:py-0 sf:text-xs sf:text-rose-700 sf:underline hover:sf:bg-rose-50 hover:sf:text-rose-800"
						onclick={() => removeNode(index)}
						{disabled}
					>
						Remove rule
					</Button>
				</div>

				{#if operatorNeedsValue(node.operator)}
					{#if operatorNeedsList(node.operator)}
						<label class="sf:flex sf:flex-col sf:gap-1">
							<span class="sf:text-xs sf:text-slate-500">Values (comma-separated)</span>
							<input
								type="text"
								class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								value={listText(node.value)}
								oninput={(event) => setListValue(index, (event.target as HTMLInputElement).value)}
								placeholder="sales, billing, support"
								{disabled}
							/>
						</label>
					{:else if operatorNeedsNumeric(node.operator)}
						<label class="sf:flex sf:flex-col sf:gap-1">
							<span class="sf:text-xs sf:text-slate-500">Numeric value</span>
							<input
								type="number"
								step="any"
								class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								value={typeof node.value === 'number' ? node.value : Number(node.value ?? 0)}
								oninput={(event) =>
									updateRule(index, { value: Number((event.target as HTMLInputElement).value) })}
								{disabled}
							/>
						</label>
					{:else}
						<label class="sf:flex sf:flex-col sf:gap-1">
							<span class="sf:text-xs sf:text-slate-500">Value</span>
							<input
								type="text"
								class="sf:border sf:border-slate-300 sf:rounded-md sf:px-2 sf:py-1 sf:text-sm sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								value={typeof node.value === 'string' ? node.value : ''}
								oninput={(event) =>
									updateRule(index, { value: (event.target as HTMLInputElement).value })}
								{disabled}
							/>
						</label>
					{/if}
				{/if}
			</div>
		{/if}
	{/each}

	<div class="sf:flex sf:flex-wrap sf:gap-2">
		<Button
			type="button"
			variant="secondary"
			size="sm"
			onclick={addRule}
			{disabled}
		>
			Add rule
		</Button>

		{#if depth < MAX_CONDITION_DEPTH}
			<Button
				type="button"
				variant="secondary"
				size="sm"
				onclick={addGroup}
				{disabled}
			>
				Add group
			</Button>
		{/if}
	</div>
</div>
