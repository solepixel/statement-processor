/**
 * Statement Processor admin scripts.
 *
 * @package StatementProcessor
 */

( function ( $ ) {
	'use strict';

	$( function () {
		// Dismissible notices.
		$( '.statement-processor-admin .notice.is-dismissible' ).on( 'click', '.notice-dismiss', function () {
			$( this ).closest( '.notice' ).slideUp();
		} );

		// File drop zone: drag and drop support.
		( function () {
			var dropzone = document.getElementById( 'statement_processor_file_dropzone' );
			var fileInput = document.getElementById( 'statement_processor_files' );
			var fileCountEl = document.getElementById( 'statement_processor_file_count' );
			var acceptTypes = [ 'application/pdf', 'text/csv', 'text/plain' ];
			var acceptExt = [ 'pdf', 'csv' ];

			function isAccepted( file ) {
				var ext = ( file.name || '' ).split( '.' ).pop().toLowerCase();
				return acceptExt.indexOf( ext ) !== -1 || acceptTypes.indexOf( file.type ) !== -1;
			}

			function updateFileCount() {
				var count = fileInput.files.length;
				fileCountEl.textContent = count > 0 ? count + ' ' + ( count === 1 ? 'file' : 'files' ) + ' selected' : '';
			}

			if ( dropzone && fileInput ) {
				[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
					dropzone.addEventListener( ev, function ( e ) {
						e.preventDefault();
						e.stopPropagation();
						dropzone.classList.add( 'drag-over' );
					} );
				} );
				[ 'dragleave', 'drop' ].forEach( function ( ev ) {
					dropzone.addEventListener( ev, function ( e ) {
						e.preventDefault();
						e.stopPropagation();
						dropzone.classList.remove( 'drag-over' );
					} );
				} );
				dropzone.addEventListener( 'drop', function ( e ) {
					var files = e.dataTransfer.files;
					if ( ! files.length ) return;
					var accepted = [];
					for ( var i = 0; i < files.length; i++ ) {
						if ( isAccepted( files[ i ] ) ) accepted.push( files[ i ] );
					}
					if ( accepted.length === 0 ) return;
					var dt = new DataTransfer();
					accepted.forEach( function ( f ) { dt.items.add( f ); } );
					fileInput.files = dt.files;
					updateFileCount();
				} );
				fileInput.addEventListener( 'change', updateFileCount );
				updateFileCount();
			}
		} )();

		// Source dropdown: show "Add New" text input when "Add New..." is selected.
		$( '#statement_processor_source' ).on( 'change', function () {
			var isAddNew = $( this ).val() === 'add_new';
			$( '#statement_processor_source_new_wrap' ).toggle( isAddNew );
			$( '#statement_processor_source_new' ).prop( 'required', isAddNew );
			if ( ! isAddNew ) {
				$( '#statement_processor_source_new' ).val( '' );
			}
		} ).trigger( 'change' );

		// Upload form: validation and loading state (disable button + spinner while processing).
		$( '.statement-processor-upload-form' ).on( 'submit', function () {
			var $form = $( this );
			var $files = $( '#statement_processor_files' )[ 0 ];
			if ( $files && ! $files.files.length ) {
				alert( 'Please select at least one PDF or CSV file.' );
				return false;
			}
			var sourceVal = $( '#statement_processor_source' ).val();
			if ( ! sourceVal || sourceVal === '' ) {
				alert( 'Please select a source.' );
				return false;
			}
			if ( sourceVal === 'detect_auto' ) {
				// No further validation; sources can be set per row on review.
			} else if ( sourceVal === 'add_new' && ! $( '#statement_processor_source_new' ).val().trim() ) {
				alert( 'Please enter the new source name.' );
				return false;
			}

			var $btn = $form.find( '.statement-processor-upload-btn' );
			if ( $btn.length && ! $btn.prop( 'disabled' ) ) {
				$btn.prop( 'disabled', true ).addClass( 'is-busy' );
				$btn.data( 'original-html', $btn.html() );
				$btn.html( '<span class="statement-processor-spinner" aria-hidden="true"></span> ' + ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.processingText ? statementProcessorAdmin.processingText : 'Processing…' ) );
			}
		} );

		// Review table: Select all / deselect all.
		$( '#sp-select-all' ).on( 'change', function () {
			$( '#statement-processor-review-form' ).find( 'tbody input[name="include[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
		} );
		$( '#statement-processor-review-form' ).on( 'change', 'tbody input[name="include[]"]', function () {
			var total = $( '#statement-processor-review-form tbody input[name="include[]"]' ).length;
			var checked = $( '#statement-processor-review-form tbody input[name="include[]"]:checked' ).length;
			$( '#sp-select-all' ).prop( 'checked', total > 0 && total === checked );
		} );

		// Review table: inline edit on cell click (textarea for description, input for others).
		$( '#statement-processor-review-form' ).on( 'click', 'td.sp-editable', function () {
			var $cell = $( this );
			if ( $cell.find( 'input.sp-edit-input, textarea.sp-edit-input' ).length ) return;
			var idx = $cell.data( 'index' );
			var field = $cell.data( 'field' );
			var displayText = $cell.text().trim();
			var isTime = field === 'time';
			var isDesc = field === 'description';
			var inputVal = displayText;
			if ( isTime && ( displayText === '—' || displayText === '' ) ) inputVal = '';
			var $input;
			if ( isDesc ) {
				$input = $( '<textarea class="sp-edit-input regular-text" rows="3" />' ).val( inputVal );
			} else {
				$input = $( '<input type="text" class="sp-edit-input regular-text" />' ).val( inputVal );
			}
			$cell.empty().append( $input );
			$input.focus();
			$input.on( 'blur', function () {
				var val = isDesc ? $input.val().trim() : $input.val().trim();
				$cell.closest( 'tr' ).find( 'input[name="tx[' + idx + '][' + field + ']"]' ).val( val );
				$cell.empty().text( isTime && ! val ? '—' : val );
			} );
			$input.on( 'keydown', function ( e ) {
				if ( e.which === 13 && ! isDesc ) {
					e.preventDefault();
					$input.blur();
				}
				if ( e.which === 27 ) {
					$input.val( displayText );
					$input.blur();
				}
			} );
		} );

		// Review table: Source dropdown — show/hide "Add New" input.
		$( '#statement-processor-review-form' ).on( 'change', 'select.sp-source-select', function () {
			var $sel = $( this );
			var isAddNew = $sel.val() === 'add_new';
			$sel.siblings( '.sp-source-new-wrap' ).toggle( isAddNew );
		} );
	} );
}( jQuery ) );
