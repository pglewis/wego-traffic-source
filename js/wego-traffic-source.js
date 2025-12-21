/**
 * Configuration object for WeGo tracking system.
 * Injected into page as <script type="application/json" class="wego-tracking-config">
 *
 * @typedef {Object} WeGoTrackingConfig
 * @property {string} endpoint - REST API URL where event data is sent via sendBeacon
 * @property {WeGoTrackedEvent[]} trackedEvents - Array of tracked event definitions
 *
 * @typedef {Object} WeGoTrackedEvent
 * @property {string} slug - Tracked event identifier, sent to API as event_type
 * @property {WeGoEventSource} eventSource - Event source configuration
 *
 * @typedef {Object} WeGoLinkClickSource
 * @property {'link_click'} type - Event source type
 * @property {string} selector - CSS selector for link click events
 *
 * @typedef {Object} WeGoFormSubmitSource
 * @property {'form_submit'} type - Event source type
 * @property {string} selector - CSS selector for form submit events
 *
 * @typedef {Object} WeGoPodiumWidgetSource
 * @property {'podium_widget'} type - Event source type
 * @property {string[]} events - Array of Podium event names
 *
 * @typedef {Object} WeGoYouTubeVideoSource
 * @property {'youtube_video'} type - Event source type
 * @property {string} selector - CSS selector for YouTube iframes
 * @property {string[]} states - Array of YouTube video states
 *
 * @typedef {WeGoLinkClickSource | WeGoFormSubmitSource | WeGoPodiumWidgetSource | WeGoYouTubeVideoSource} WeGoEventSource
 *
 * @example
 * {
 *   "endpoint": "https://example.com/wp-json/wego/v1/track-event",
 *   "trackedEvents": [
 *     {
 *       "slug": "tel_clicks",
 *       "eventSource": {
 *         "type": "link_click",
 *         "selector": "a[href^='tel:']"
 *       }
 *     },
 *     {
 *       "slug": "podium_events",
 *       "eventSource": {
 *         "type": "podium_widget",
 *         "events": ["Bubble Clicked", "Conversation Started"]
 *       }
 *     }
 *   ]
 * }
 *
 * @remarks
 * - For link_click: Only elements with an href attribute will trigger events, href becomes primary_value
 * - For form_submit: Form ID or action URL becomes primary_value
 * - For podium_widget: PodiumEventsCallback is set up to handle Podium events
 * - Click events bubble, so selector matches against clicked element and ancestors
 * - Config is generated server-side via output_tracking_config() in PHP
 */

// Storage keys
const STORAGE_KEY_UTM = 'wego_utm';
const STORAGE_KEY_REFERRER = 'wego_referrer';

// Event source type constants
// IMPORTANT: These values must match the string values returned by PHP event
// source classes (get_type()).
const EVENT_SOURCE_TYPE_LINK_CLICK = 'link_click';
const EVENT_SOURCE_TYPE_FORM_SUBMIT = 'form_submit';
const EVENT_SOURCE_TYPE_PODIUM_WIDGET = 'podium_widget';
const EVENT_SOURCE_TYPE_YOUTUBE_VIDEO = 'youtube_video';

const FORM_FIELD_TARGET_VALUE = 'wego-traffic-source';
const SELECTOR_CONFIG_DATA_SCRIPT = 'script.wego-tracking-config';

// ========== Main Execution ==========

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
	if ( !rawConfig.trackedEvents || !Array.isArray( rawConfig.trackedEvents ) ) {
		return null;
	}
	if ( !rawConfig.endpoint || typeof rawConfig.endpoint !== 'string' ) {
		return null;
	}

	// Basic validation passed, return as typed config
	return /** @type {WeGoTrackingConfig} */ ( rawConfig );
}

/**
 * Set up handlers for the tracked events in the inline JSON config
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

	// Set up tracking for each tracked event based on its event source type
	for ( const trackedEvent of config.trackedEvents ) {
		if ( !trackedEvent.eventSource || !trackedEvent.slug ) {
			continue;
		}

		switch ( trackedEvent.eventSource.type ) {
			case EVENT_SOURCE_TYPE_LINK_CLICK:
				setupLinkClickTracking( trackedEvent, config.endpoint );
				break;

			case EVENT_SOURCE_TYPE_FORM_SUBMIT:
				setupFormSubmitTracking( trackedEvent, config.endpoint );
				break;

			case EVENT_SOURCE_TYPE_PODIUM_WIDGET:
				setupPodiumEventTracking( trackedEvent, config.endpoint );
				break;

			case EVENT_SOURCE_TYPE_YOUTUBE_VIDEO:
				setupYouTubeEventTracking( trackedEvent, config.endpoint );
				break;
		}
	}
}

// ========== Links ==========

/**
 * Set up click tracking for link-based events
*
* @param {WeGoTrackedEvent} trackedEvent - The tracked event configuration
* @param {string} endpoint - The REST API endpoint URL
*/
function setupLinkClickTracking( trackedEvent, endpoint ) {
	// Check if there are any links matching the selector
	const links = validateSelectorAll( trackedEvent.eventSource.selector, trackedEvent.slug );

	if ( !links.length ) {
		// No matching targets, nothing to track
		return;
	}

	document.addEventListener( 'click', ( e ) => {
		const target = validateClosest( e.target, trackedEvent.eventSource.selector, trackedEvent.slug );
		if ( !target || !target.hasAttribute( 'href' ) ) {
			return;
		}
		// href is the primary value for link click events
		sendEventBeacon( endpoint, trackedEvent.slug, target.getAttribute( 'href' ) );
	} );
}

