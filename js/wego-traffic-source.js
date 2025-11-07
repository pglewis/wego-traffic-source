const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';
const FORM_FIELD_MARKER = 'wego-traffic-source';

// Main execution
storeTrafficParams();
fillReferrerFields();

function storeTrafficParams() {
	// Only store if this is the first page load (storage is empty)
	const existingUtm = sessionStorage.getItem(STORAGE_KEY_UTM);
	const existingRef = sessionStorage.getItem(STORAGE_KEY_REFERRER);

	// Early exit if we already have first-page-load data, don't overwrite
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
	const referrerFields = document.querySelectorAll('input[type="hidden"]');
	const value = determineTrafficSource();

	for (const referrerField of referrerFields) {
		if (referrerField && referrerField.value === FORM_FIELD_MARKER) {
			referrerField.value = value;
		}
	}
}

// Business logic for the string to be used as the hidden form field's value
function determineTrafficSource() {
	const storedUtm = sessionStorage.getItem(STORAGE_KEY_UTM);
	const storedRef = sessionStorage.getItem(STORAGE_KEY_REFERRER);

	// Check for PPC
	if (storedUtm) {
		const utmData = JSON.parse(storedUtm);
		const medium = (utmData.utm_medium || '').toLowerCase();
		if (medium && (medium.includes('cpc') || medium.includes('ppc') || medium.includes('paid'))) {
			return 'PPC';
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
			const searchParams = new URLSearchParams(refUrl.search);
			const searchTerm = searchParams.get(searchEngines[engine]);
			return searchTerm ?
				`Search: ${engine} - "${searchTerm}"` :
				`Search: ${engine}`;
		}
		// External referral
		return `Referral from ${refUrl.hostname}`;
	}

	// "Direct" if we have no other information
	return 'Direct';
}