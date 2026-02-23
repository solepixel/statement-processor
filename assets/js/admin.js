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

		// Upload form: per-file upload + processing progress via AJAX.
		$( '.statement-processor-upload-form' ).on( 'submit', function ( e ) {
			e.preventDefault();
			var $form = $( this );
			var fileInput = document.getElementById( 'statement_processor_files' );
			if ( ! fileInput || ! fileInput.files.length ) {
				alert( 'Please select at least one PDF or CSV file.' );
				return false;
			}
			var sourceVal = $( '#statement_processor_source' ).val();
			if ( ! sourceVal || sourceVal === '' ) {
				alert( 'Please select a source.' );
				return false;
			}
			if ( sourceVal === 'add_new' && ! $( '#statement_processor_source_new' ).val().trim() ) {
				alert( 'Please enter the new source name.' );
				return false;
			}

			var files = Array.prototype.slice.call( fileInput.files );
			var $progressWrap = $( '#statement_processor_upload_progress' );
			var $list = $( '#statement_processor_file_progress_list' );
			var $btn = $form.find( '.statement-processor-upload-btn' );
			var labels = ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.uploadLabels )
				? statementProcessorAdmin.uploadLabels
				: { pending: 'Pending', uploading: 'Uploading…', processing: 'Processing…', done: 'Done', error: 'Error' };
			if ( ! labels.pending ) {
				labels.pending = 'Pending';
			}

			$list.empty();
			files.forEach( function ( file, idx ) {
				var $li = $( '<li class="statement-processor-file-progress-item" data-index="' + idx + '">' );
				$li.append( '<span class="sp-file-name" title="' + ( file.name || '' ).replace( /"/g, '&quot;' ) + '">' + ( file.name || 'File ' + ( idx + 1 ) ) + '</span>' );
				$li.append( '<div class="sp-file-status">' + labels.pending + '</div>' );
				$li.append( '<div class="sp-progress-bar-wrap"><div class="sp-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div></div>' );
				$list.append( $li );
			} );
			$progressWrap.show();
			$btn.prop( 'disabled', true ).addClass( 'is-busy' );

			var sessionId = ( Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 ) ).replace( /[^a-z0-9]/gi, '' ).slice( 0, 32 );
			var totalFiles = files.length;
			var currentIndex = 0;

			function setStatus( index, statusText, isError ) {
				var $item = $list.find( '.statement-processor-file-progress-item[data-index="' + index + '"]' );
				$item.find( '.sp-file-status' ).text( statusText ).toggleClass( 'sp-error', !! isError );
			}

			function setUploadProgress( index, percent ) {
				var $item = $list.find( '.statement-processor-file-progress-item[data-index="' + index + '"]' );
				$item.find( '.sp-progress-bar' ).css( 'width', Math.min( 100, Math.max( 0, percent ) ) + '%' ).attr( 'aria-valuenow', Math.round( percent ) );
			}

			function setProcessing( index ) {
				var $item = $list.find( '.statement-processor-file-progress-item[data-index="' + index + '"]' );
				$item.find( '.sp-progress-bar-wrap' ).addClass( 'is-processing' );
				$item.find( '.sp-progress-bar' ).css( 'width', '100%' ).attr( 'aria-valuenow', 100 );
				$item.find( '.sp-file-status' ).text( labels.processing );
			}

			function clearProcessing( index ) {
				var $item = $list.find( '.statement-processor-file-progress-item[data-index="' + index + '"]' );
				$item.find( '.sp-progress-bar-wrap' ).removeClass( 'is-processing' );
			}

			function uploadNext() {
				if ( currentIndex >= totalFiles ) {
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
					return;
				}
				var file = files[ currentIndex ];
				var formData = new FormData();
				formData.append( 'action', 'statement_processor_upload_one_file' );
				formData.append( 'statement_processor_upload_nonce', statementProcessorAdmin.nonce );
				formData.append( 'session_id', sessionId );
				formData.append( 'file_index', currentIndex );
				formData.append( 'total_files', totalFiles );
				formData.append( 'statement_processor_source', sourceVal );
				if ( sourceVal === 'add_new' ) {
					formData.append( 'statement_processor_source_new', $( '#statement_processor_source_new' ).val().trim() );
				}
				formData.append( 'statement_processor_file', file );

				setStatus( currentIndex, labels.uploading, false );
				setUploadProgress( currentIndex, 0 );
				clearProcessing( currentIndex );

				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', statementProcessorAdmin.ajaxUrl );

				xhr.upload.addEventListener( 'progress', function ( ev ) {
					if ( ev.lengthComputable && ev.total > 0 ) {
						var pct = ( ev.loaded / ev.total ) * 100;
						setUploadProgress( currentIndex, pct );
						if ( pct >= 100 ) {
							setProcessing( currentIndex );
						}
					}
				} );

				xhr.addEventListener( 'load', function () {
					clearProcessing( currentIndex );
					var res;
					try {
						res = JSON.parse( xhr.responseText );
					} catch ( err ) {
						setStatus( currentIndex, labels.error + ': ' + ( xhr.statusText || 'Invalid response' ), true );
						clearProcessing( currentIndex );
						$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
						return;
					}
					if ( res.success && res.data ) {
						clearProcessing( currentIndex );
						setStatus( currentIndex, labels.done, false );
						setUploadProgress( currentIndex, 100 );
						if ( res.data.redirectUrl ) {
							window.location.href = res.data.redirectUrl;
							return;
						}
						currentIndex += 1;
						uploadNext();
					} else {
						clearProcessing( currentIndex );
						setStatus( currentIndex, labels.error + ( res.data && res.data.message ? ': ' + res.data.message : '' ), true );
						$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
					}
				} );

				xhr.addEventListener( 'error', function () {
					clearProcessing( currentIndex );
					setStatus( currentIndex, labels.error + ': ' + ( xhr.statusText || 'Network error' ), true );
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
				} );

				xhr.send( formData );
			}

			uploadNext();
		} );

		// Review form: Import selected via AJAX (batch) with spinner and progress.
		$( '#statement-processor-review-form' ).on( 'submit', function ( e ) {
			e.preventDefault();
			var $form = $( this );
			var $btn = $( '#statement_processor_import_btn' );
			var $progress = $( '#statement_processor_import_progress' );
			var checked = $form.find( 'tbody input[name="include[]"]:checked' );
			if ( ! checked.length ) {
				alert( 'Please select at least one transaction to import.' );
				return false;
			}
			var nonce = $form.find( 'input[name="statement_processor_import_nonce"]' ).val();
			var reviewKey = $form.find( 'input[name="statement_processor_review_key"]' ).val();
			if ( ! nonce || ! reviewKey ) {
				alert( 'Session expired. Please refresh the page and upload your files again.' );
				return false;
			}
			var ajaxUrl = $form.data( 'ajaxUrl' ) || ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.ajaxUrl ? statementProcessorAdmin.ajaxUrl : '' );
			var importBatchUrl = ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.importBatchRestUrl )
				? statementProcessorAdmin.importBatchRestUrl
				: ( ajaxUrl ? ajaxUrl + ( ajaxUrl.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'action=statement_processor_import_batch' : '' );
			if ( ! importBatchUrl ) {
				alert( 'Import is not available. Please refresh the page.' );
				return false;
			}
			// Flush any active inline edits into hidden inputs so edits are included (blur may not have fired).
			$form.find( '.sp-edit-input' ).each( function () {
				var $input = $( this );
				var $cell = $input.closest( 'td.sp-editable' );
				if ( $cell.length ) {
					var idx = $cell.data( 'index' );
					var field = $cell.data( 'field' );
					var val = $input.val();
					if ( field === 'description' ) {
						val = val.trim();
					} else {
						val = val.trim();
						if ( field === 'time' && ! val ) { val = ''; }
					}
					$form.find( 'input[name="tx[' + idx + '][' + field + ']"]' ).val( val );
				}
			} );
			var totalToImport = checked.length;
			var batchSize = 50;
			var indices = checked.map( function () { return parseInt( $( this ).val(), 10 ); } ).get();
			var importLabel = ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.importLabel ) ? statementProcessorAdmin.importLabel : 'Importing…';
			var progressLabel = ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.importProgressLabel ) ? statementProcessorAdmin.importProgressLabel : 'Importing… %s / %s';
			function buildFormDataForBatch( batchIndices ) {
				var txBatch = {};
				batchIndices.indices.forEach( function ( idx ) {
					txBatch[ idx ] = {
						date: $form.find( 'input[name="tx[' + idx + '][date]"]' ).val() || '',
						time: $form.find( 'input[name="tx[' + idx + '][time]"]' ).val() || '',
						description: $form.find( 'input[name="tx[' + idx + '][description]"]' ).val() || '',
						amount: $form.find( 'input[name="tx[' + idx + '][amount]"]' ).val() || '',
						origination: $form.find( 'input[name="tx[' + idx + '][origination]"]' ).val() || '',
						origination_stored_name: $form.find( 'input[name="tx[' + idx + '][origination_stored_name]"]' ).val() || '',
						source_term_id: $form.find( 'select[name="tx[' + idx + '][source_term_id]"]' ).val() || '',
						source_new: $form.find( 'input[name="tx[' + idx + '][source_new]"]' ).val() || ''
					};
				} );
				var fd = new FormData();
				fd.append( 'action', 'statement_processor_import_batch' );
				fd.append( 'statement_processor_import_nonce', nonce );
				fd.append( 'statement_processor_review_key', reviewKey );
				fd.append( 'batch_number', batchIndices.batchIndex );
				fd.append( 'total_batches', batchIndices.totalBatches );
				fd.append( 'tx_batch', JSON.stringify( txBatch ) );
				batchIndices.indices.forEach( function ( idx ) {
					fd.append( 'include[]', idx );
				} );
				return fd;
			}
			var batches = [];
			for ( var b = 0; b < indices.length; b += batchSize ) {
				batches.push( { indices: indices.slice( b, b + batchSize ), batchIndex: batches.length, totalBatches: 0 } );
			}
			var i;
			for ( i = 0; i < batches.length; i++ ) {
				batches[ i ].batchIndex = i;
				batches[ i ].totalBatches = batches.length;
			}
			$btn.prop( 'disabled', true ).addClass( 'is-busy' ).data( 'original-html', $btn.html() );
			$btn.html( '<span class="statement-processor-spinner" aria-hidden="true"></span> ' + importLabel );
			$progress.show().text( progressLabel.replace( '%s', '0' ).replace( '%s', String( totalToImport ) ) );
			var cumulativeImported = 0;
			var batchIndex = 0;
			function sendNextBatch() {
				if ( batchIndex >= batches.length ) {
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' ).html( $btn.data( 'original-html' ) );
					$progress.hide();
					return;
				}
				var batch = batches[ batchIndex ];
				var formData = buildFormDataForBatch( batch );
				var ajaxHeaders = { 'X-Requested-With': 'XMLHttpRequest' };
				if ( typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.restNonce ) {
					ajaxHeaders[ 'X-WP-Nonce' ] = statementProcessorAdmin.restNonce;
				}
				$.ajax( {
					url: importBatchUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					headers: ajaxHeaders
				} ).done( function ( res ) {
					if ( res.success && res.data ) {
						cumulativeImported += ( res.data.imported || 0 ) + ( res.data.skipped || 0 );
						$progress.text( progressLabel.replace( '%s', String( cumulativeImported ) ).replace( '%s', String( totalToImport ) ) );
						if ( res.data.done && res.data.redirect_url ) {
							window.location.href = res.data.redirect_url;
							return;
						}
						batchIndex += 1;
						sendNextBatch();
					} else {
						$progress.text( res.data && res.data.message ? res.data.message : 'Import failed.' ).css( 'color', '#d63638' );
						$btn.prop( 'disabled', false ).removeClass( 'is-busy' ).html( $btn.data( 'original-html' ) );
					}
				} ).fail( function ( xhr, textStatus, errorThrown ) {
					var msg = 'Import failed.';
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						msg = xhr.responseJSON.data.message;
					} else if ( textStatus === 'parsererror' && xhr.responseText ) {
						msg = 'Server returned an invalid response. Please try again or refresh the page.';
					} else if ( xhr.statusText ) {
						msg = xhr.statusText;
					}
					if ( window.console && window.console.group ) {
						window.console.group( 'Statement Processor import: debug' );
						window.console.log( 'Request URL:', importBatchUrl );
						window.console.log( 'Response status:', xhr.status, xhr.statusText );
						window.console.log( 'Response URL (after redirects):', xhr.responseURL || '(same as request)' );
						window.console.log( 'textStatus:', textStatus, 'errorThrown:', errorThrown );
						window.console.log( 'Response length:', ( xhr.responseText && xhr.responseText.length ) || 0 );
						if ( xhr.responseText ) {
							window.console.log( 'Response preview (first 500 chars):', xhr.responseText.substring( 0, 500 ) );
						}
						window.console.groupEnd();
					}
					$progress.text( msg ).css( 'color', '#d63638' );
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' ).html( $btn.data( 'original-html' ) );
				} );
			}
			batchIndex = 0;
			sendNextBatch();
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

		// Transaction edit: Suggest category button (only when localized data exists).
		var $suggestBtn = $( '#sp_suggest_category_btn' );
		if ( $suggestBtn.length && typeof statementProcessorAdmin !== 'undefined' && statementProcessorAdmin.suggestCategoryNonce ) {
			var $status = $( '#sp_suggest_category_status' );
			$suggestBtn.on( 'click', function () {
				var description = $( '#sp_meta_description' ).val();
				if ( ! description || ! description.trim() ) {
					$status.text( '' ).css( 'color', '' );
					return;
				}
				$suggestBtn.prop( 'disabled', true );
				$status.text( '' ).css( 'color', '' );
				$.post(
					statementProcessorAdmin.ajaxUrl,
					{
						action: 'sp_suggest_category',
						nonce: statementProcessorAdmin.suggestCategoryNonce,
						description: description.trim()
					}
				).done( function ( res ) {
					if ( res.success && res.data && res.data.term_id ) {
						var termId = parseInt( res.data.term_id, 10 );
						var $cb = $( 'input[name="tax_input[sp-category][]"][value="' + termId + '"]' );
						if ( $cb.length ) {
							$cb.prop( 'checked', true );
							$( '#sp_category_was_suggested' ).val( '1' );
							$status.text( res.data.message || 'Category suggested.' ).css( 'color', 'green' );
						} else {
							$status.text( res.data.message || 'Category suggested.' ).css( 'color', 'green' );
							$( '#sp_category_was_suggested' ).val( '1' );
						}
					} else {
						$status.text( res.data && res.data.message ? res.data.message : 'No category suggested.' ).css( 'color', '#666' );
					}
				} ).fail( function () {
					$status.text( 'Request failed.' ).css( 'color', 'red' );
				} ).always( function () {
					$suggestBtn.prop( 'disabled', false );
				} );
			} );
		}
	} );
}( jQuery ) );
