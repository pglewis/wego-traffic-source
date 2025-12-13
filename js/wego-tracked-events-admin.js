/**
 * State
 */
let rowIndex = 0;
let originalFormData = null;
let isDirty = false;

/**
 * DOM References
 */
const dom = {
	tableBody: document.getElementById( 'wego-tracked-events-body' ),
	addButton: document.getElementById( 'wego-add-tracked-event' ),
	template: document.getElementById( 'wego-tracked-event-row-template' ),
	form: document.querySelector( 'form' ),
	eventSourceTemplates: {}
};

/**
 * Get the admin configuration from the JSON script element
 */
function getAdminConfig() {
	const configElement = document.querySelector( 'script.wego-admin-config' );
	if ( ! configElement ) {
		console.error( 'WeGo admin config not found' );
		return null;
	}
	return JSON.parse( configElement.textContent );
}

/**
 * Validation handler mapping
 */
const validationHandlers = {
	css_selector: ( row ) => {
		const selectorField = row.querySelector( 'textarea[name*="[event_source_selector]"]' );
		const selectorValue = selectorField ? selectorField.value.trim() : '';
		return {
			validation: validateCSSSelector( selectorValue ),
			field: selectorField
		};
	},
	form_submit: ( row ) => {
		const selectorField = row.querySelector( 'textarea[name*="[event_source_selector]"]' );
		const selectorValue = selectorField ? selectorField.value.trim() : '';
		return {
			validation: validateCSSSelector( selectorValue ),
			field: selectorField
		};
	},
	podium_events: ( row ) => {
		const validation = validatePodiumEvents( row );
		const checkboxContainer = row.querySelector( '.wego-podium-checkboxes' );
		return {
			validation: validation,
			field: checkboxContainer
		};
	}
};

/**
 * Build event source configuration from admin config
 */
function buildEventSourceConfig() {
	const config = getAdminConfig();
	if ( ! config || ! config.eventSourceTypes ) {
		console.error( 'Invalid admin config' );
		return {};
	}

	const eventSourceConfig = {};

	for ( const [ type, meta ] of Object.entries( config.eventSourceTypes ) ) {
		const handler = validationHandlers[ meta.validation_type ];
		if ( handler ) {
			eventSourceConfig[ type ] = { validate: handler };
		}
	}

	return eventSourceConfig;
}

/**
 * Event Source Configuration
 */
const eventSourceConfig = buildEventSourceConfig();

// ========== Core Setup & Execution ==========

init();

/**
 * Initialization
 */

function init() {
	rowIndex = parseInt( dom.tableBody.dataset.rowIndex, 10 ) || 0;

	cacheEventSourceTemplates();
	captureFormState();

	// Note: Don't call initializeAllRows() here - server-rendered rows already
	// have correct content. Only swap templates when user changes dropdown.

	window.addEventListener( 'beforeunload', handleBeforeUnload );
	dom.form.addEventListener( 'submit', handleFormSubmit );
	dom.form.addEventListener( 'input', updateDirtyFlag );
	dom.form.addEventListener( 'change', updateDirtyFlag );
	dom.tableBody.addEventListener( 'click', handleTableClick );
	dom.tableBody.addEventListener( 'change', handleTableChange );
	dom.tableBody.addEventListener( 'input', handleTableInput );
	dom.addButton.addEventListener( 'click', handleAddButtonClick );
}

function cacheEventSourceTemplates() {
	// Cache all available event source templates from DOM
	// This ensures templates are available regardless of active tracked events
	const templates = document.querySelectorAll( 'template[id^="wego-event-source-"]' );

	for ( const template of templates ) {
		// Extract type from ID: "wego-event-source-link_click" → "link_click"
		const eventSourceType = template.id.replace( 'wego-event-source-', '' );
		dom.eventSourceTemplates[ eventSourceType ] = template;
	}
}

function captureFormState() {
	originalFormData = new FormData( dom.form );
}

function initializeAllRows() {
	const rows = dom.tableBody.querySelectorAll( 'tr:not(.wego-no-items)' );
	for ( const row of rows ) {
		updateConfigFields( row );
	}
}

/**
 * Validation
 */

/**
 * Validate CSS selector syntax
 */
function validateCSSSelector( selector ) {
	if ( ! selector || selector.trim() === '' ) {
		return { valid: false, error: 'Selector cannot be empty' };
	}

	const trimmedSelector = selector.trim();

	if ( trimmedSelector === 'a' ) {
		return { valid: false, error: 'Selector cannot be just "a". Please provide a more specific selector.' };
	}

	try {
		const selectors = trimmedSelector.split( ',' ).map( s => s.trim() ).filter( s => s );

		for ( const singleSelector of selectors ) {
			document.querySelectorAll( singleSelector );
		}

		return { valid: true };
	} catch ( error ) {
		return {
			valid: false,
			error: 'Invalid CSS selector'
		};
	}
}

/**
 * Validate Podium event checkboxes
 */
function validatePodiumEvents( row ) {
	const checkboxes = row.querySelectorAll( 'input[name*="[event_source_events]"]:checked' );
	if ( checkboxes.length === 0 ) {
		return { valid: false, error: 'Please select at least one Podium event to track' };
	}
	return { valid: true };
}

/**
 * Form State Tracking
 */

function checkFormDirty() {
	const currentFormData = new FormData( dom.form );
	const current = Array.from( currentFormData.entries() ).sort();
	const original = Array.from( originalFormData.entries() ).sort();

	if ( current.length !== original.length ) {
		return true;
	}

	for ( let i = 0; i < current.length; i++ ) {
		if ( current[i][0] !== original[i][0] || current[i][1] !== original[i][1] ) {
			return true;
		}
	}

	return false;
}

