const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';
const FORM_FIELD_TARGET_VALUE = 'wego-traffic-source';
const API_ENDPOINT_TEL_CLICK = '/wp-json/wego/v1/track-tel-click';

// Main execution
setupEventListeners();
storeTrafficParams();
fillFormFields();

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

	// Always save UTM data, even if empty
	sessionStorage.setItem(STORAGE_KEY_UTM, JSON.stringify(utmParams));

	// Check for external referrer
	let referrer = '';
	if (document.referrer) {
		// Don't log ourself as referrer
		try {
			const referrerUrl = new URL(document.referrer);
			const currentUrl = new URL(window.location.href);
			if (referrerUrl.hostname !== currentUrl.hostname) {
				referrer = document.referrer;
			}
		} catch {
			// If URL parsing fails, leave referrer as empty string
		}
	}

	// Always save referrer, even if empty
	sessionStorage.setItem(STORAGE_KEY_REFERRER, referrer);
}

// Set the value of all matching form fields
function fillFormFields() {
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

function setupEventListeners() {
	// Track tel link clicks
	document.addEventListener('click', handleTelLinkClick);
}

function handleTelLinkClick(e) {
	const target = e.target.closest('a[href^="tel:"]');
	if (target) {
		const phoneNumber = target.getAttribute('href').replace('tel:', '');
		const trafficSource = determineTrafficSource();

		// Send to WordPress REST API
		fetch(API_ENDPOINT_TEL_CLICK, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({
				phone_number: phoneNumber,
				traffic_source: trafficSource
			})
		}).catch(err => {
			console.error('Failed to track tel click:', err);
		});
	}
}