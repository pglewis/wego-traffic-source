const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';
const FORM_FIELD_TARGET_VALUE = 'wego-traffic-source';

// Main execution
setupDynamicEventTracking();
storeTrafficParams();
fillFormFields();

/**
 * Set up click handlers for dynamic event types from inline JSON config
 */
function setupDynamicEventTracking() {
	const configElement = document.querySelector('script.wego-tracking-config');
	if (!configElement) {
		return;
	}

	let config;
	try {
		config = JSON.parse(configElement.textContent);
	} catch (e) {
		console.error('WeGo Tracking: Failed to parse config', e);
		return;
	}

	if (!config.eventTypes || !Array.isArray(config.eventTypes)) {
		return;
	}

	// Set up click handler for each event type
	for (const eventType of config.eventTypes) {
		if (!eventType.selector || !eventType.slug) {
			continue;
		}

		document.addEventListener('click', (e) => {
			const target = e.target.closest(eventType.selector);
			if (!target || !target.hasAttribute('href')) {
				return;
			}

			const primaryValue = target.getAttribute('href');
			const trafficSource = determineTrafficSource();
			const deviceType = getDeviceType();
			const pagePath = window.location.pathname + window.location.search;
			const browserFamily = getBrowserFamily();
			const osFamily = getOsFamily();

			// Use sendBeacon for reliable delivery even during page unload
			// https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon
			navigator.sendBeacon(
				config.endpoint,
				new Blob(
					[JSON.stringify({
						event_type: eventType.slug,
						primary_value: primaryValue,
						traffic_source: trafficSource,
						device_type: deviceType,
						page_url: pagePath,
						browser_family: browserFamily,
						os_family: osFamily
					})],
					{ type: 'application/json' }
				)
			);
		});
	}
}

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
			'google': 'Google',
			'bing': 'Bing',
			'yahoo': 'Yahoo',
			'duckduckgo': 'DuckDuckGo'
		};
		const engine = Object.keys(searchEngines).find(e => refUrl.hostname.toLowerCase().includes(e));
		if (engine) {
			return `Organic Search: ${searchEngines[engine]}`;
		}

		// External referral
		return `Referral from ${refUrl.hostname}`;
	}

	// "Direct" if we have no other information
	return 'Direct';
}

/**
 * Detect device type based on user agent and viewport width
 * Uses standard industry heuristics for device detection
 */
function getDeviceType() {
	const ua = navigator.userAgent.toLowerCase();
	const width = window.innerWidth || document.documentElement.clientWidth;

	// Check for tablet indicators
	if (ua.includes('ipad') ||
		(ua.includes('android') && !ua.includes('mobile')) ||
		(ua.includes('tablet'))) {
		return 'Tablet';
	}

	// Check for mobile indicators
	if (ua.includes('mobile') ||
		ua.includes('iphone') ||
		ua.includes('ipod') ||
		width < 768) {
		return 'Mobile';
	}

	return 'Desktop';
}

/**
 * Detect browser family from user agent
 * Uses standard user agent patterns
 */
function getBrowserFamily() {
	const ua = navigator.userAgent;

	// Edge (Chromium-based) must be checked before Chrome
	if (ua.includes('Edg/') || ua.includes('Edge/')) {
		return 'Edge';
	}

	// Chrome must be checked before Safari (Chrome UA contains Safari)
	if (ua.includes('Chrome/')) {
		return 'Chrome';
	}

	if (ua.includes('Safari/')) {
		return 'Safari';
	}

	if (ua.includes('Firefox/')) {
		return 'Firefox';
	}

	// Opera
	if (ua.includes('OPR/') || ua.includes('Opera/')) {
		return 'Opera';
	}

	// Internet Explorer
	if (ua.includes('MSIE') || ua.includes('Trident/')) {
		return 'Internet Explorer';
	}

	return 'Other';
}

/**
 * Detect operating system family from user agent
 * Uses standard user agent patterns
 */
function getOsFamily() {
	const ua = navigator.userAgent;

	// iOS devices
	if (ua.includes('iPhone') || ua.includes('iPad') || ua.includes('iPod')) {
		return 'iOS';
	}

	// Android
	if (ua.includes('Android')) {
		return 'Android';
	}

	// Windows
	if (ua.includes('Windows')) {
		return 'Windows';
	}

	// macOS
	if (ua.includes('Macintosh') || ua.includes('Mac OS X')) {
		return 'macOS';
	}

	// Linux
	if (ua.includes('Linux')) {
		return 'Linux';
	}

	// Chrome OS
	if (ua.includes('CrOS')) {
		return 'Chrome OS';
	}

	return 'Other';
}
