/**
 * WeGo Event Types Admin - Settings page functionality
 */

const tableBody = document.getElementById( 'wego-event-types-body' );
const addButton = document.getElementById( 'wego-add-event-type' );
const template = document.getElementById( 'wego-event-type-row-template' );
const form = document.querySelector( 'form' );

let rowIndex = parseInt( tableBody.dataset.rowIndex, 10 ) || 0;

// Unsaved changes detection
let originalFormData = null;
let isDirty = false;

// Capture original form state after page load
function captureFormState() {
	originalFormData = new FormData( form );
}

// Check if form has changed
function checkFormDirty() {
	const currentFormData = new FormData( form );
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

// Update dirty flag on form changes
function updateDirtyFlag() {
	isDirty = checkFormDirty();
}

// Warn before leaving page with unsaved changes
window.addEventListener( 'beforeunload', function( e ) {
	if ( isDirty ) {
		e.preventDefault();
		e.returnValue = ''; // Chrome requires returnValue to be set
		return ''; // Some browsers show this message
	}
});

/**
 * Validate CSS selector syntax using try/catch with document.querySelectorAll
 */
function validateCSSSelector( selector ) {
	if ( ! selector || selector.trim() === '' ) {
		return { valid: false, error: 'Selector cannot be empty' };
	}

	const trimmedSelector = selector.trim();

	// Basic validation: can't be just 'a'
	if ( trimmedSelector === 'a' ) {
		return { valid: false, error: 'Selector cannot be just "a". Please provide a more specific selector.' };
	}

	try {
		// Test each selector individually if multiple selectors are comma-separated
		const selectors = trimmedSelector.split( ',' ).map( s => s.trim() ).filter( s => s );

		for ( const singleSelector of selectors ) {
			// This will throw if the selector is invalid
			document.querySelectorAll( singleSelector );
		}

		return { valid: true };
	} catch ( error ) {
		return {
			valid: false,
			error: `Invalid CSS selector`
		};
	}
}

// Validate form before submission
form.addEventListener( 'submit', function( e ) {
	// Check all CSS selector fields
	const rows = tableBody.querySelectorAll( 'tr:not(.wego-no-items)' );
	let hasError = false;
	let firstErrorField = null;
	let errorMessages = [];

	for ( const row of rows ) {
		const nameField = row.querySelector( 'input[name*="[name]"]' );
		const selectorField = row.querySelector( 'textarea[name*="[css_selectors]"]' );

		// Skip validation if row is empty (will be filtered out server-side)
		if ( ! nameField || ! nameField.value.trim() ) {
			continue;
		}

		// Validate CSS selector
		const selectorValue = selectorField ? selectorField.value.trim() : '';
		const validation = validateCSSSelector( selectorValue );
		const eventName = nameField.value.trim();

		if ( ! validation.valid ) {
			hasError = true;
			selectorField.classList.add( 'form-invalid' );

			// Collect specific error message for this event type
			errorMessages.push( `"${eventName}": ${validation.error}` );

			// Store reference to first error for scrolling
			if ( ! firstErrorField ) {
				firstErrorField = selectorField;
			}
		} else {
			selectorField.classList.remove( 'form-invalid' );
		}
	}

	if ( hasError ) {
		e.preventDefault();

		// Scroll to first error field
		if ( firstErrorField ) {
			firstErrorField.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		// Show specific error messages
		const errorText = 'Please fix the following CSS selector errors:\n\n' + errorMessages.join( '\n' );
		alert( errorText );
		return false;
	}

	isDirty = false;
});

// Initialize form state tracking
captureFormState();

// Track changes on all form inputs
form.addEventListener( 'input', updateDirtyFlag );
form.addEventListener( 'change', updateDirtyFlag );

// Add new event type row
addButton.addEventListener( 'click', function() {
	const newRow = template.innerHTML.replace( /\{\{INDEX\}\}/g, rowIndex );

	// Remove "no items" row if present
	const noItems = tableBody.querySelector( '.wego-no-items' );
	if ( noItems ) {
		noItems.remove();
	}

	tableBody.insertAdjacentHTML( 'beforeend', newRow );
	rowIndex++;
	updateDirtyFlag();
});

// Handle delete button clicks (event delegation)
tableBody.addEventListener( 'click', function( e ) {
	if ( e.target.classList.contains( 'wego-delete-row' ) ) {
		e.target.closest( 'tr' ).remove();
		updateDirtyFlag();
	}
});

// Auto-generate slug from name (event delegation)
tableBody.addEventListener( 'input', function( e ) {
	if ( e.target.classList.contains( 'wego-event-name' ) ) {
		const row = e.target.closest( 'tr' );
		const slugField = row.querySelector( '.wego-event-slug' );
		const maxLength = slugField.getAttribute( 'maxlength' ) || 15;

		// Only auto-generate if slug hasn't been manually edited
		if ( ! slugField.dataset.manual ) {
			slugField.value = e.target.value
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '_' )
				.replace( /^_|_$/g, '' )
				.substring( 0, maxLength );
		}
	}
});

// Mark slug as manually edited
tableBody.addEventListener( 'change', function( e ) {
	if ( e.target.classList.contains( 'wego-event-slug' ) ) {
		e.target.dataset.manual = 'true';
	}
});
