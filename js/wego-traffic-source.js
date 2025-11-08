const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';
const FORM_FIELD_TARGET_VALUE = 'wego-traffic-source';

// Main execution
storeTrafficParams();
fillReferrerFields();

function storeTrafficParams() {
	// Early exit if we already have first-page-load data, don't overwrite
	const existingUtm = sessionStorage.getItem(STORAGE_KEY_UTM);
	const existingRef = sessionStorage.getItem(STORAGE_KEY_REFERRER);
	if (existingUtm || existingRef) {
		return;
	}

	// Collect any UTM data
	const url = new URL(window.location.href);
	const utmParams = {};
	const utmTags = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
	for (const param of utmTags) {
		const value = url.searchParams.get(param);
		if (value) {
			utmParams[param] = value;
		}
	}

	// Save any UTM data we found
	if (Object.keys(utmParams).length > 0) {
		sessionStorage.setItem(STORAGE_KEY_UTM, JSON.stringify(utmParams));
	}

	// Save referrer
	if (document.referrer) {
		sessionStorage.setItem(STORAGE_KEY_REFERRER, document.referrer);
	}
}

// Set the value of all matching form fields
function fillReferrerFields() {
	const hiddenFields = document.querySelectorAll('input[type="hidden"]');
	const value = determineTrafficSource();

	// Check hidden fields for ones we're targeting
	for (const hiddenField of hiddenFields) {
		if (hiddenField.value.toLowerCase() === FORM_FIELD_TARGET_VALUE.toLowerCase()) {
			hiddenField.value = value;
		}
	}
}

// Business logic for the string to be used as the hidden form field's value
function determineTrafficSource() {
	const storedUtm = sessionStorage.getItem(STORAGE_KEY_UTM);
	const storedRef = sessionStorage.getItem(STORAGE_KEY_REFERRER);

	// Check for UTM data - return early if we have tracking
	if (storedUtm) {
		const utmData = JSON.parse(storedUtm);
		const medium = utmData.utm_medium;
		if (medium) {
			const term = utmData.utm_term;
			return term ? `Tracked: ${medium} - ${term}` : `Tracked: ${medium}`;
		}
	}

	// Check for search engines and referrals
	if (storedRef) {
		let refUrl;
		try {
			refUrl = new URL(storedRef);
		} catch {
			return 'Direct';
		}
		const searchEngines = {
			'google': 'q',
			'bing': 'q',
			'yahoo': 'p',
			'duckduckgo': 'q'
		};
		const engine = Object.keys(searchEngines).find(e => refUrl.hostname.includes(e));
		if (engine) {
			return `Organic Search: ${engine}`;
		}
		// External referral
		return `Referral from ${refUrl.hostname}`;
	}

	// "Direct" if we have no other information
	return 'Direct';
}
