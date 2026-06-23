import { afterEach, describe, expect, it } from 'vitest';
import {
	loadSharedManagedZdrRequirement,
	resetSharedManagedZdrRequirementForTests
} from '$lib/utils/managed-zdr-requirement-cache';

afterEach(() => {
	resetSharedManagedZdrRequirementForTests();
});

describe('loadSharedManagedZdrRequirement', () => {
	it('deduplicates concurrent settings reads without keeping a stale value forever', async () => {
		let calls = 0;
		const first = loadSharedManagedZdrRequirement(async () => {
			calls += 1;
			return { managed_zdr_required: true };
		});
		const second = loadSharedManagedZdrRequirement(async () => {
			calls += 1;
			return { managed_zdr_required: false };
		});

		await expect(first).resolves.toBe(true);
		await expect(second).resolves.toBe(true);
		expect(calls).toBe(1);

		await expect(
			loadSharedManagedZdrRequirement(async () => {
				calls += 1;
				return { managed_zdr_required: false };
			})
		).resolves.toBe(false);
		expect(calls).toBe(2);
	});
});
