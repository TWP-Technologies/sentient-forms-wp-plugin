export const WP_ADMIN_NOTICE_SELECTOR = '.notice, .updated, .error, .update-nag';

function uniqueElements(elements: Iterable<Element>): HTMLElement[] {
	const seen = new Set<Element>();
	const unique: HTMLElement[] = [];
	for (const element of elements) {
		if (!(element instanceof HTMLElement) || seen.has(element)) continue;
		seen.add(element);
		unique.push(element);
	}
	return unique;
}

function noticeSources(appRoot: HTMLElement): ParentNode[] {
	const seen = new Set<ParentNode>();
	const sources: ParentNode[] = [];
	const addSource = (source: ParentNode | null) => {
		if (!source || seen.has(source)) return;
		seen.add(source);
		sources.push(source);
	};

	addSource(appRoot);
	if (appRoot.parentElement) {
		addSource(appRoot.parentElement);
	}

	addSource(appRoot.closest('#wpbody-content'));

	return sources;
}

function isInsideNoticeTray(element: HTMLElement, trayContent: HTMLElement): boolean {
	return trayContent.contains(element) || Boolean(element.closest('[data-sentient-wp-notice-tray]'));
}

export function countCollectedWpAdminNotices(trayContent: HTMLElement): number {
	return trayContent.querySelectorAll(WP_ADMIN_NOTICE_SELECTOR).length;
}

export function relocateWpAdminNotices(
	appRoot: HTMLElement,
	trayContent: HTMLElement
): number {
	const candidates = uniqueElements(
		noticeSources(appRoot).flatMap((source) =>
			Array.from(source.querySelectorAll(WP_ADMIN_NOTICE_SELECTOR))
		)
	);

	for (const notice of candidates) {
		if (isInsideNoticeTray(notice, trayContent)) continue;
		trayContent.appendChild(notice);
	}

	return countCollectedWpAdminNotices(trayContent);
}
