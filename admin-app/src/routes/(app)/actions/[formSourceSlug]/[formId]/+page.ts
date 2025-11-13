import { error } from '@sveltejs/kit';
import type { PageLoad } from './$types';

export const load: PageLoad = ({ params }) => {
	const formId = Number.parseInt(params.formId, 10);

	if (Number.isNaN(formId) || formId <= 0) {
		throw error(404, 'Invalid form identifier');
	}

	return {
		formSourceSlug: params.formSourceSlug,
		formId
	};
};
