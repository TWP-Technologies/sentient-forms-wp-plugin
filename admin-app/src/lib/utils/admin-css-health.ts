export const ADMIN_CSS_HEALTH_WARNING = '[Sentient Forms] Admin CSS health check failed';

export type AdminCssHealthFailure =
	| 'button-radius'
	| 'button-target-missing'
	| 'card-border'
	| 'card-radius'
	| 'card-background'
	| 'card-target-missing'
	| 'modal-radius'
	| 'modal-target-missing';

export type AdminCssHealthTarget = 'button' | 'card' | 'modal';

export interface AdminCssHealthReport {
	ok: boolean;
	failures: AdminCssHealthFailure[];
	measurements: Record<string, number | string | null>;
	elementsChecked: number;
	targetsChecked: Record<AdminCssHealthTarget, boolean>;
}

type InspectOptions = {
	expectedTargets?: AdminCssHealthTarget[];
};

type HealthCheckOptions = InspectOptions & {
	maxAttempts?: number;
	schedule?: (callback: () => void) => void;
};

const DEFAULT_EXPECTED_TARGETS: AdminCssHealthTarget[] = ['button', 'card'];
const DEFAULT_MAX_ATTEMPTS = 3;
const DEFAULT_RETRY_DELAY_MS = 150;

const TARGET_MISSING_FAILURES: Record<AdminCssHealthTarget, AdminCssHealthFailure> = {
	button: 'button-target-missing',
	card: 'card-target-missing',
	modal: 'modal-target-missing'
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

export function inspectAdminCssHealth(
	root: ParentNode = document,
	options: InspectOptions = {}
): AdminCssHealthReport {
	const failures: AdminCssHealthFailure[] = [];
	const measurements: AdminCssHealthReport['measurements'] = {};
	const targetsChecked: AdminCssHealthReport['targetsChecked'] = {
		button: false,
		card: false,
		modal: false
	};
	let elementsChecked = 0;

	const button = queryElement(
		root,
		'button[class*="sf:rounded"], [role="button"][class*="sf:rounded"]'
	);
	if (button) {
		targetsChecked.button = true;
		elementsChecked += 1;
		const buttonRadius = readPixels(getComputedStyle(button).borderTopLeftRadius);
		measurements.buttonRadius = buttonRadius;
		if (buttonRadius <= 0) failures.push('button-radius');
	}

	const card = queryElement(root, '.sf-card');
	if (card) {
		targetsChecked.card = true;
		elementsChecked += 1;
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
		targetsChecked.modal = true;
		elementsChecked += 1;
		const modalRadius = readPixels(getComputedStyle(modalShell).borderTopLeftRadius);
		measurements.modalRadius = modalRadius;
		if (modalRadius <= 0) failures.push('modal-radius');
	}

	for (const target of new Set(options.expectedTargets ?? [])) {
		if (!targetsChecked[target]) failures.push(TARGET_MISSING_FAILURES[target]);
	}

	return {
		ok: failures.length === 0,
		failures,
		measurements,
		elementsChecked,
		targetsChecked
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
			const runAfterDelay = () => globalThis.setTimeout(callback, DEFAULT_RETRY_DELAY_MS);
			if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
				window.requestAnimationFrame(() => {
					window.requestAnimationFrame(runAfterDelay);
				});
				return;
			}
			runAfterDelay();
		});

	const expectedTargets = options.expectedTargets ?? DEFAULT_EXPECTED_TARGETS;
	const maxAttempts = options.maxAttempts ?? DEFAULT_MAX_ATTEMPTS;

	const inspect = (attempt: number) => {
		const report = inspectAdminCssHealth(root, { expectedTargets });
		const targetMissing = report.failures.some((failure) => failure.endsWith('-target-missing'));
		if (targetMissing && attempt < maxAttempts) {
			schedule(() => inspect(attempt + 1));
			return;
		}
		if (!report.ok) {
			console.warn(ADMIN_CSS_HEALTH_WARNING, report);
		}
	};

	schedule(() => inspect(1));
}
