import { describe, expect, it } from 'vitest';

function channelToLinear(value: number): number {
	const normalized = value / 255;
	return normalized <= 0.03928
		? normalized / 12.92
		: ((normalized + 0.055) / 1.055) ** 2.4;
}

function hexToLuminance(hex: string): number {
	const cleaned = hex.replace('#', '');
	const red = parseInt(cleaned.slice(0, 2), 16);
	const green = parseInt(cleaned.slice(2, 4), 16);
	const blue = parseInt(cleaned.slice(4, 6), 16);
	return (
		0.2126 * channelToLinear(red) +
		0.7152 * channelToLinear(green) +
		0.0722 * channelToLinear(blue)
	);
}

function contrastRatio(hexA: string, hexB: string): number {
	const lumA = hexToLuminance(hexA);
	const lumB = hexToLuminance(hexB);
	const [lighter, darker] = lumA >= lumB ? [lumA, lumB] : [lumB, lumA];
	return (lighter + 0.05) / (darker + 0.05);
}

describe('accessibility focus contrast tokens', () => {
	const white = '#ffffff';
	const focusTokens = {
		primary: '#2563eb',
		secondary: '#475569',
		danger: '#ef4444',
		success: '#16a34a',
		warning: '#d97706'
	} as const;

	for (const [intent, token] of Object.entries(focusTokens)) {
		it(`${intent} focus token meets non-text contrast guidance against white`, () => {
			expect(contrastRatio(token, white)).toBeGreaterThanOrEqual(3);
		});
	}
});
