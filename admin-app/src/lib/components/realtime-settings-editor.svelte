<script lang="ts">
	import { Alert, Toggle } from '$lib/components/ui';
	import type { SelectOption } from '$lib/components/ui/types';
	import type {
		FormFieldInfo,
		RealtimeBlockingMode,
		RealtimeHiddenFieldExposureMode,
		RealtimeInitialPanelState,
		RealtimePageCheckpointMode,
		RealtimeSettings
	} from '$lib/api/types';
	import {
		REALTIME_BLOCKING_OPTIONS,
		REALTIME_HIDDEN_FIELD_EXPOSURE_OPTIONS,
		REALTIME_INITIAL_PANEL_OPTIONS,
		REALTIME_PAGE_CHECKPOINT_OPTIONS,
		normalizeRealtimeSettings,
		toDerivedRefreshMode
	} from '$lib/utils/realtime-settings';

	type RealtimeSettingsScope = 'action' | 'form' | 'mapping';

	interface Props {
		idPrefix: string;
		value?: RealtimeSettings;
		scope?: RealtimeSettingsScope;
		formFields?: FormFieldInfo[];
		storageFieldOptions?: SelectOption[];
		totalPages?: number;
		onchange?: (settings: RealtimeSettings) => void;
	}

	let {
		idPrefix,
		value = $bindable(),
		scope = 'mapping',
		formFields = [],
		storageFieldOptions = [{ value: '', label: 'Automatic hidden field (recommended)' }],
		totalPages = 1,
		onchange
	}: Props = $props();

	const settings = $derived(normalizeRealtimeSettings(value));
	const isFormSpecificScope = $derived(scope !== 'action');
	const checkpointFields = $derived(
		formFields.filter((field) => field.type !== 'hidden' && field.type !== 'page')
	);
	const pageOptions = $derived(
		Array.from({ length: Math.max(1, Math.min(200, Math.round(totalPages || 1))) }, (_, index) => index + 1)
	);
	const controlClass =
		'sf:h-9 sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:text-sm sf:text-slate-900 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1';
	const compactInputClass =
		'sf:h-9 sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:text-sm sf:text-slate-900 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1';

	function updateRealtimeSettings(partial: Partial<RealtimeSettings>) {
		const next = normalizeRealtimeSettings({
			...settings,
			...partial
		});
		next.refresh_mode = toDerivedRefreshMode(next);
		value = next;
		onchange?.(next);
	}

	function toggleCheckpointField(fieldId: string) {
		const next = new Set(settings.checkpoint_field_ids ?? []);
		next.has(fieldId) ? next.delete(fieldId) : next.add(fieldId);
		updateRealtimeSettings({ checkpoint_field_ids: Array.from(next) });
	}

	function toggleCheckpointPage(page: number) {
		const next = new Set(settings.page_checkpoint_pages ?? []);
		next.has(page) ? next.delete(page) : next.add(page);
		updateRealtimeSettings({ page_checkpoint_pages: Array.from(next).sort((a, b) => a - b) });
	}

	function numberFromInput(event: Event, fallback: number) {
		const parsed = Number.parseInt((event.currentTarget as HTMLInputElement).value || String(fallback), 10);
		return Number.isFinite(parsed) ? parsed : fallback;
	}
</script>

