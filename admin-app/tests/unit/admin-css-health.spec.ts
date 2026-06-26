import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	ADMIN_CSS_HEALTH_WARNING,
	inspectAdminCssHealth,
	runAdminCssHealthCheck
} from '$lib/utils/admin-css-health';

afterEach(() => {
	document.body.innerHTML = '';
	vi.restoreAllMocks();
});

describe('admin CSS health probe', () => {
	it('reports healthy button, card, and modal shell styles', () => {
		document.body.innerHTML = `
			<button class="sf:rounded" style="border-radius: 4px">Refresh</button>
			<div class="sf-card" style="border: 1px solid rgb(203, 213, 225); border-radius: 12px; background: white"></div>
			<div role="dialog" aria-modal="true" style="border-radius: 12px"></div>
		`;

		const report = inspectAdminCssHealth(document);

		expect(report.ok).toBe(true);
		expect(report.elementsChecked).toBe(3);
		expect(report.targetsChecked).toEqual({ button: true, card: true, modal: true });
		expect(report.failures).toEqual([]);
		expect(report.measurements.buttonRadius).toBeGreaterThan(0);
		expect(report.measurements.cardBorder).toBeGreaterThan(0);
		expect(report.measurements.cardRadius).toBeGreaterThan(0);
		expect(report.measurements.modalRadius).toBeGreaterThan(0);
	});

	it('reports missing critical computed styles without mutating the document', () => {
		document.body.innerHTML = `
			<button class="sf:rounded" style="border-radius: 0">Refresh</button>
			<div class="sf-card" style="border: 0 solid transparent; border-radius: 0; background: transparent"></div>
			<div role="dialog" aria-modal="true" style="border-radius: 0"></div>
		`;
		const originalMarkup = document.body.innerHTML;

		const report = inspectAdminCssHealth(document);

		expect(report.ok).toBe(false);
		expect(report.failures).toEqual([
			'button-radius',
			'card-border',
			'card-radius',
			'card-background',
			'modal-radius'
		]);
		expect(document.body.innerHTML).toBe(originalMarkup);
	});

	it('reports missing default probe targets instead of silently passing an empty DOM', () => {
		const report = inspectAdminCssHealth(document);

		expect(report.ok).toBe(false);
		expect(report.elementsChecked).toBe(0);
		expect(report.targetsChecked).toEqual({ button: false, card: false, modal: false });
		expect(report.failures).toEqual(['button-target-missing', 'card-target-missing']);
	});

	it('falls back to default targets when runtime options are malformed', () => {
		const report = inspectAdminCssHealth(document, {
			expectedTargets: ['card', 'unknown-target']
		} as never);

		expect(report.ok).toBe(false);
		expect(report.failures).toEqual(['button-target-missing', 'card-target-missing']);
		expect(report.failures).not.toContain(undefined);
	});

	it('logs a concise warning when the scheduled probe detects failures', () => {
		document.body.innerHTML =
			'<button class="sf:rounded" style="border-radius: 0">Refresh</button>';
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

		runAdminCssHealthCheck(document, {
			expectedTargets: ['button'],
			schedule: (callback) => callback()
		});

		expect(warn).toHaveBeenCalledWith(
			ADMIN_CSS_HEALTH_WARNING,
			expect.objectContaining({
				ok: false,
				failures: ['button-radius']
			})
		);
	});

	it('retries the scheduled probe so async-rendered card styles can be checked', () => {
		const callbacks: Array<() => void> = [];
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
		document.body.innerHTML =
			'<button class="sf:rounded" style="border-radius: 4px">Refresh</button>';

		runAdminCssHealthCheck(document, {
			expectedTargets: ['button', 'card'],
			maxAttempts: 2,
			schedule: (callback) => callbacks.push(callback)
		});

		expect(callbacks).toHaveLength(1);
		callbacks.shift()?.();
		expect(warn).not.toHaveBeenCalled();

		document.body.insertAdjacentHTML(
			'beforeend',
			'<div class="sf-card" style="border: 1px solid rgb(203, 213, 225); border-radius: 12px; background: white"></div>'
		);
		expect(callbacks).toHaveLength(1);
		callbacks.shift()?.();

		expect(warn).not.toHaveBeenCalled();
	});
});
