import { z } from 'zod';

const delimiterPattern = /[\s,;\t|]+/;
const emailSchema = z.email();
const webhookSchema = z.url({ protocol: /^https?$/ });

export function parseDelimitedList(value: string): string[] {
	return Array.from(
		new Set(
			value
				.split(delimiterPattern)
				.map((item) => item.trim())
				.filter(Boolean)
		)
	);
}

export function validateEmailTags(values: string[]): { values: string[]; errors: string[] } {
	const accepted: string[] = [];
	const errors: string[] = [];

	for (const value of values) {
		const result = emailSchema.safeParse(value);
		if (result.success) {
			accepted.push(result.data);
		} else {
			errors.push(`${value}: ${result.error.issues[0]?.message ?? 'Invalid email'}`);
		}
	}

	return { values: Array.from(new Set(accepted)), errors };
}

export function validateWebhookTags(values: string[]): { values: string[]; errors: string[] } {
	const accepted: string[] = [];
	const errors: string[] = [];

	for (const value of values) {
		const result = webhookSchema.safeParse(value);
		if (result.success) {
			accepted.push(result.data);
		} else {
			errors.push(`${value}: ${result.error.issues[0]?.message ?? 'Invalid HTTP URL'}`);
		}
	}

	return { values: Array.from(new Set(accepted)), errors };
}
