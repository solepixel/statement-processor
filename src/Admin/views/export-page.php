<?php
/**
 * Statement Processor Export page template.
 *
 * @package StatementProcessor
 * @var \WP_Term[] $sources
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap statement-processor-admin">
	<h1><?php esc_html_e( 'Export to CSV', 'statement-processor' ); ?></h1>

	<div class="statement-processor-section">
		<p class="description"><?php esc_html_e( 'Filter by month/year and source, then download a CSV file.', 'statement-processor' ); ?></p>

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
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'statement-processor' ); ?></button>
			</p>
		</form>
	</div>
</div>
