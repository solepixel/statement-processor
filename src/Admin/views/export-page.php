<?php
/**
 * Statement Processor Export page template.
 *
 * @package StatementProcessor
 * @var \WP_Term[]            $sources
 * @var array<string, string> $available_columns
 * @var string[]              $default_columns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$order       = $default_columns;
$preselected = $default_columns;
if ( ! empty( $_GET['export_columns'] ) && is_array( $_GET['export_columns'] ) ) {
	$from_get   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_GET['export_columns'] ) ) ) ) );
	$preselected = array_intersect( $from_get, array_keys( $available_columns ) );
	$order       = array_values( $preselected );
	foreach ( array_keys( $available_columns ) as $key ) {
		if ( ! in_array( $key, $order, true ) ) {
			$order[] = $key;
		}
	}
} else {
	foreach ( array_keys( $available_columns ) as $key ) {
		if ( ! in_array( $key, $order, true ) ) {
			$order[] = $key;
		}
	}
}
?>
<div class="wrap statement-processor-admin">
	<h1><?php esc_html_e( 'Export to CSV', 'statement-processor' ); ?></h1>

	<div class="statement-processor-section">
		<p class="description"><?php esc_html_e( 'Filter by month/year and source, choose columns and order, then download a CSV file.', 'statement-processor' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="statement-processor-export-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( \StatementProcessor\Admin\ExportPage::PAGE_SLUG ); ?>" />
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
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Columns', 'statement-processor' ); ?>
					</th>
					<td>
						<p class="description" style="margin-bottom: 8px;"><?php esc_html_e( 'Drag to reorder; check to include in export. Only checked columns are exported, in the order shown.', 'statement-processor' ); ?></p>
						<ul id="statement-processor-export-columns" class="statement-processor-export-columns-list">
							<?php foreach ( $order as $key ) : ?>
								<?php
								$label   = isset( $available_columns[ $key ] ) ? $available_columns[ $key ] : $key;
								$checked = in_array( $key, $preselected, true );
								?>
								<li class="statement-processor-export-column-item">
									<label>
										<input type="checkbox" name="export_columns[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'statement-processor' ); ?></button>
			</p>
		</form>
	</div>
</div>
<style>
.statement-processor-export-columns-list { list-style: none; margin: 0; padding: 0; max-width: 320px; }
.statement-processor-export-column-item { padding: 6px 10px; margin: 2px 0; background: #f0f0f1; border: 1px solid #c3c4c7; cursor: move; border-radius: 2px; }
.statement-processor-export-column-item.ui-sortable-helper { box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
.statement-processor-export-column-item label { cursor: move; display: block; }
.statement-processor-export-column-item input[type="checkbox"] { margin-right: 8px; cursor: pointer; }
</style>
<script>
jQuery(function($) {
	$('#statement-processor-export-columns').sortable({
		axis: 'y',
		handle: 'label',
		placeholder: 'statement-processor-export-column-item statement-processor-export-column-placeholder',
		forcePlaceholderSize: true
	});
});
</script>
