/**
 * Configuration object for WeGo tracking system.
 * Injected into page as <script type="application/json" class="wego-tracking-config">
 *
 * @typedef {Object} WeGoTrackingConfig
 * @property {string} endpoint - REST API URL where event data is sent via sendBeacon
 * @property {WeGoEventType[]} eventTypes - Array of event type definitions to track
 *
 * @typedef {Object} WeGoEventType
 * @property {string} slug - Event type identifier, sent to API as event_type
 * @property {WeGoEventSource} event_source - Event source configuration
 *
 * @typedef {Object} WeGoEventSource
 * @property {string} type - Type of event source ('link_click' or 'podium_widget')
 * @property {string} [selector] - CSS selector for link_click events
 * @property {string[]} [events] - Array of Podium event names for podium_widget events
 *
 * @example
 * {
 *   "endpoint": "https://example.com/wp-json/wego/v1/track-event",
 *   "eventTypes": [
 *     {
 *       "slug": "tel_clicks",
 *       "event_source": {
 *         "type": "link_click",
 *         "selector": "a[href^='tel:']"
 *       }
 *     },
 *     {
 *       "slug": "podium_events",
 *       "event_source": {
 *         "type": "podium_widget",
 *         "events": ["Bubble Clicked", "Conversation Started"]
 *       }
 *     }
 *   ]
 * }
 *
 * @remarks
 * - For link_click: Only elements with an href attribute will trigger events, href becomes primary_value
 * - For podium_widget: PodiumEventsCallback is set up to handle Podium events
 * - Click events bubble, so selector matches against clicked element and ancestors
 * - Config is generated server-side via output_tracking_config() in PHP
 */

// Storage keys
const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';

// Event source type constants
const EVENT_SOURCE_TYPE_LINK_CLICK = 'link_click';
const EVENT_SOURCE_TYPE_PODIUM_WIDGET = 'podium_widget';

const FORM_FIELD_TARGET_VALUE = 'wego-traffic-source';
const SELECTOR_CONFIG_DATA_SCRIPT = 'script.wego-tracking-config';

// Main execution
setupEventTracking();
storeTrafficSourceData();
populateFormFields();

// ========== Core Setup & Execution ==========

/**
 * Validate and type-narrow the raw config object
 *
 * @param {any} rawConfig - The parsed JSON config
 * @returns {WeGoTrackingConfig|null} Validated config or null if invalid
 */
function validateTrackingConfig( rawConfig ) {
	if ( !rawConfig || typeof rawConfig !== 'object' ) {
		return null;
	}
	if ( !rawConfig.eventTypes || !Array.isArray( rawConfig.eventTypes ) ) {
		return null;
	}
	if ( !rawConfig.endpoint || typeof rawConfig.endpoint !== 'string' ) {
		return null;
	}

	// Basic validation passed, return as typed config
	return /** @type {WeGoTrackingConfig} */ rawConfig;
}

/**
 * Set up handlers for the event types in the inline JSON config
 */
function setupEventTracking() {
	const configElement = document.querySelector( SELECTOR_CONFIG_DATA_SCRIPT );
	if ( !configElement ) {
		return;
	}

	let config;
	try {
		const parsed = JSON.parse( configElement.textContent );
		config = validateTrackingConfig( parsed );
		if ( !config ) {
			console.error( 'WeGo Tracking: Invalid config structure' );
			return;
		}
	} catch ( e ) {
		console.error( 'WeGo Tracking: Failed to parse config', e );
		return;
	}

	// Set up tracking for each event type based on its source type
	for ( const eventType of config.eventTypes ) {
		if ( !eventType.event_source || !eventType.slug ) {
			continue;
		}

		switch ( eventType.event_source.type ) {
			case EVENT_SOURCE_TYPE_LINK_CLICK:
				setupLinkClickTracking( eventType, config.endpoint );
				break;

			case EVENT_SOURCE_TYPE_PODIUM_WIDGET:
				setupPodiumEventTracking( eventType, config.endpoint );
				break;
		}
	}
}

// ========== Event Tracking Setup ==========

