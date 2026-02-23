<?php
/**
 * Statement Processor admin page template.
 *
 * @package StatementProcessor
 * @var string   $export_nonce
 * @var \WP_Term[] $sources
 * @var bool    $show_review
 * @var array|null $review_data  ['transactions' => array, 'source_name' => string, 'source_term_id' => int]
 * @var string  $review_key
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = get_transient( 'statement_processor_notice' );
if ( $notice ) {
	delete_transient( 'statement_processor_notice' );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
}
?>

<div class="wrap statement-processor-admin">
	<h1><?php esc_html_e( 'Import statements', 'statement-processor' ); ?></h1>

	<?php if ( $show_review && $review_data && ! empty( $review_data['transactions'] ) ) : ?>
		<div class="statement-processor-section statement-processor-review">
			<h2><?php esc_html_e( 'Review transactions', 'statement-processor' ); ?></h2>
			<p class="description">
				<?php
				if ( ! empty( $review_data['source_term_id'] ) ) {
					printf(
						/* translators: 1: source name */
						esc_html__( 'Source: %1$s. Uncheck any row to exclude it from the import. Then click "Import selected".', 'statement-processor' ),
						esc_html( $review_data['source_name'] )
					);
				} else {
					esc_html_e( 'Set the source for each row below (or change it). Uncheck any row to exclude it from the import. Then click "Import selected".', 'statement-processor' );
				}
				?>
			</p>

			<form method="post" action="" id="statement-processor-review-form" class="statement-processor-review-form">
				<?php wp_nonce_field( 'statement_processor_import_selected', 'statement_processor_import_nonce' ); ?>
				<input type="hidden" name="statement_processor_review_key" value="<?php echo esc_attr( $review_key ); ?>" />

				<table class="wp-list-table widefat fixed striped statement-processor-review-table">
					<thead>
						<tr>
							<td class="check-column">
								<label class="screen-reader-text" for="sp-select-all"><?php esc_html_e( 'Select all', 'statement-processor' ); ?></label>
								<input type="checkbox" id="sp-select-all" aria-label="<?php esc_attr_e( 'Select all', 'statement-processor' ); ?>" checked />
							</td>
							<th scope="col"><?php esc_html_e( 'Date', 'statement-processor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Time', 'statement-processor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'statement-processor' ); ?></th>
							<th scope="col" class="column-amount"><?php esc_html_e( 'Amount', 'statement-processor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'statement-processor' ); ?></th>
							<th scope="col" class="column-file" title="<?php esc_attr_e( 'File type', 'statement-processor' ); ?>"><?php esc_html_e( 'File', 'statement-processor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$current_sources = isset( $sources ) ? $sources : [];
						foreach ( $review_data['transactions'] as $index => $t ) :
							$date_val   = isset( $t['date'] ) ? $t['date'] : '';
							$time_val   = isset( $t['time'] ) && $t['time'] !== '00:00:00' ? $t['time'] : '';
							$desc_val   = isset( $t['description'] ) ? $t['description'] : '';
							$amt_val    = isset( $t['amount'] ) ? $t['amount'] : '';
							$orig_val   = isset( $t['origination'] ) ? $t['origination'] : '';
							$source_id   = isset( $t['source_term_id'] ) ? (int) $t['source_term_id'] : ( isset( $review_data['source_term_id'] ) ? (int) $review_data['source_term_id'] : 0 );
							$source_name = isset( $t['source_name'] ) ? $t['source_name'] : '';
							$detect_auto = ( isset( $review_data['source_term_id'] ) ? (int) $review_data['source_term_id'] : 0 ) === 0;
							$match_src   = null;
							if ( $source_name !== '' && ! empty( $current_sources ) ) {
								foreach ( $current_sources as $src ) {
									if ( strcasecmp( trim( $src->name ), trim( $source_name ) ) === 0 ) {
										$match_src = $src;
										break;
									}
								}
							}
							$show_source_input = ( $source_id <= 0 && $source_name !== '' ) || ( $detect_auto && $match_src === null );
							$file_ext   = $orig_val !== '' ? strtolower( pathinfo( $orig_val, PATHINFO_EXTENSION ) ) : '';
							$file_type  = ( $file_ext === 'pdf' ) ? 'pdf' : ( ( $file_ext === 'csv' ) ? 'csv' : 'file' );
						?>
							<tr data-index="<?php echo (int) $index; ?>">
								<th scope="row" class="check-column">
									<input type="checkbox" name="include[]" value="<?php echo (int) $index; ?>" id="sp-include-<?php echo (int) $index; ?>" checked />
								</th>
								<td class="sp-editable" data-field="date" data-index="<?php echo (int) $index; ?>" tabindex="0"><?php echo esc_html( $date_val ); ?></td>
								<td class="sp-editable" data-field="time" data-index="<?php echo (int) $index; ?>" tabindex="0"><?php echo esc_html( $time_val ?: '—' ); ?></td>
								<td class="sp-editable" data-field="description" data-index="<?php echo (int) $index; ?>" tabindex="0"><?php echo esc_html( $desc_val ); ?></td>
								<td class="column-amount sp-editable" data-field="amount" data-index="<?php echo (int) $index; ?>" tabindex="0"><?php echo esc_html( $amt_val ); ?></td>
								<td class="column-source">
									<label for="sp-source-<?php echo (int) $index; ?>" class="screen-reader-text"><?php esc_attr_e( 'Source for this row', 'statement-processor' ); ?></label>
									<select name="tx[<?php echo (int) $index; ?>][source_term_id]" id="sp-source-<?php echo (int) $index; ?>" class="sp-source-select" data-has-new="<?php echo $show_source_input ? '1' : '0'; ?>">
										<option value="add_new" <?php selected( $source_id <= 0 && ! $match_src, true ); ?>><?php esc_html_e( 'Add New', 'statement-processor' ); ?></option>
										<?php foreach ( $current_sources as $src ) : ?>
											<option value="<?php echo (int) $src->term_id; ?>" <?php selected( ( $match_src && (int) $match_src->term_id === (int) $src->term_id ) || ( $source_id > 0 && (int) $source_id === (int) $src->term_id ), true ); ?>><?php echo esc_html( $src->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<span class="sp-source-new-wrap" style="<?php echo $show_source_input ? '' : 'display:none;'; ?>">
										<input type="text" class="sp-source-new-input regular-text" name="tx[<?php echo (int) $index; ?>][source_new]" value="<?php echo esc_attr( $source_name ); ?>" placeholder="<?php esc_attr_e( 'New source name', 'statement-processor' ); ?>" />
									</span>
								</td>
								<td class="column-file-icon" title="<?php echo esc_attr( $orig_val ); ?>">
									<span class="sp-file-icon sp-file-<?php echo esc_attr( $file_type ); ?>" aria-label="<?php echo esc_attr( $orig_val ); ?>"><?php echo esc_html( strtoupper( $file_ext ?: '—' ) ); ?></span>
								</td>
								<td class="sp-hidden-inputs">
									<input type="hidden" name="tx[<?php echo (int) $index; ?>][date]" value="<?php echo esc_attr( $date_val ); ?>" />
									<input type="hidden" name="tx[<?php echo (int) $index; ?>][time]" value="<?php echo esc_attr( $time_val ); ?>" />
									<input type="hidden" name="tx[<?php echo (int) $index; ?>][description]" value="<?php echo esc_attr( $desc_val ); ?>" />
									<input type="hidden" name="tx[<?php echo (int) $index; ?>][amount]" value="<?php echo esc_attr( $amt_val ); ?>" />
									<input type="hidden" name="tx[<?php echo (int) $index; ?>][origination]" value="<?php echo esc_attr( $orig_val ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected', 'statement-processor' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \StatementProcessor\Admin\AdminPage::PAGE_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'statement-processor' ); ?></a>
				</p>
			</form>
		</div>
	<?php else : ?>

	<div class="statement-processor-section">
		<h2><?php esc_html_e( 'Import from files', 'statement-processor' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Upload PDF or CSV files. You will review the parsed transactions before they are imported.', 'statement-processor' ); ?></p>

		<form method="post" action="" enctype="multipart/form-data" class="statement-processor-upload-form">
			<?php wp_nonce_field( 'statement_processor_upload', 'statement_processor_upload_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="statement_processor_source"><?php esc_html_e( 'Source', 'statement-processor' ); ?></label>
					</th>
					<td>
						<select id="statement_processor_source" name="statement_processor_source" class="regular-text">
							<option value="detect_auto" selected="selected"><?php esc_html_e( 'Detect Automatically', 'statement-processor' ); ?></option>
							<?php foreach ( $sources as $src ) : ?>
								<option value="<?php echo esc_attr( $src->name ); ?>"><?php echo esc_html( $src->name ); ?></option>
							<?php endforeach; ?>
							<option value="add_new"><?php esc_html_e( 'Add New...', 'statement-processor' ); ?></option>
						</select>
						<p class="statement-processor-source-new-wrap" id="statement_processor_source_new_wrap" style="display: none; margin-top: 0.5em;">
							<label for="statement_processor_source_new" class="screen-reader-text"><?php esc_html_e( 'New source name', 'statement-processor' ); ?></label>
							<input type="text" id="statement_processor_source_new" name="statement_processor_source_new" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Chase Checking, Amex', 'statement-processor' ); ?>" />
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="statement_processor_files"><?php esc_html_e( 'Files', 'statement-processor' ); ?></label>
					</th>
					<td>
						<div class="statement-processor-file-dropzone" id="statement_processor_file_dropzone" aria-describedby="statement_processor_file_dropzone_hint">
							<input type="file" id="statement_processor_files" name="statement_processor_files[]" accept=".pdf,.csv" multiple />
							<span class="statement-processor-file-dropzone-label" id="statement_processor_file_dropzone_hint"><?php esc_html_e( 'Drag and drop PDF or CSV files here, or click to browse.', 'statement-processor' ); ?></span>
							<span class="statement-processor-file-dropzone-count" id="statement_processor_file_count" aria-live="polite"></span>
						</div>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary statement-processor-upload-btn" id="statement_processor_upload_btn"><?php esc_html_e( 'Upload and review', 'statement-processor' ); ?></button>
			</p>
		</form>
	</div>

	<hr />

	<div class="statement-processor-section">
		<h2><?php esc_html_e( 'Export to CSV', 'statement-processor' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Filter by month/year and source, then download a CSV file.', 'statement-processor' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="statement-processor-export-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( \StatementProcessor\Admin\AdminPage::PAGE_SLUG ); ?>" />
			<input type="hidden" name="statement_processor_export" value="1" />
			<?php wp_nonce_field( 'statement_processor_export', '_wpnonce', false ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="export_year"><?php esc_html_e( 'Year', 'statement-processor' ); ?></label>
					</th>
					<td>
						<?php
						$current_year = (int) gmdate( 'Y' );
						$years        = range( $current_year, $current_year - 10 );
						?>
						<select name="export_year" id="export_year">
							<option value=""><?php esc_html_e( 'All', 'statement-processor' ); ?></option>
							<?php foreach ( $years as $y ) : ?>
								<option value="<?php echo (int) $y; ?>"><?php echo (int) $y; ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="export_month"><?php esc_html_e( 'Month', 'statement-processor' ); ?></label>
					</th>
					<td>
						<select name="export_month" id="export_month">
							<option value=""><?php esc_html_e( 'All', 'statement-processor' ); ?></option>
							<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
								<option value="<?php echo (int) $m; ?>"><?php echo esc_html( gmdate( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="export_source"><?php esc_html_e( 'Source', 'statement-processor' ); ?></label>
					</th>
					<td>
						<select name="export_source" id="export_source">
							<option value=""><?php esc_html_e( 'All sources', 'statement-processor' ); ?></option>
							<?php foreach ( $sources as $src ) : ?>
								<option value="<?php echo esc_attr( (string) $src->term_id ); ?>"><?php echo esc_html( $src->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Download CSV', 'statement-processor' ); ?></button>
			</p>
		</form>
	</div>
	<?php endif; ?>
</div>
