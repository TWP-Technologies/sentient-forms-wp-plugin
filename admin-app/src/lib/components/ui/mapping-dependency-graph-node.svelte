<script lang="ts">
	import { Handle, Position, type NodeProps } from '@xyflow/svelte';
	import Badge from './badge.svelte';
	import Button from './button.svelte';
	import type { MappingDependencyGraphActionNodeData } from './mapping-dependency-graph.types';
	import {
		DEPENDENCY_SOURCE_HANDLE_ID,
		DEPENDENCY_TARGET_HANDLE_ID,
		hookRootTargetHandleId
	} from '$lib/utils/mapping-dependency-xyflow';

	type Props = NodeProps & {
		data: MappingDependencyGraphActionNodeData;
	};

	let { data }: Props = $props();

	function isNodeEnabled(): boolean {
		return data.linkage.is_action_enabled_for_form !== false;
	}

	function nodeClass(): string {
		const stateClass = data.isInvalid
			? 'sf:border-rose-300 sf:bg-rose-50/40'
			: data.isDisabled
				? 'sf:border-slate-300 sf:bg-slate-50 sf:opacity-80 sf:grayscale-[0.22]'
				: data.isBlockedByDisabledUpstream
					? 'sf:border-slate-200 sf:bg-slate-50 sf:opacity-70 sf:grayscale-[0.32]'
					: 'sf:border-slate-200';

		if (data.isEditingTarget) {
			return `${stateClass} sf:border-blue-500 sf:ring-2 sf:ring-blue-200`;
		}
		if (data.editingMappingId && data.isSelectedDependency) {
			return `${stateClass} sf:border-emerald-500 sf:bg-emerald-50`;
		}
		return stateClass;
	}

	function setEditingTarget(): void {
		data.onSetEditingMapping?.(data.nodeId);
	}

	function displayHookLabel(hook: string): string {
		return data.hookLabels[hook] ?? hook;
	}

	function statusBadgeClass(): string {
		if (data.isInvalid) {
			return 'sf:inline-flex sf:items-center sf:rounded-full sf:border sf:border-rose-200 sf:bg-rose-50 sf:px-2.5 sf:py-0.5 sf:text-xs sf:font-medium sf:text-rose-700';
		}
		if (isNodeEnabled()) {
			return 'sf:inline-flex sf:items-center sf:cursor-pointer sf:rounded-full sf:border sf:border-success-200 sf:bg-success-50 sf:px-2.5 sf:py-0.5 sf:text-xs sf:font-medium sf:text-success-600 sf:shadow-sm sf:hover:bg-success-100 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-success-600 sf:focus-visible:ring-offset-1';
		}
		return 'sf:inline-flex sf:items-center sf:cursor-pointer sf:rounded-full sf:border sf:border-warning-200 sf:bg-warning-50 sf:px-2.5 sf:py-0.5 sf:text-xs sf:font-medium sf:text-warning-700 sf:shadow-sm sf:hover:bg-warning-100 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-warning-600 sf:focus-visible:ring-offset-1';
	}

	function sourceHandleStyle(): string {
		return 'top:50%; width:18px; height:18px; background:#3b82f6; border:2px solid #ffffff; box-shadow:0 0 0 1px #1d4ed8; z-index:5; pointer-events:all; cursor:crosshair;';
	}

	function dependencyTargetHandleStyle(): string {
		return 'top:50%; width:18px; height:18px; background:#64748b; border:2px solid #ffffff; box-shadow:0 0 0 1px #475569; z-index:5; pointer-events:all; cursor:copy;';
	}

	function rootTargetHooks(): string[] {
		return Array.from(new Set(data.availableRootHooks ?? [])).sort();
	}
	const rootHooks = $derived(rootTargetHooks());

	const dependentSources = $derived.by(() => {
		const mappings = Object.values(data.triggerSources ?? {})
			.filter((source) => source.type === 'mapping' && Boolean(source.mappingId))
			.map((source) => source.mappingId as string);
		return Array.from(new Set(mappings));
	});

	function rootTargetHandleStyle(index: number, total: number): string {
		const span = Math.max(total - 1, 1);
		const ratio = total <= 1 ? 0.5 : index / span;
		const topPercent = 30 + ratio * 40;
		return `top:${topPercent}%; width:16px; height:16px; background:#93c5fd; border:2px solid #ffffff; box-shadow:0 0 0 1px #3b82f6; z-index:5; pointer-events:all; cursor:copy;`;
	}

	function invalidHooksTitle(): string {
		if (data.invalidHooks.length === 0) return 'No valid trigger source configured.';
		return `Missing trigger source: ${data.invalidHooks.join(', ')}`;
	}

	function serializeAnchorRect(anchor: DOMRect | null | undefined) {
		if (!anchor) return null;
		return {
			left: anchor.left,
			top: anchor.top,
			right: anchor.right,
			bottom: anchor.bottom,
			width: anchor.width,
			height: anchor.height
		};
	}

	function setDuplicatePopoverOpen(open: boolean, anchor?: DOMRect | null): void {
		if (data.isDuplicatePopoverOpen === open) return;
		data.onDuplicatePopoverOpenChange?.({
			mappingId: data.nodeId,
			open,
			anchorRect: open ? serializeAnchorRect(anchor) : null
		});
	}

	function toggleDuplicatePopover(event: MouseEvent): void {
		event.stopPropagation();
		if (data.isDuplicating) return;
		const button = event.currentTarget as HTMLElement | null;
		setDuplicatePopoverOpen(!data.isDuplicatePopoverOpen, button?.getBoundingClientRect() ?? null);
	}

	$effect(() => {
		if (data.pendingRemovalId === data.nodeId && data.isDuplicatePopoverOpen) {
			setDuplicatePopoverOpen(false);
		}
	});

	$effect(() => {
		if (data.isDuplicating && data.isDuplicatePopoverOpen) {
			setDuplicatePopoverOpen(false);
		}
	});