/**
 * Set up click tracking for link-based events
 *
 * @param {WeGoEventType} eventType - The event type configuration
 * @param {string} endpoint - The REST API endpoint URL
 */
function setupLinkClickTracking( eventType, endpoint ) {
	document.addEventListener( 'click', ( e ) => {
		const target = e.target.closest( eventType.event_source.selector );
		if ( !target || !target.hasAttribute( 'href' ) ) {
			return;
		}

		// href is the primary value for link click events
		sendEventBeacon( endpoint, eventType.slug, target.getAttribute( 'href' ) );
	} );
}

/**
 * Set up tracking for Podium widget events
 *
 * @param {WeGoEventType} eventType - The Podium event type configuration
 * @param {string} endpoint - The REST API endpoint URL
 */
function setupPodiumEventTracking( eventType, endpoint ) {
	/**
	 * Podium widget event callback
	 * @param {string} eventName - The Podium event name that triggered
	 * @param {any} properties - Additional event properties from Podium
	 */
	window.PodiumEventsCallback = function( eventName, properties ) {
		if ( eventType.event_source.events.includes( eventName ) ) {
			// The Podium event name is display-ready for the primary value
			sendEventBeacon( endpoint, eventType.slug, eventName );
		}
	};
}

// ========== Event Transmission ==========

/**
 * Send event data via sendBeacon
 *
 * @param {string} endpoint - The REST API endpoint URL
 * @param {string} eventSlug - The event type slug
 * @param {string} primaryValue - The primary value for the event
 */
function sendEventBeacon( endpoint, eventSlug, primaryValue ) {
	const trafficSource = determineTrafficSource();
	const deviceType = getDeviceType();
	const pagePath = window.location.pathname + window.location.search;
	const browserFamily = getBrowserFamily();
	const osFamily = getOsFamily();

	// Use sendBeacon for reliable delivery even during page unload
	// https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon
	navigator.sendBeacon(
		endpoint,
		new Blob(
			[ JSON.stringify( {
				event_type: eventSlug,
				primary_value: primaryValue,
				traffic_source: trafficSource,
				device_type: deviceType,
				page_url: pagePath,
				browser_family: browserFamily,
				os_family: osFamily
			} ) ],
			{ type: 'application/json' }
		)
	);
}

// ========== Traffic Source Management ==========

function storeTrafficSourceData() {
	// Early exit if we already have first-page-load data, don't overwrite
	const existingUtm = sessionStorage.getItem( STORAGE_KEY_UTM );
	const existingRef = sessionStorage.getItem( STORAGE_KEY_REFERRER );
	if ( existingUtm || existingRef ) {
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
			// If URL parsing fails, preserve the raw referrer so malformed
			// values can be inspected later
			referrer = document.referrer;
		}
	}

	// Always save referrer, even if empty. Trim to avoid whitespace-only values downstream.
	sessionStorage.setItem(STORAGE_KEY_REFERRER, referrer.trim());
}

