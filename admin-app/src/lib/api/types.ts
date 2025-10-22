export interface LicenseActivationRequest {
	licenseKey: string;
	siteUrl: string;
	localSiteIdentifier: string;
}

export interface LicenseActivationResult {
	success: boolean;
	message: string;
	status: string;
	proxyApiKey?: string;
	tier?: string;
	expiryDate?: string | null;
	licenseId?: string;
	siteId?: string;
}

export interface LicenseActivationResponsePayload {
	success?: boolean;
	message?: string;
	status?: string;
	proxy_api_key?: string;
	tier?: string;
	expiry_date?: string | null;
	license_id?: string;
	site_id?: string;
}

export interface LicenseInfoResponse {
	license_key_masked: string;
	status: string;
	proxy_key_present: boolean;
	expires_at: string | null;
	last_synced: string | null;
	tier: string | null;
	license_id: string | null;
	site_id: string | null;
	site_url: string;
}

export interface ApiErrorPayload {
	error_code?: string;
	message?: string;
	[key: string]: unknown;
}
