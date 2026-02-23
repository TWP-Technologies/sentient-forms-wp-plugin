import type { FormActionLinkage } from '$lib/api/types';
import type { DuplicateParentSelection, WorkflowPlanResponse } from '$lib/api/types';
import type { FormFieldInfo } from '$lib/api/types';
import type { MappingTriggerSourceRecord } from '$lib/utils/mapping-dependencies';

export type MappingDependencyDuplicateParentOption = {
	id: string;
	label: string;
	description?: string;
	parent: DuplicateParentSelection;
};

export type MappingDependencyGraphProps = {
	linkages?: FormActionLinkage[];
	editingMappingId?: string | null;
	draftDependencyIds?: string[];
	hookLabels?: Record<string, string>;
	hasUnsavedChanges?: boolean;
	workflowPlan?: WorkflowPlanResponse | null;
	workflowPlanLoading?: boolean;
	workflowPlanError?: string | null;
	formSourceSlug?: string;
	formId?: number;
	formFields?: FormFieldInfo[];
	onSetEditingMapping?: (mappingId: string | null) => void;
	onToggleDependency?: (mappingId: string) => void;
	onConnectDependency?: (
		sourceMappingId: string,
		targetMappingId: string,
		hook: string | null
	) => void;
	onDisconnectDependency?: (
		sourceMappingId: string,
		targetMappingId: string,
		hook: string | null
	) => void;
	onAttachRootDependency?: (mappingId: string, hook: string) => void;
	onUndoLastRootAttach?: () => void;
	canUndoRootAttach?: boolean;
	onClearDependencies?: () => void;
	pendingRemovalId?: string | null;
	onConfigureMapping?: (linkage: FormActionLinkage) => void;
	onToggleMappingEnabled?: (linkage: FormActionLinkage) => void | Promise<void>;
	onRequestRemoveMapping?: (linkage: FormActionLinkage) => void;
	onConfirmRemoveMapping?: (linkage: FormActionLinkage) => void | Promise<void>;
	onCancelRemoveMapping?: () => void;
	onDuplicateMapping?: (
		linkage: FormActionLinkage,
		parent: DuplicateParentSelection
	) => void | Promise<void>;
	duplicatingMappingId?: string | null;
	onSaveDependencies?: () => void | Promise<void>;
	onOpenAddAction?: () => void;
	onCancelDependencyEdit?: () => void;
	savingDependencies?: boolean;
};

export type MappingDependencyGraphActionNodeData = {
	kind: 'mapping';
	nodeId: string;
	mappingId: string;
	label: string;
	linkage: FormActionLinkage;
	editingMappingId: string | null;
	isEditingTarget: boolean;
	isSelectedDependency: boolean;
	isDisabled: boolean;
	isInvalid: boolean;
	invalidHooks: string[];
	isBlockedByDisabledUpstream: boolean;
	disabledUpstreamIds: string[];
	pendingRemovalId: string | null;
	canStartConnection: boolean;
	canAcceptConnection: boolean;
	availableRootHooks: string[];
	triggerHooks: string[];
	triggerSources: MappingTriggerSourceRecord;
	autonomousHooks: string[];
	hasConditionalRun: boolean;
	hookLabels: Record<string, string>;
	onToggleDependency: (mappingId: string) => void;
	onSetEditingMapping?: (mappingId: string) => void;
	onConfigureMapping: (linkage: FormActionLinkage) => void;
	onToggleMappingEnabled: (linkage: FormActionLinkage) => void | Promise<void>;
	onRequestRemoveMapping: (linkage: FormActionLinkage) => void;
	onConfirmRemoveMapping: (linkage: FormActionLinkage) => void | Promise<void>;
	onCancelRemoveMapping: () => void;
	onDuplicateMapping?: (
		linkage: FormActionLinkage,
		parent: DuplicateParentSelection
	) => void | Promise<void>;
	onDuplicatePopoverOpenChange?: (mappingId: string, open: boolean) => void;
	duplicateParentOptions: MappingDependencyDuplicateParentOption[];
	isDuplicating: boolean;
	isDuplicatePopoverOpen: boolean;
};

export type MappingDependencyGraphHookRootNodeData = {
	kind: 'hook_root';
	nodeId: string;
	hook: string;
	label: string;
	hookLabel: string;
	canStartConnection: boolean;
	sourceHandleIds: string[];
};

export type MappingDependencyGraphNodeData =
	| MappingDependencyGraphActionNodeData
	| MappingDependencyGraphHookRootNodeData;
