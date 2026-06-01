import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { test, type Locator, type Page } from '@playwright/test';

type ScreenshotTarget = Page | Locator;

function isPage(target: ScreenshotTarget): target is Page {
	return typeof (target as Page).viewportSize === 'function';
}

export async function saveGreenlightScreenshot(
	target: ScreenshotTarget,
	name: string,
	options: { fullPage?: boolean } = {}
): Promise<string | null> {
	const screenshot = isPage(target)
		? await target.screenshot({ fullPage: options.fullPage ?? true, type: 'png' })
		: await target.screenshot({ type: 'png' });

	await test.info().attach(`${name}.png`, {
		body: screenshot,
		contentType: 'image/png'
	});

	const artifactDir = process.env.SENTIENT_FORMS_GREENLIGHT_ARTIFACT_DIR;
	if (!artifactDir) return null;

	mkdirSync(artifactDir, { recursive: true });
	const filePath = path.join(artifactDir, `${name}.png`);
	writeFileSync(filePath, screenshot);
	return filePath;
}