// ========== Forms ==========

/**
 * Set up tracking for form submission events
 *
 * All AJAX submit support requires requires `jQuery.on()`; no native event support
 *
 * TODO: Haven't found a way to track non-ajax Gravity forms yet
 *
 * @param {WeGoTrackedEvent} trackedEvent - The form submit tracked event configuration
 * @param {string} endpoint - The REST API endpoint URL
 */
function setupFormSubmitTracking( trackedEvent, endpoint ) {
	const CF7_AJAX_EVENT = 'wpcf7mailsent';
	const FORMIDABLE_AJAX_CLASS = 'frm_ajax_submit';
	const FORMIDABLE_AJAX_EVENT = 'frmFormComplete';
	const GRAVITY_AJAX_EVENT = 'gform_confirmation_loaded';
	const NINJA_AJAX_EVENT = 'nfFormSubmitResponse';

	// Check if there are any forms matching the selector
	const forms = validateSelectorAll( trackedEvent.eventSource.selector, trackedEvent.slug );

	if ( !forms.length ) {
		// No matching targets, nothing to track or listen for
		return;
	}

	/**
	 * General form submit events that match the selector
	 */
	document.addEventListener( 'submit', ( e ) => {
		const form = validateClosest( e.target, trackedEvent.eventSource.selector, trackedEvent.slug );

		// Some AJAX submits will still fire form submit so we need to ignore those
		function shouldNotListen( formElement ) {
			return (
				formElement.classList.contains( 'wpcf7-form' )
				||  !!formElement.closest('.wpcf7')
				|| form.classList.contains( FORMIDABLE_AJAX_CLASS )
			);
		};

		if ( !form || shouldNotListen( form ) ) {
			return;
		}

		handleFormSubmit( form );
	} );

	/**
	 * Gravity Forms AJAX submit support
	 *
	 * Haven't figured out how to detect non AJAX submits yet (native form
	 * submit is thwarted) and only the form ID is available. Because form
	 * plugins hate you.
	 */
	jQuery( document ).on( GRAVITY_AJAX_EVENT, function( event, formId ) {
		// Form is gone by this point, this is duct tape until 2.4
		handleFormSubmit( null, `Gravity Form ID ${formId}` );
	} );

	/**
	 * Formidable Forms AJAX submit support
	 *
	 * Best labelling is to set the title, though it's only discoverable in a
	 * screen reader legend inside the form.  This is because form plugins hate
	 * you.
	 */
	jQuery( document ).on( FORMIDABLE_AJAX_EVENT, function( event, /** @type {HTMLFormElement} */ form ) {
		// Legend should be the form title, grab it and use it if it exists
		const legendText = form.querySelector( 'legend' )?.textContent?.trim() || '';
		handleFormSubmit( form, legendText );
	} );

	/**
	 * Ninja Forms AJAX submit support
	 */
	jQuery( document ).on( NINJA_AJAX_EVENT, function( event, response ) {
		const formTitle = String( response?.response?.data?.settings?.title || "" );
		const formId = response?.id;
		const formContainer = formId ? document.querySelector( `#nf-form-${formId}-cont` ) : null;
		const formElement = formContainer?.querySelector( 'form' ) || null;

		if ( !formElement ) {
			console.error( 'wego-traffic-source: Could not find form element for Ninja Forms AJAX submit.', {
				response,
				formId,
				formContainer
			} );
			return;
		}

		handleFormSubmit( formElement, formTitle );
	} );

	/**
	 * Contact Form 7 AJAX submit support
	 *
	 * Best labelling is to set the title
	 */
	document.addEventListener( CF7_AJAX_EVENT, function( event ) {
		handleFormSubmit( event.target );
	} );

	/**
	 * Handle form submission and send beacon
	 *
	 * @param {any} form - Form element from event
	 * @param {string} primaryValue Will override and be used as the primary value if set
	 */
	function handleFormSubmit( form, primaryValue = "" ) {
		primaryValue =
			primaryValue
			|| form?.title
			|| form?.ariaLabel
			|| form?.id
			|| form?.name
			|| form?.getAttribute?.( 'role' )
			|| form?.action
			|| 'Unknown form';
		sendEventBeacon( endpoint, trackedEvent.slug, primaryValue );
	}
}

