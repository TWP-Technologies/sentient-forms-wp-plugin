import { describe, expect, it } from 'vitest';
import {
	createDefaultRealtimeSettings,
	normalizeRealtimeSettings,
	summarizeRealtimeSettings,
	toDerivedRefreshMode
} from '$lib/utils/realtime-settings';

describe('realtime settings helpers', () => {
	it('includes every configurable realtime default used by action, form, and mapping scopes', () => {
		expect(createDefaultRealtimeSettings()).toMatchObject({
			auto_refresh_enabled: true,
			field_checkpoints_enabled: false,
			checkpoint_field_ids: [],
			page_checkpoints_enabled: false,
			page_checkpoint_mode: 'all_pages',
			page_checkpoint_pages: [],
			page_checkpoint_timeout_ms: 2500,
			storage_target_field_id: '',
			debounce_ms: 900,
			cooldown_ms: 8000,
			manual_refresh_enabled: true,
			blocking_mode: 'advisory',
			refresh_mode: 'auto',
			initial_panel_state: 'minimized',
			hidden_field_exposure_mode: 'label_hidden',
			pre_submit_run_enabled: false,
			pre_submit_timeout_ms: 2500
		});
	});

	it('normalizes legacy checkpoint refresh mode into explicit field checkpoint triggers', () => {
		const normalized = normalizeRealtimeSettings({
			refresh_mode: 'checkpoint',
			checkpoint_field_ids: ['1', '1', ' 2 '],
			debounce_ms: 50,
			cooldown_ms: -100
		});

		expect(normalized.auto_refresh_enabled).toBe(false);
		expect(normalized.field_checkpoints_enabled).toBe(true);
		expect(normalized.checkpoint_field_ids).toEqual(['1', '2']);
		expect(normalized.debounce_ms).toBe(250);
		expect(normalized.cooldown_ms).toBe(0);
		expect(toDerivedRefreshMode(normalized)).toBe('checkpoint');
	});

	it('summarizes composed auto, manual, field, and page triggers for the admin UI', () => {
		const summary = summarizeRealtimeSettings({
			auto_refresh_enabled: true,
			manual_refresh_enabled: true,
			field_checkpoints_enabled: true,
			checkpoint_field_ids: ['1', '2'],
			page_checkpoints_enabled: true,
			storage_target_field_id: '9',
			blocking_mode: 'require_answers'
		});

		expect(summary).toBe('auto + manual + 2 field checkpoints + page checkpoints · stores in 9 · required answers');
	});
});
