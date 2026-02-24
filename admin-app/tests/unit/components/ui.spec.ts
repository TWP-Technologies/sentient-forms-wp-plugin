import { describe, it, expect } from 'vitest';
import {
	buttonStyles,
	controlIntentToVariant,
	variantForIntent
} from '$lib/components/ui/buttonStyles';
import { badgeStyles } from '$lib/components/ui/badgeStyles';

describe('UI class generators', () => {
	it('provides primary button styles', () => {
		const classes = buttonStyles({ variant: 'primary', size: 'md' });
		expect(classes).toContain('sf:bg-primary-600');
		expect(classes).toContain('sf:border-primary-700');
		expect(classes).toContain('sf:focus-visible:ring-primary-500');
		expect(classes).toContain('sf:h-10');
	});

	it('provides stronger secondary and ghost affordances', () => {
		const secondary = buttonStyles({ variant: 'secondary', size: 'md' });
		expect(secondary).toContain('sf:border-slate-300');
		expect(secondary).toContain('sf:shadow-sm');
		expect(secondary).toContain('sf:hover:border-slate-400');

		const ghost = buttonStyles({ variant: 'ghost', size: 'md' });
		expect(ghost).toContain('sf:border-slate-300');
		expect(ghost).toContain('sf:bg-slate-50/70');
		expect(ghost).toContain('sf:hover:bg-slate-100');
	});

	it('maps control intents to button variants', () => {
		expect(controlIntentToVariant).toEqual({
			primary: 'primary',
			secondary: 'secondary',
			tertiary: 'ghost',
			danger: 'danger'
		});
		expect(variantForIntent('tertiary')).toBe('ghost');
		expect(variantForIntent('danger')).toBe('danger');
	});

	it('provides badge variants', () => {
		const classes = badgeStyles({ variant: 'success' });
		expect(classes).toContain('sf:bg-success-50');
	});
});
