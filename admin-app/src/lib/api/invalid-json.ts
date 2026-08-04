export interface InvalidJsonResponsePayload {
	code: 'invalid_json_response';
	error_code: 'invalid_json_response';
	message: string;
	status: number;
	content_type: string;
	body_length: number;
	url: string;
}

export async function readResponseText(response: Response): Promise<string> {
	const textReader = (response as Response & { text?: () => Promise<string> }).text;
	if (typeof textReader === 'function') {
		return textReader.call(response);
	}

	const jsonReader = (response as Response & { json?: () => Promise<unknown> }).json;
	if (typeof jsonReader === 'function') {
		const payload = await jsonReader.call(response);
		return JSON.stringify(payload) ?? '';
	}

	return '';
}

export function buildInvalidJsonResponsePayload(
	response: Response,
	bodyText: string,
	requestUrl: string
): InvalidJsonResponsePayload {
	const contentType = response.headers.get('content-type') ?? '';
	const responseUrl =
		typeof response.url === 'string' && response.url.length > 0 ? response.url : requestUrl;

	return {
		code: 'invalid_json_response',
		error_code: 'invalid_json_response',
		message:
			'Sentient Forms received invalid JSON from WordPress. The response may contain stray output before the JSON body.',
		status: response.status,
		content_type: contentType,
		body_length: bodyText.length,
		url: responseUrl
	};
}

export function hasContaminatedJsonPrefix(bodyText: string): boolean {
	const prefix = bodyText.slice(firstNonJsonWhitespaceIndex(bodyText));
	if (prefix.startsWith('\uFEFF')) {
		return true;
	}

	const codePoint = prefix.codePointAt(0);
	return typeof codePoint === 'number' && isUnsafeControlCharacter(codePoint);
}

function firstNonJsonWhitespaceIndex(value: string): number {
	for (let index = 0; index < value.length; index += 1) {
		const codePoint = value.codePointAt(index);
		if (codePoint !== 0x09 && codePoint !== 0x0a && codePoint !== 0x0d && codePoint !== 0x20) {
			return index;
		}
	}

	return value.length;
}

function isUnsafeControlCharacter(codePoint: number): boolean {
	return (
		(codePoint >= 0x00 && codePoint <= 0x08) ||
		codePoint === 0x0b ||
		codePoint === 0x0c ||
		(codePoint >= 0x0e && codePoint <= 0x1f) ||
		codePoint === 0x7f
	);
}