// ========== Podium Widget Events ==========

/**
 * Set up tracking for Podium widget events
 *
 * @param {WeGoTrackedEvent} trackedEvent - The Podium tracked event configuration
 * @param {string} endpoint - The REST API endpoint URL
 */
function setupPodiumEventTracking( trackedEvent, endpoint ) {
	// Validate that we have events to track
	if ( !trackedEvent.eventSource.events || !Array.isArray( trackedEvent.eventSource.events ) ) {
		return;
	}

	/**
	 * Podium widget event callback
	 *
	 * Note: There's no real use case for more than a single Podium tracked
	 * event so we assume there should never be more than one and blindly
	 * overwrite any previous PodiumEventsCallback instance.
	 *
	 * @param {string} eventName - The Podium event name that triggered
	 * @param {any} properties - Additional event properties from Podium
	 */
	window.PodiumEventsCallback = function( eventName, properties ) {
		if ( trackedEvent.eventSource.events.includes( eventName ) ) {
			// The Podium event name is display-ready for the primary value
			sendEventBeacon( endpoint, trackedEvent.slug, eventName );
		}
	};
}

// ========== YouTube ==========

/**
 * Set up tracking for YouTube video events
 *
 * @param {WeGoTrackedEvent} trackedEvent - The YouTube tracked event configuration
 * @param {string} endpoint - The REST API endpoint URL
 */
function setupYouTubeEventTracking( trackedEvent, endpoint ) {
	// Validate that we have states to track
	if ( !trackedEvent.eventSource.states || !Array.isArray( trackedEvent.eventSource.states ) ) {
		return;
	}
	// YouTube player state constants
	const YT_STATE_ENDED = 0;
	const YT_STATE_PLAYING = 1;
	const YT_STATE_PAUSED = 2;
	const YT_STATE_BUFFERING = 3;

	// Map state numbers to display-ready state names (matching PHP canonical values)
	const stateMap = {
		[YT_STATE_PLAYING]: 'Playing',
		[YT_STATE_PAUSED]: 'Paused',
		[YT_STATE_ENDED]: 'Ended',
		[YT_STATE_BUFFERING]: 'Buffering',
	};

	// Look for iframes that match the selector
	const iframes = validateSelectorAll( trackedEvent.eventSource.selector, trackedEvent.slug );
	if ( !iframes.length ) {
		// No matching targets, nothing to track
		return;
	}

	// Start the loading process only if we have targets
	loadYouTubeAPI( iframes );

	/**
	 * Load YouTube IFrame API if not already loaded
	 */
	function loadYouTubeAPI( iframes ) {
		// Check if API is already loaded
		if ( typeof window.YT !== 'undefined' && typeof window.YT.Player === 'function' ) {
			initializePlayers( iframes ) ;
			return;
		}

		// Set up callback for when API loads
		window.onYouTubeIframeAPIReady = function() {
			initializePlayers( iframes );
		};

		// Inject YouTube IFrame API script
		const tag = document.createElement( 'script' );
		tag.src = 'https://www.youtube.com/iframe_api';
		const firstScriptTag = document.getElementsByTagName( 'script' )[0];
		firstScriptTag.parentNode.insertBefore( tag, firstScriptTag );
	}

	/**
	 * Initialize YouTube players after API is ready
	 */
	function initializePlayers( iframes ) {
		for ( const iframe of iframes ) {
			// Skip if already initialized
			if ( iframe.dataset.wegoYtInitialized ) {
				continue;
			}

			// Mark as initialized
			iframe.dataset.wegoYtInitialized = 'true';

			// Ensure iframe has enablejsapi=1 parameter
			if ( iframe.src && ! iframe.src.includes( 'enablejsapi=1' ) ) {
				const url = new URL( iframe.src );
				url.searchParams.set( 'enablejsapi', '1' );
				iframe.src = url.toString();
			}

			// Ensure iframe has an ID for the YT.Player API
			if ( ! iframe.id ) {
				iframe.id = 'wego-yt-' + Math.random().toString( 36 ).substring( 2, 11 );
			}

			// Create YT.Player instance
			new window.YT.Player( iframe.id, {
				events: {
					onStateChange: ( event ) => {
						// TODO: Probably should check the key exists for safety
						const stateKey = stateMap[ event.data ];

						// Only track if this state is configured
						if ( !stateKey || !trackedEvent.eventSource.states.includes( stateKey ) ) {
							return;
						}

						// Get video information
						const player = event.target;
						const videoData = player.getVideoData();
						const videoId = videoData.video_id || '';
						const videoTitle = videoData.title || 'Unknown Video';
						const videoUrl = videoId ? `https://www.youtube.com/watch?v=${videoId}` : '';
						const currentTime = typeof player.getCurrentTime() === 'number' ? player.getCurrentTime() : 0;

						// Build event_source_data object
						const eventSourceData = {
							video_id: videoId,
							video_title: videoTitle,
							video_url: videoUrl,
							state_change: stateKey,
							current_time: currentTime
						};

						// Primary value: video title, state change, and formatted current time
						const hours = Math.floor( currentTime / 3600 );
						const minutes = Math.floor( ( currentTime % 3600 ) / 60 );
						const seconds = Math.floor( currentTime % 60 );
						const timeStr = `${hours}:${minutes.toString().padStart( 2, '0' )}:${seconds.toString().padStart( 2, '0' )}`;
						const primaryValue = `${videoTitle}: ${stateKey} (${timeStr})`;

						sendEventBeacon( endpoint, trackedEvent.slug, primaryValue, eventSourceData );
					}
				}
			} );
		}
	}

}