function updateDirtyFlag() {
	isDirty = checkFormDirty();
}

/**
 * Row Management
 */

function updateConfigFields( row ) {
	const typeSelect = row.querySelector( '.wego-event-source-type' );
	const configContainer = row.querySelector( '.wego-config-container' );

	if ( ! typeSelect || ! configContainer ) {
		return;
	}

	const selectedType = typeSelect.value;
	const rowIndexAttr = row.dataset.rowIndex;
	const templateElement = dom.eventSourceTemplates[ selectedType ];

	// Replace entire config container to prevent DOM bloat from multiple event source types
	if ( templateElement ) {
		// Clear existing content
		configContainer.innerHTML = '';

		// Clone the template content
		const clonedContent = templateElement.content.cloneNode( true );

		// Replace placeholders in text nodes and attributes
		function replacePlaceholders( element ) {
			if ( element.nodeType === Node.TEXT_NODE ) {
				element.textContent = element.textContent.replace( /\{\{INDEX\}\}/g, rowIndexAttr );
			} else if ( element.nodeType === Node.ELEMENT_NODE ) {
				// Replace in attributes
				for ( const attr of element.attributes ) {
					attr.value = attr.value.replace( /\{\{INDEX\}\}/g, rowIndexAttr );
				}
				// Recursively process child nodes
				for ( const child of element.childNodes ) {
					replacePlaceholders( child );
				}
			}
		}

		for ( const child of clonedContent.childNodes ) {
			replacePlaceholders( child );
		}

		// Append the cloned content
		configContainer.appendChild( clonedContent );
	}
}

function addRow() {
	const noItems = dom.tableBody.querySelector( '.wego-no-items' );
	if ( noItems ) {
		noItems.remove();
	}

	// Clone the template content and replace placeholders
	const clonedContent = dom.template.content.cloneNode( true );

	// Replace placeholders in text nodes and attributes
	function replacePlaceholders( element ) {
		if ( element.nodeType === Node.TEXT_NODE ) {
			element.textContent = element.textContent.replace( /\{\{INDEX\}\}/g, rowIndex );
		} else if ( element.nodeType === Node.ELEMENT_NODE ) {
			// Replace in attributes
			for ( const attr of element.attributes ) {
				attr.value = attr.value.replace( /\{\{INDEX\}\}/g, rowIndex );
			}
			// Recursively process child nodes
			for ( const child of element.childNodes ) {
				replacePlaceholders( child );
			}
		}
	}

	for ( const child of clonedContent.childNodes ) {
		replacePlaceholders( child );
	}

	// Append the cloned content
	dom.tableBody.appendChild( clonedContent );

	const rows = dom.tableBody.querySelectorAll( 'tr:not(.wego-no-items)' );
	const newRowElement = rows[ rows.length - 1 ];

	// Initialize config fields after row is in DOM (needs data-row-index attribute)
	updateConfigFields( newRowElement );

	rowIndex++;
	updateDirtyFlag();
}

function deleteRow( row ) {
	row.remove();
	updateDirtyFlag();
}

/**
 * Event Handlers
 */

function handleBeforeUnload( e ) {
	if ( isDirty ) {
		e.preventDefault();
		e.returnValue = '';
		return '';
	}
}

function handleFormSubmit( e ) {
	const rows = dom.tableBody.querySelectorAll( 'tr:not(.wego-no-items)' );
	let hasError = false;
	let firstErrorField = null;
	const errorMessages = [];

	for ( const row of rows ) {
		const nameField = row.querySelector( 'input[name*="[name]"]' );
		const typeSelect = row.querySelector( '.wego-event-source-type' );

		if ( ! nameField || ! nameField.value.trim() ) {
			continue;
		}

		const eventName = nameField.value.trim();
		const eventType = typeSelect ? typeSelect.value : 'link_click';
		const config = eventSourceConfig[ eventType ];

		if ( config && config.validate ) {
			const result = config.validate( row );
			const validation = result.validation;
			const field = result.field;

			if ( ! validation.valid ) {
				hasError = true;
				if ( field ) {
					field.classList.add( 'form-invalid' );
				}
				errorMessages.push( `"${eventName}": ${validation.error}` );

				if ( ! firstErrorField ) {
					firstErrorField = field;
				}
			} else {
				if ( field ) {
					field.classList.remove( 'form-invalid' );
				}
			}
		}
	}

	if ( hasError ) {
		e.preventDefault();

		if ( firstErrorField ) {
			firstErrorField.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		const errorText = 'Please fix the following errors:\n\n' + errorMessages.join( '\n' );
		alert( errorText );
		return false;
	}

	isDirty = false;
}

function handleAddButtonClick() {
	addRow();
}

function handleTableClick( e ) {
	if ( e.target.classList.contains( 'wego-delete-row' ) ) {
		const row = e.target.closest( 'tr' );
		deleteRow( row );
	}
}

function handleTableChange( e ) {
	if ( e.target.classList.contains( 'wego-event-source-type' ) ) {
		const row = e.target.closest( 'tr' );
		updateConfigFields( row );
		updateDirtyFlag();
	}

	if ( e.target.classList.contains( 'wego-event-slug' ) ) {
		e.target.dataset.manual = 'true';
	}
}

function handleTableInput( e ) {
	if ( e.target.classList.contains( 'wego-event-name' ) ) {
		const row = e.target.closest( 'tr' );
		const slugField = row.querySelector( '.wego-event-slug' );
		const maxLength = slugField.getAttribute( 'maxlength' ) || 15;

		if ( ! slugField.dataset.manual ) {
			slugField.value = e.target.value
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '_' )
				.replace( /^_|_$/g, '' )
				.substring( 0, maxLength );
		}
	}
}
