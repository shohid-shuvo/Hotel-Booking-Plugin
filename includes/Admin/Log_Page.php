<?php
/**
 * System Sync Diagnostic Log Viewer Page.
 *
 * @package ARC\HotelCore\Admin
 */

namespace ARC\HotelCore\Admin;

use ARC\HotelCore\Api\Api_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Diagnostic Log viewer in WooCommerce Admin (restricted to developers).
 */
class Log_Page {

	/**
	 * Hook admin menu.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 65 );
	}

	/**
	 * Register submenu under Hotel Management (strictly hidden from clients by default).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Strictly hide Diagnostic / API Logs from client view by default.
		// Only display when explicitly enabled for developers via constant or filter.
		$show_logs = apply_filters(
			'arc_show_diagnostic_logs',
			( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'ARC_DEV_MODE' ) && ARC_DEV_MODE )
		);

		if ( ! $show_logs ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . \ARC\HotelCore\Rooms\Room_Post_Type::POST_TYPE,
			__( 'System Sync Logs', 'arc-hotel-core' ),
			__( 'Sync Logs', 'arc-hotel-core' ),
			'manage_woocommerce',
			'arc-api-logs',
			[ $this, 'render' ]
		);
	}

	/**
	 * Render log viewer HTML with complete endpoint masking.
	 *
	 * @return void
	 */
	public function render(): void {
		$show_logs = apply_filters(
			'arc_show_diagnostic_logs',
			( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'ARC_DEV_MODE' ) && ARC_DEV_MODE )
		);

		if ( ! $show_logs || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this diagnostic page.', 'arc-hotel-core' ) );
		}

		global $wpdb;
		$table_name = Api_Logger::get_table_name();

		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			Api_Logger::install_table();
		} else {
			Api_Logger::migrate_legacy_logs();
		}

		$page     = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 30;
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$logs  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$total_pages = ceil( $total / $per_page );
		?>
		<div class="wrap arc-logs-wrap">
			<h1><?php esc_html_e( 'Hotel Management — System Synchronization Logs', 'arc-hotel-core' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Review background synchronization status. Proprietary architecture and sensitive credentials are automatically protected.', 'arc-hotel-core' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
				<thead>
					<tr>
						<th style="width: 60px;"><?php esc_html_e( 'ID', 'arc-hotel-core' ); ?></th>
						<th style="width: 150px;"><?php esc_html_e( 'Timestamp (UTC)', 'arc-hotel-core' ); ?></th>
						<th style="width: 80px;"><?php esc_html_e( 'Method', 'arc-hotel-core' ); ?></th>
						<th style="width: 220px;"><?php esc_html_e( 'Operation', 'arc-hotel-core' ); ?></th>
						<th style="width: 90px;"><?php esc_html_e( 'Status', 'arc-hotel-core' ); ?></th>
						<th style="width: 90px;"><?php esc_html_e( 'Duration', 'arc-hotel-core' ); ?></th>
						<th style="width: 180px;"><?php esc_html_e( 'Correlation ID', 'arc-hotel-core' ); ?></th>
						<th><?php esc_html_e( 'Status Message', 'arc-hotel-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="8" style="text-align: center; padding: 20px; color: #64748b;">
								<?php esc_html_e( 'No synchronization events recorded yet.', 'arc-hotel-core' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $row ) :
							$is_ok    = ! empty( $row->succeeded );
							$status_bg = $is_ok ? '#e8f5e9' : '#ffebee';
							$status_co = $is_ok ? '#2e7d32' : '#c62828';
							$op_label  = Api_Logger::get_operation_name( (string) $row->endpoint );
							?>
							<tr>
								<td><?php echo esc_html( $row->id ); ?></td>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><strong><?php echo esc_html( $row->method ); ?></strong></td>
								<td>
									<span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; background: #f1f5f9; color: #1e3a8a; border: 1px solid #cbd5e1;">
										<?php echo esc_html( $op_label ); ?>
									</span>
								</td>
								<td>
									<span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-weight: bold; background: <?php echo esc_attr( $status_bg ); ?>; color: <?php echo esc_attr( $status_co ); ?>;">
										<?php echo esc_html( $row->http_status ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $row->duration_ms ); ?> ms</td>
								<td><small><code><?php echo esc_html( substr( $row->correlation_id, 0, 16 ) ); ?>...</code></small></td>
								<td>
									<?php if ( ! empty( $row->error_message ) ) : ?>
										<span style="color: #c62828;"><?php echo esc_html( $row->error_message ); ?></span>
									<?php else : ?>
										<span style="color: #2e7d32;">&#10004; <?php esc_html_e( 'Success', 'arc-hotel-core' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 15px;">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php printf( esc_html__( '%d logs', 'arc-hotel-core' ), $total ); ?></span>
						<?php
						echo paginate_links( [
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'   => $total_pages,
							'current' => $page,
						] );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
