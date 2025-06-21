export async function api(endpoint, opts = {}) {
    const config = window.SentientFormsData || window.sentientFormsAdmin;
    
    if (!config?.apiBaseUrl) {
        return Promise.reject(new Error('Sentient Forms API configuration not found'));
    }
    
    if (!config.rest_nonce) {
        console.warn('Sentient Forms: Missing nonce, request may fail authentication');
    }
    
    const headers = opts.headers ?? {};
    headers['X-WP-Nonce'] = config.rest_nonce;
    
    return fetch(`${config.apiBaseUrl}${endpoint}`, {
        credentials: 'same-origin',
        ...opts,
        headers,
    });
}
