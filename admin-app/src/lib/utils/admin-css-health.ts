export const ADMIN_CSS_HEALTH_WARNING = '[Sentient Forms] Admin CSS health check failed';

export type AdminCssHealthFailure =
	| 'button-radius'
	| 'card-border'
	| 'card-radius'
	| 'card-background'
	| 'modal-radius';

export interface AdminCssHealthReport {
	ok: boolean;
	failures: AdminCssHealthFailure[];
	measurements: Record<string, number | string | null>;
}

type HealthCheckOptions = {
	schedule?: (callback: () => void) => void;
};

function readPixels(value: string): number {
	const parsed = Number.parseFloat(value);
	return Number.isFinite(parsed) ? parsed : 0;
}

function isTransparentBackground(value: string): boolean {
	return value === '' || value === 'transparent' || value === 'rgba(0, 0, 0, 0)';
}

function queryElement(root: ParentNode, selector: string): HTMLElement | null {
	const element = root.querySelector(selector);
	return element instanceof HTMLElement ? element : null;
}

function cssHealthProbeEnabled(): boolean {
	if (import.meta.env.DEV || import.meta.env.MODE === 'test') return true;
	return typeof window !== 'undefined' && Boolean(window.sentientFormsConfig?.devMode);
}

export function inspectAdminCssHealth(root: ParentNode = document): AdminCssHealthReport {
	const failures: AdminCssHealthFailure[] = [];
	const measurements: AdminCssHealthReport['measurements'] = {};

	const button = queryElement(
		root,
		'button[class*="sf:rounded"], [role="button"][class*="sf:rounded"]'
	);
	if (button) {
		const buttonRadius = readPixels(getComputedStyle(button).borderTopLeftRadius);
		measurements.buttonRadius = buttonRadius;
		if (buttonRadius <= 0) failures.push('button-radius');
	}

	const card = queryElement(root, '.sf-card');
	if (card) {
		const cardStyles = getComputedStyle(card);
		const cardBorder = readPixels(cardStyles.borderTopWidth);
		const cardRadius = readPixels(cardStyles.borderTopLeftRadius);
		measurements.cardBorder = cardBorder;
		measurements.cardRadius = cardRadius;
		measurements.cardBackground = cardStyles.backgroundColor;
		if (cardBorder <= 0) failures.push('card-border');
		if (cardRadius <= 0) failures.push('card-radius');
		if (isTransparentBackground(cardStyles.backgroundColor)) failures.push('card-background');
	}

	const modalShell = queryElement(
		root,
		'.sf-model-selector-shell, [role="dialog"][aria-modal="true"]'
	);
	if (modalShell) {
		const modalRadius = readPixels(getComputedStyle(modalShell).borderTopLeftRadius);
		measurements.modalRadius = modalRadius;
		if (modalRadius <= 0) failures.push('modal-radius');
	}

	return {
		ok: failures.length === 0,
		failures,
		measurements
	};
}

export function runAdminCssHealthCheck(
	root: ParentNode = document,
	options: HealthCheckOptions = {}
): void {
	if (!cssHealthProbeEnabled()) return;

	const schedule =
		options.schedule ??
		((callback: () => void) => {
			if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
				window.requestAnimationFrame(callback);
				return;
			}
			globalThis.setTimeout(callback, 0);
		});

	schedule(() => {
		const report = inspectAdminCssHealth(root);
		if (!report.ok) {
			console.warn(ADMIN_CSS_HEALTH_WARNING, report);
		}
	});
}