</script>

<Handle
	type="target"
	position={Position.Left}
	id={DEPENDENCY_TARGET_HANDLE_ID}
	isConnectableStart={false}
	isConnectableEnd={data.canAcceptConnection}
	style={dependencyTargetHandleStyle()}
/>

{#each rootHooks as hook, index (hook)}
	<Handle
		type="target"
		position={Position.Left}
		id={hookRootTargetHandleId(hook)}
		isConnectableStart={false}
		isConnectableEnd={data.canAcceptConnection}
		style={rootTargetHandleStyle(index, rootHooks.length)}
	/>
{/each}

<Handle
	type="source"
	position={Position.Right}
	id={DEPENDENCY_SOURCE_HANDLE_ID}
	isConnectableStart={data.canStartConnection}
	isConnectableEnd={false}
	style={sourceHandleStyle()}
/>

<div
	class={`sf:w-[320px] sf:rounded-md sf:border sf:bg-white sf:p-3 sf:shadow-sm sf:text-left sf:space-y-2 sf:cursor-pointer ${nodeClass()}`}
	data-testid={`dependency-node-card-${data.nodeId}`}
	onclick={setEditingTarget}
	role="button"
	tabindex={0}
	onkeydown={(event) => {
		if (event.key !== 'Enter' && event.key !== ' ') return;
		event.preventDefault();
		setEditingTarget();
	}}
>
	<div class="sf:flex sf:items-start sf:justify-between sf:gap-2">
		<div>
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{data.label}</p>
				{#if data.hasConditionalRun}
					<span
						class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-md sf:border sf:border-amber-200 sf:bg-amber-50 sf:px-1.5 sf:py-0.5 sf:text-[10px] sf:font-semibold sf:text-amber-800"
						title="Conditional run enabled"
					>
						<svg
							class="sf:h-3 sf:w-3"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							aria-hidden="true"
						>
							<path d="M12 3v18"></path>
							<path d="M17 8H9a2 2 0 1 1 0-4h8"></path>
							<path d="M7 16h8a2 2 0 1 1 0 4H7"></path>
						</svg>
						Conditional
					</span>
				{/if}
			</div>
			<p class="sf:text-[11px] sf:text-slate-500">{data.nodeId}</p>
		</div>
		{#if data.isInvalid}
			<span
				class={statusBadgeClass()}
				title={invalidHooksTitle()}
				data-testid={`dependency-node-invalid-${data.nodeId}`}
			>
				Invalid
			</span>
		{:else}
			<button
				type="button"
				class={statusBadgeClass()}
				aria-label={`Toggle ${data.label} ${isNodeEnabled() ? 'disabled' : 'enabled'} state`}
				data-testid={`dependency-node-toggle-enabled-${data.nodeId}`}
				onclick={(event) => {
					event.stopPropagation();
					void data.onToggleMappingEnabled(data.linkage);
				}}
			>
				{isNodeEnabled() ? 'Enabled' : 'Disabled'}
			</button>
		{/if}
	</div>

	<div class="sf:flex sf:flex-wrap sf:gap-1">
		{#if dependentSources.length > 0}
			<span class="sf:text-[11px] sf:text-slate-500">
				Triggered by action: {dependentSources.join(', ')}
			</span>
		{:else if data.autonomousHooks.length > 0}
			{#each data.autonomousHooks as hook (hook)}
				<Badge variant="info">{displayHookLabel(hook)}</Badge>
			{/each}
		{:else}
			<p class="sf:text-[11px] sf:text-slate-500">No autonomous triggers configured</p>
		{/if}
	</div>

	{#if data.isInvalid}
		<p class="sf:text-[11px] sf:text-rose-700">
			Missing upstream source for: {data.invalidHooks.join(', ')}
		</p>
	{/if}

	{#if data.isBlockedByDisabledUpstream}
		<p class="sf:text-[11px] sf:text-amber-700">
			Blocked by disabled upstream:
			{data.disabledUpstreamIds.join(', ')}
		</p>
	{/if}

	<div
		class="sf:flex sf:items-center sf:justify-between sf:gap-2 sf:pt-1 sf:border-t sf:border-slate-100"
	>
		<div
			class="sf:flex sf:items-center sf:gap-1"
			data-testid={`dependency-node-actions-left-${data.nodeId}`}
		>
			<Button
				size="sm"
				variant="ghost"
				onclick={(event) => {
					event.stopPropagation();
					data.onConfigureMapping(data.linkage);
				}}
				class="nodrag"
				data-testid={`dependency-node-configure-${data.nodeId}`}
			>
				Configure
			</Button>
		</div>
		<div
			class="sf:flex sf:items-center sf:gap-1 sf:relative"
			data-testid={`dependency-node-actions-right-${data.nodeId}`}
		>
			{#if data.pendingRemovalId !== data.nodeId}
				<div class="sf:relative">
						<Button
							size="sm"
							variant="ghost"
							iconOnly
							aria-label={`Duplicate ${data.label}`}
							onclick={toggleDuplicatePopover}
							class="nodrag"
							data-testid={`dependency-node-duplicate-open-${data.nodeId}`}
							disabled={data.isDuplicating || data.duplicateParentOptions.length === 0}
						>
						<svg
							class="sf:h-4 sf:w-4"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							stroke-linejoin="round"
							aria-hidden="true"
						>
							<rect x="9" y="9" width="11" height="11" rx="2"></rect>
							<rect x="4" y="4" width="11" height="11" rx="2"></rect>
						</svg>
					</Button>
					</div>
				{/if}
			{#if data.pendingRemovalId === data.nodeId}
				<Button
					size="sm"
					variant="danger"
					iconOnly
					aria-label={`Confirm removal of ${data.label}`}
					onclick={(event) => {
						event.stopPropagation();
						void data.onConfirmRemoveMapping(data.linkage);
					}}
					class="nodrag"
					data-testid={`dependency-node-remove-confirm-${data.nodeId}`}
				>
					<svg
						class="sf:h-4 sf:w-4"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
					>
						<path d="M5 12l5 5L20 7"></path>
					</svg>
				</Button>
				<Button
					size="sm"
					variant="secondary"
					iconOnly
					aria-label={`Cancel removal of ${data.label}`}
					onclick={(event) => {
						event.stopPropagation();
						data.onCancelRemoveMapping();
					}}
					class="nodrag"
					data-testid={`dependency-node-remove-cancel-${data.nodeId}`}
				>
					<svg
						class="sf:h-4 sf:w-4"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
					>
						<path d="M18 6L6 18"></path>
						<path d="M6 6l12 12"></path>
					</svg>
				</Button>
			{:else}
				<Button
					size="sm"
					variant="ghost"
					iconOnly
					aria-label={`Remove ${data.label}`}
					onclick={(event) => {
						event.stopPropagation();
						data.onRequestRemoveMapping(data.linkage);
					}}
					class="nodrag"
					data-testid={`dependency-node-remove-${data.nodeId}`}
				>
					<svg
						class="sf:h-4 sf:w-4"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
					>
						<path d="M3 6h18"></path>
						<path d="M8 6V4h8v2"></path>
						<path d="M6 6l1 14h10l1-14"></path>
						<path d="M10 11v6"></path>
						<path d="M14 11v6"></path>
					</svg>
				</Button>
			{/if}
		</div>
	</div>
</div>
