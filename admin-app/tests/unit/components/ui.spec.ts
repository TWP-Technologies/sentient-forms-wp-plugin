import { describe, it, expect } from 'vitest';
import { buttonStyles } from '$lib/components/ui/buttonStyles';
import { badgeStyles } from '$lib/components/ui/badgeStyles';

describe('UI class generators', () => {
	it('provides primary button styles', () => {
		const classes = buttonStyles({ variant: 'primary', size: 'md' });
		expect(classes).toContain('sf-bg-primary-600');
		expect(classes).toContain('sf-h-10');
	});

	it('provides badge variants', () => {
		const classes = badgeStyles({ variant: 'success' });
		expect(classes).toContain('sf-bg-success-50');
	});
});