<div class="sf:space-y-7" data-testid={`${idPrefix}-realtime-settings`}>
	<header>
		<h3 class="sf:text-base sf:font-semibold sf:text-slate-900">Real-time assistant behavior</h3>
		<p class="sf:mt-1 sf:text-xs sf:leading-snug sf:text-slate-500">
			Configure when and how the clarification assistant interacts with visitors during form completion.
		</p>
	</header>

	<section class="sf:space-y-3">
		<h4 class="sf:text-sm sf:font-semibold sf:text-slate-900">Trigger settings</h4>
		<div class="sf:divide-y sf:divide-slate-200 sf:border-y sf:border-slate-200">
			<div class="sf:py-3">
				<Toggle
					id={`${idPrefix}-auto-refresh`}
					checked={settings.auto_refresh_enabled}
					label="Auto refresh after input"
					description="Runs after visible field changes using debounce and cooldown."
					onchange={(event) =>
						updateRealtimeSettings({ auto_refresh_enabled: event.detail.checked })}
				/>

				{#if settings.auto_refresh_enabled}
					<div class="sf:ml-14 sf:mt-3 sf:grid sf:gap-3 sf:sm:grid-cols-2">
						<div>
							<label
								class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
								for={`${idPrefix}-debounce`}
							>
								Debounce (ms)
							</label>
							<input
								id={`${idPrefix}-debounce`}
								class={compactInputClass}
								type="number"
								min="250"
								max="5000"
								value={settings.debounce_ms}
								oninput={(event) =>
									updateRealtimeSettings({ debounce_ms: numberFromInput(event, 900) })}
							/>
						</div>
						<div>
							<label
								class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
								for={`${idPrefix}-cooldown`}
							>
								Cooldown (ms)
							</label>
							<input
								id={`${idPrefix}-cooldown`}
								class={compactInputClass}
								type="number"
								min="0"
								max="60000"
								value={settings.cooldown_ms}
								oninput={(event) =>
									updateRealtimeSettings({ cooldown_ms: numberFromInput(event, 8000) })}
							/>
						</div>
					</div>
				{/if}
			</div>

			{#if isFormSpecificScope}
				<div class="sf:py-3">
					<Toggle
						id={`${idPrefix}-field-checkpoints`}
						checked={settings.field_checkpoints_enabled}
						label="Field checkpoints"
						description="Also run when selected fields are completed."
						onchange={(event) =>
							updateRealtimeSettings({ field_checkpoints_enabled: event.detail.checked })}
					/>

					{#if settings.field_checkpoints_enabled}
						<div
							class="sf:ml-14 sf:mt-3"
							data-testid={`${idPrefix}-field-checkpoint-list`}
						>
							{#if checkpointFields.length === 0}
								<p class="sf:text-sm sf:text-slate-500">No visible input fields loaded for this form yet.</p>
							{:else}
								<div class="sf:flex sf:flex-col sf:gap-2">
									{#each checkpointFields as field (field.id)}
										<label class="sf:flex sf:min-h-6 sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700">
											<input
												type="checkbox"
												class="sf:h-4 sf:w-4 sf:rounded-sm sf:border-slate-300 sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1"
												checked={settings.checkpoint_field_ids?.includes(field.id)}
												onchange={() => toggleCheckpointField(field.id)}
												data-testid={`${idPrefix}-field-checkpoint-${field.id}`}
											/>
											<span>{field.adminLabel || field.label || `Field ${field.id}`} ({field.id})</span>
										</label>
									{/each}
								</div>
							{/if}
						</div>
					{/if}
				</div>

				<div class="sf:py-3">
					<Toggle
						id={`${idPrefix}-page-checkpoints`}
						checked={settings.page_checkpoints_enabled}
						label="Page checkpoints"
						description="Run before the visitor leaves selected form pages."
						onchange={(event) =>
							updateRealtimeSettings({ page_checkpoints_enabled: event.detail.checked })}
					/>

					{#if settings.page_checkpoints_enabled}
						<div
							class="sf:ml-14 sf:mt-3 sf:space-y-3"
							data-testid={`${idPrefix}-page-checkpoint-options`}
						>
							<div class="sf:grid sf:gap-3 sf:sm:grid-cols-2">
								<div>
									<label
										class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
										for={`${idPrefix}-page-rule`}
									>
										Page rule
									</label>
									<select
										id={`${idPrefix}-page-rule`}
										class={controlClass}
										value={settings.page_checkpoint_mode}
										onchange={(event) =>
											updateRealtimeSettings({
												page_checkpoint_mode: event.currentTarget
													.value as RealtimePageCheckpointMode
											})}
									>
										{#each REALTIME_PAGE_CHECKPOINT_OPTIONS as option}
											<option value={option.value}>{option.label}</option>
										{/each}
									</select>
								</div>
								<div>
									<label
										class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
										for={`${idPrefix}-page-timeout`}
									>
										Page timeout (ms)
									</label>
									<input
										id={`${idPrefix}-page-timeout`}
										class={compactInputClass}
										type="number"
										min="500"
										max="10000"
										value={settings.page_checkpoint_timeout_ms}
										aria-describedby={`${idPrefix}-page-timeout-description`}
										oninput={(event) =>
											updateRealtimeSettings({
												page_checkpoint_timeout_ms: numberFromInput(event, 2500)
											})}
									/>
									<p
										class="sf:mt-1 sf:text-[11px] sf:leading-snug sf:text-slate-500"
										id={`${idPrefix}-page-timeout-description`}
									>
										Navigation continues after this wait.
									</p>
								</div>
							</div>

							{#if settings.page_checkpoint_mode !== 'all_pages'}
								<div class="sf:flex sf:flex-col sf:gap-2">
									{#each pageOptions as page}
										<label class="sf:flex sf:min-h-6 sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700">
											<input
												type="checkbox"
												class="sf:h-4 sf:w-4 sf:rounded-sm sf:border-slate-300 sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1"
												checked={settings.page_checkpoint_pages?.includes(page)}
												onchange={() => toggleCheckpointPage(page)}
												data-testid={`${idPrefix}-page-checkpoint-${page}`}
											/>
											<span>Page {page}</span>
										</label>
									{/each}
								</div>
							{/if}
						</div>
					{/if}
				</div>
			{/if}

			<div class="sf:py-3">
				<Toggle
					id={`${idPrefix}-manual-refresh`}
					checked={settings.manual_refresh_enabled}
					label="Manual refresh"
					description="Shows a visitor refresh button."
					onchange={(event) =>
						updateRealtimeSettings({ manual_refresh_enabled: event.detail.checked })}
				/>
			</div>
		</div>

		{#if !isFormSpecificScope}
			<Alert variant="info">
				<p class="sf:text-sm">
					Field checkpoints, page checkpoints, and storage fields are configured on a specific
					form so the choices match that form's fields and pages.
				</p>
			</Alert>
		{/if}
	</section>

	<section class="sf:space-y-3">
		<h4 class="sf:text-sm sf:font-semibold sf:text-slate-900">Submission behavior</h4>
		<div class="sf:divide-y sf:divide-slate-200 sf:border-y sf:border-slate-200">
			<div class="sf:py-3">
				<Toggle
					id={`${idPrefix}-pre-submit`}
					checked={settings.pre_submit_run_enabled}
					label="Run before submit"
					description="Run one final assistant pass before the final Gravity Forms submit."
					onchange={(event) =>
						updateRealtimeSettings({ pre_submit_run_enabled: event.detail.checked })}
				/>

				{#if settings.pre_submit_run_enabled}
					<div class="sf:ml-14 sf:mt-3 sf:grid sf:gap-4 sf:sm:grid-cols-2">
						<div>
							<label
								class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
								for={`${idPrefix}-pre-submit-timeout`}
							>
								Pre-submit timeout (ms)
							</label>
							<input
								id={`${idPrefix}-pre-submit-timeout`}
								class={compactInputClass}
								type="number"
								min="500"
								max="10000"
								value={settings.pre_submit_timeout_ms}
								aria-describedby={`${idPrefix}-pre-submit-timeout-description`}
								oninput={(event) =>
									updateRealtimeSettings({ pre_submit_timeout_ms: numberFromInput(event, 2500) })}
							/>
							<p
								class="sf:mt-1 sf:text-[11px] sf:leading-snug sf:text-slate-500"
								id={`${idPrefix}-pre-submit-timeout-description`}
							>
								Submission continues after this wait.
							</p>
						</div>

						<div>
							<label
								class="sf:mb-1 sf:block sf:text-xs sf:font-medium sf:text-slate-700"
								for={`${idPrefix}-submit-policy`}
							>
								Submit policy
							</label>
							<select
								id={`${idPrefix}-submit-policy`}
								class={controlClass}
								value={settings.blocking_mode}
								aria-describedby={`${idPrefix}-submit-policy-description`}
								onchange={(event) =>
									updateRealtimeSettings({
										blocking_mode: event.currentTarget.value as RealtimeBlockingMode
									})}
							>
								{#each REALTIME_BLOCKING_OPTIONS as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
							<p
								class="sf:mt-1 sf:text-[11px] sf:leading-snug sf:text-slate-500"
								id={`${idPrefix}-submit-policy-description`}
							>
								Choose whether required AI questions can hold submission.
							</p>
						</div>
					</div>
				{/if}
			</div>
		</div>
	</section>

	<section class="sf:space-y-3">
		<h4 class="sf:text-sm sf:font-semibold sf:text-slate-900">Context sent to AI</h4>
		<div class="sf:ml-14 sf:space-y-4">
			<div class="sf:max-w-sm">
				<label
					class="sf:mb-1 sf:block sf:text-sm sf:font-medium sf:text-slate-800"
					for={`${idPrefix}-hidden-field-context`}
				>
					Hidden field context
				</label>
				<p class="sf:mb-2 sf:text-xs sf:leading-snug sf:text-slate-500">
					Controls fields hidden by conditional logic or pagination.
				</p>
				<select
					id={`${idPrefix}-hidden-field-context`}
					class={controlClass}
					value={settings.hidden_field_exposure_mode}
					onchange={(event) =>
						updateRealtimeSettings({
							hidden_field_exposure_mode: event.currentTarget
								.value as RealtimeHiddenFieldExposureMode
						})}
				>
					{#each REALTIME_HIDDEN_FIELD_EXPOSURE_OPTIONS as option}
						<option value={option.value}>{option.label}</option>
					{/each}
				</select>
			</div>

			{#if settings.hidden_field_exposure_mode === 'label_hidden_value' ||
				settings.hidden_field_exposure_mode === 'label_value'}
				<Alert variant="warning">
					<p class="sf:text-sm">
						This setting can send values from fields the visitor cannot currently see. Use it
						only when those fields are intentionally part of the assistant context.
					</p>
				</Alert>
			{/if}

			<div class="sf:max-w-sm">
				<label
					class="sf:mb-1 sf:block sf:text-sm sf:font-medium sf:text-slate-800"
					for={`${idPrefix}-initial-panel-state`}
				>
					Initial panel
				</label>
				<p class="sf:mb-2 sf:text-xs sf:leading-snug sf:text-slate-500">
					Controls the visitor-facing assistant panel on form load.
				</p>
				<select
					id={`${idPrefix}-initial-panel-state`}
					class={controlClass}
					value={settings.initial_panel_state}
					onchange={(event) =>
						updateRealtimeSettings({
							initial_panel_state: event.currentTarget.value as RealtimeInitialPanelState
						})}
				>
					{#each REALTIME_INITIAL_PANEL_OPTIONS as option}
						<option value={option.value}>{option.label}</option>
					{/each}
				</select>
			</div>
		</div>
	</section>

	{#if isFormSpecificScope}
		<section class="sf:space-y-3">
			<h4 class="sf:text-sm sf:font-semibold sf:text-slate-900">Storage</h4>
			<div class="sf:ml-14 sf:max-w-sm">
				<label
					class="sf:mb-1 sf:block sf:text-sm sf:font-medium sf:text-slate-800"
					for={`${idPrefix}-storage-target`}
				>
					Virtual Q&A storage
				</label>
				<p class="sf:mb-2 sf:text-xs sf:leading-snug sf:text-slate-500">
					Stores serialized virtual question and answer JSON before submit.
				</p>
				<select
					id={`${idPrefix}-storage-target`}
					class={controlClass}
					value={settings.storage_target_field_id}
					onchange={(event) =>
						updateRealtimeSettings({
							storage_target_field_id: event.currentTarget.value
						})}
				>
					{#each storageFieldOptions as option}
						<option value={option.value} disabled={option.disabled}>{option.label}</option>
					{/each}
				</select>
			</div>
		</section>
	{/if}
</div>