// Determine what to show for referrer
function determineTrafficSource() {
	const storedUtm = sessionStorage.getItem( STORAGE_KEY_UTM );
	const storedRef = sessionStorage.getItem( STORAGE_KEY_REFERRER );

	// Check for UTM data - return early if we have tracking
	if ( storedUtm ) {
		const utmData = JSON.parse( storedUtm );
		const medium = utmData.utm_medium;
		if ( medium ) {
			const term = utmData.utm_term;
			return term ? `Tracked: ${medium} - ${term}` : `Tracked: ${medium}`;
		}
	}

	// Check for search engines and referrals
	if ( storedRef ) {
		let refUrl;
		try {
			refUrl = new URL( storedRef );
		} catch {
			// Malformed referrer: return a short, safe preview
			const preview = storedRef.length > 100 ? storedRef.slice( 0, 100 ) + '…' : storedRef;
			return `Malformed Referral: ${ preview }`;
		}
		const hostname = refUrl.hostname.toLowerCase();

		// Known email and search engine providers
		const knownReferrers = [
			{ label: 'Email: Gmail', domains: [ 'mail.google.com', 'inbox.google.com', 'com.google.android.gm' ] },
			{ label: 'Email: Outlook', domains: [ 'mail.live.com', 'outlook.live.com', 'com.microsoft.office.outlook' ] },
			{ label: 'Email: Yahoo Mail', domains: [ 'mail.yahoo.com', 'mail.yahoo.co.uk', 'com.yahoo.mobile.client.android.mail' ] },
			{ label: 'Email: AOL', domains: [ 'mail.aol.com', 'com.aol.mobile.aolapp' ] },
			{ label: 'Email: Proton', domains: [ 'mail.proton.me' ] },
			{ label: 'Organic Search: Google', domains: [ 'www.google.com', 'google.com', 'google.co.uk', 'google.ca' ] },
			{ label: 'Organic Search: Bing', domains: [ 'bing.com', 'www.bing.com', 'm.bing.com' ] },
			{ label: 'Organic Search: Yahoo', domains: [ 'search.yahoo.com', 'yahoo.com' ] },
			{ label: 'Organic Search: DuckDuckGo', domains: [ 'duckduckgo.com' ] },
			{ label: 'Organic Search: Brave', domains: [ 'search.brave.com' ] }
		];

		for ( const ref of knownReferrers ) {
			// Exact matches only, check the Snowplow database for a curated list of domain names
			if ( ref.domains.some( d => hostname === d ) ) {
				return ref.label;
			}
		}

		// External referral (no match found)
		return `Referral from ${ refUrl.hostname }`;
	}

	// "Direct" if we have no other information
	return 'Direct';
}

// Set the value of all matching form fields
function populateFormFields() {
	const hiddenFields = document.querySelectorAll( 'input[type="hidden"]' );
	const value = determineTrafficSource();

	// Check hidden fields for ones we're targeting
	for ( const hiddenField of hiddenFields ) {
		if ( hiddenField.value.toLowerCase() === FORM_FIELD_TARGET_VALUE.toLowerCase() ) {
			hiddenField.value = value;
		}
	}
}

// ========== Device/Browser Detection ==========

/**
 * Detect device type based on user agent and viewport width
 * Uses standard industry heuristics for device detection
 */
function getDeviceType() {
	const ua = navigator.userAgent.toLowerCase();
	const width = window.innerWidth || document.documentElement.clientWidth;

	// Check for tablet indicators
	if ( ua.includes( 'ipad' ) ||
		( ua.includes( 'android' ) && !ua.includes( 'mobile' ) ) ||
		( ua.includes( 'tablet' ) ) ) {
		return 'Tablet';
	}

	// Check for mobile indicators
	if ( ua.includes( 'mobile' ) ||
		ua.includes( 'iphone' ) ||
		ua.includes( 'ipod' ) ||
		width < 768 ) {
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
	if ( ua.includes( 'Edg/' ) || ua.includes( 'Edge/' ) ) {
		return 'Edge';
	}

	// Chrome must be checked before Safari (Chrome UA contains Safari)
	if ( ua.includes( 'Chrome/' ) ) {
		return 'Chrome';
	}

	if ( ua.includes( 'Safari/' ) ) {
		return 'Safari';
	}

	if ( ua.includes( 'Firefox/' ) ) {
		return 'Firefox';
	}

	// Opera
	if ( ua.includes( 'OPR/' ) || ua.includes( 'Opera/' ) ) {
		return 'Opera';
	}

	// Internet Explorer
	if ( ua.includes( 'MSIE' ) || ua.includes( 'Trident/' ) ) {
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
	if ( ua.includes( 'iPhone' ) || ua.includes( 'iPad' ) || ua.includes( 'iPod' ) ) {
		return 'iOS';
	}

	// Android
	if ( ua.includes( 'Android' ) ) {
		return 'Android';
	}

	// Windows
	if ( ua.includes( 'Windows' ) ) {
		return 'Windows';
	}

	// macOS
	if ( ua.includes( 'Macintosh' ) || ua.includes( 'Mac OS X' ) ) {
		return 'macOS';
	}

	// Linux
	if ( ua.includes( 'Linux' ) ) {
		return 'Linux';
	}

	// Chrome OS
	if ( ua.includes( 'CrOS' ) ) {
		return 'Chrome OS';
	}

	return 'Other';
}
