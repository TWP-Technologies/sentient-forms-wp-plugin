import { describe, expect, it } from 'vitest';
import {
	countCollectedWpAdminNotices,
	relocateWpAdminNotices
} from '$lib/utils/wp-admin-notices';

describe('wp admin notice relocation', () => {
	it('moves WordPress notices out of the Sentient Forms frame into the notice tray', () => {
		document.body.innerHTML = `
			<div class="wrap">
				<div class="notice notice-warning"><p>Plugin update available.</p></div>
				<div id="sentient-forms-admin-app">
					<div data-sentient-admin-shell>
						<div data-sentient-wp-notice-tray>
							<div id="notice-tray"></div>
						</div>
						<div data-sentient-admin-frame>
							<aside>
								<div class="notice notice-error"><p>Injected into nav.</p></div>
							</aside>
							<main>Sentient Forms</main>
						</div>
					</div>
				</div>
			</div>
		`;

		const appRoot = document.getElementById('sentient-forms-admin-app');
		const tray = document.getElementById('notice-tray');
		expect(appRoot).toBeInstanceOf(HTMLElement);
		expect(tray).toBeInstanceOf(HTMLElement);
		if (!(appRoot instanceof HTMLElement) || !(tray instanceof HTMLElement)) return;

		const count = relocateWpAdminNotices(appRoot, tray);

		expect(count).toBe(2);
		expect(countCollectedWpAdminNotices(tray)).toBe(2);
		expect(tray.textContent).toContain('Plugin update available.');
		expect(tray.textContent).toContain('Injected into nav.');
		expect(document.querySelector('aside .notice')).toBeNull();
		expect(document.querySelector('.wrap > .notice')).toBeNull();
	});

	it('collects WordPress notices rendered as siblings of the Sentient Forms wrapper', () => {
		document.body.innerHTML = `
			<div id="wpbody-content">
				<div class="notice notice-info"><p>WP Rocket cache notice.</p></div>
				<div class="updated"><p>SureForms announcement.</p></div>
				<div class="wrap">
					<div id="sentient-forms-admin-app">
						<div data-sentient-admin-shell>
							<div data-sentient-wp-notice-tray>
								<div id="notice-tray"></div>
							</div>
							<nav>Dashboard Providers Actions</nav>
						</div>
					</div>
				</div>
			</div>
		`;

		const appRoot = document.getElementById('sentient-forms-admin-app');
		const tray = document.getElementById('notice-tray');
		expect(appRoot).toBeInstanceOf(HTMLElement);
		expect(tray).toBeInstanceOf(HTMLElement);
		if (!(appRoot instanceof HTMLElement) || !(tray instanceof HTMLElement)) return;

		const count = relocateWpAdminNotices(appRoot, tray);

		expect(count).toBe(2);
		expect(tray.textContent).toContain('WP Rocket cache notice.');
		expect(tray.textContent).toContain('SureForms announcement.');
		expect(document.querySelector('#wpbody-content > .notice')).toBeNull();
		expect(document.querySelector('#wpbody-content > .updated')).toBeNull();
		expect(document.querySelector('nav')?.textContent).toBe('Dashboard Providers Actions');
	});
});
