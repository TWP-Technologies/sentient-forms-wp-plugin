import { error } from '@sveltejs/kit';
import type { PageLoad } from './$types';

export const load: PageLoad = ({ params }) => {
	const formId = params.formId.trim();

	if (!formId) {
		throw error(404, 'Invalid form identifier');
	}

	return {
		formSourceSlug: params.formSourceSlug,
		formId
	};
};
