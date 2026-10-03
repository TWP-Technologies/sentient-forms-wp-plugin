import { describe, expect, it } from 'vitest';
import { resolveSpamDeliveryHolds } from '$lib/utils/spam-failure-delivery';

describe('configured spam delivery holds', () => {
	const base = {
		notificationCapability: true,
		webhookCapability: true,
		applies: true,
		mapping: {},
		form: {},
		action: {}
	};
	it('uses inherited holds, while explicit channel Allow defeats unknown facts', () => {
		expect(
			resolveSpamDeliveryHolds({
				...base,
				mapping: { suppress_notifications_on_spam: false },
				notificationCapability: undefined,
				form: undefined,
				action: undefined,
				webhookCapability: false
			})
		).toEqual({ state: 'inactive', channels: [] });
		expect(
			resolveSpamDeliveryHolds({ ...base, action: { suppress_webhooks_on_spam: false } })
		).toEqual({ state: 'active', channels: ['notifications'] });
	});
	it('keeps a known supported channel active beside a known unavailable channel', () => {
		expect(resolveSpamDeliveryHolds({ ...base, webhookCapability: false })).toEqual({
			state: 'active',
			channels: ['notifications']
		});
	});
	it('does not interpret a missing capability or inheritance response as false', () => {
		expect(resolveSpamDeliveryHolds({ ...base, webhookCapability: undefined }).state).toBe(
			'unavailable'
		);
		expect(resolveSpamDeliveryHolds({ ...base, form: undefined }).state).toBe('unavailable');
	});
	it('recognizes each known exclusion even when remaining facts are unknown', () => {
		for (const excluded of [
			{ applies: false },
			{ notificationCapability: false, webhookCapability: false },
			{ mapping: { suppress_notifications_on_spam: false, suppress_webhooks_on_spam: false } }
		]) {
			expect(
				resolveSpamDeliveryHolds({ ...base, form: undefined, action: undefined, ...excluded })
			).toEqual({ state: 'inactive', channels: [] });
		}
	});
	it('restores applicability after holds are enabled without changing a stored choice', () => {
		const mapping = {
			spam_failure_delivery_policy: 'allow_delivery' as const,
			suppress_notifications_on_spam: false
		};
		expect(resolveSpamDeliveryHolds({ ...base, webhookCapability: false, mapping }).state).toBe(
			'inactive'
		);
		expect(
			resolveSpamDeliveryHolds({
				...base,
				webhookCapability: false,
				mapping: { ...mapping, suppress_notifications_on_spam: true }
			}).state
		).toBe('active');
		expect(mapping.spam_failure_delivery_policy).toBe('allow_delivery');
	});
});