// ========== Event Transmission ==========

/**
 * Send event data via sendBeacon
 *
 * @param {string} endpoint - The REST API endpoint URL
 * @param {string} eventSlug - The event type slug
 * @param {string} primaryValue - The primary value for the event
 * @param {Object|null} eventSourceData - Optional event source specific data
 */
function sendEventBeacon( endpoint, eventSlug, primaryValue, eventSourceData = null ) {
	const trafficSource = determineTrafficSource();
	const deviceType = getDeviceType();
	const pagePath = window.location.pathname + window.location.search;
	const browserFamily = getBrowserFamily();
	const osFamily = getOsFamily();

	// Payload uses snake_case for REST API parameters (not camelCase).
	// This is standard REST API convention, matching WordPress patterns.
	// Do not succumb to the urge to refactor these to camelCase
	const payload = {
		event_type: eventSlug,
		primary_value: primaryValue,
		traffic_source: trafficSource,
		device_type: deviceType,
		page_url: pagePath,
		browser_family: browserFamily,
		os_family: osFamily
	};

	if ( eventSourceData !== null ) {
		payload.event_source_data = eventSourceData;
	}

	// Use sendBeacon for reliable delivery even during page unload
	// https://developer.mozilla.org/en-US/docs/Web/API/Navigator/sendBeacon
	navigator.sendBeacon(
		endpoint,
		new Blob(
			[ JSON.stringify( payload ) ],
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
	const url = new URL( window.location.href );
	const utmParams = {};
	const utmTags = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];
	for ( const param of utmTags)  {
		const value = url.searchParams.get( param );
		if ( value ) {
			utmParams[param] = value;
		}
	}

	// Always save UTM data, even if empty
	sessionStorage.setItem( STORAGE_KEY_UTM, JSON.stringify( utmParams ) );

	// Check for external referrer
	let referrer = '';
	if ( document.referrer ) {
		// Don't log ourself as referrer
		try {
			const referrerUrl = new URL( document.referrer );
			const currentUrl = new URL( window.location.href );
			if ( referrerUrl.hostname !== currentUrl.hostname ) {
				referrer = document.referrer;
			}
		} catch {
			// If URL parsing fails, preserve the raw referrer so malformed
			// values can be inspected later
			referrer = document.referrer;
		}
	}

	// Always save referrer, even if empty. Trim to avoid whitespace-only values downstream.
	sessionStorage.setItem( STORAGE_KEY_REFERRER, referrer.trim() );
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
			return `Malformed Referral: ${preview}`;
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
		return `Referral from ${refUrl.hostname}`;
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

/**
 * Safely run querySelectorAll with error handling
 * @param {any} maybeSelector
 * @param {string} slug - Event slug for logging
 * @returns {Array<Element>} Array of matched elements, or [] if invalid selector
 */
function validateSelectorAll(maybeSelector, slug) {
	try {
		return Array.from(document.querySelectorAll(maybeSelector));
	} catch (e) {
		console.error(`wego-traffic-source: Invalid selector ${maybeSelector} for ${slug}`);
		return [];
	}
}

/**
 * Safely run Element.closest with error handling
 * @param {EventTarget|null} contextEl
 * @param {any} maybeSelector
 * @param {string} slug - Event slug for logging
 * @returns {Element|null} Closest matching element, or null if invalid selector
 */
function validateClosest(contextEl, maybeSelector, slug) {
	if ( ! ( contextEl instanceof Element ) ) {
		return null;
	}

	try {
		return contextEl.closest(maybeSelector);
	} catch (e) {
		console.error(`wego-traffic-source: Invalid selector \`${maybeSelector}\` for ${slug}`);
		return null;
	}
}
