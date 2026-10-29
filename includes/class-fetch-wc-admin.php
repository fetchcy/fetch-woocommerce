<?php
/**
 * WooCommerce → Fetch settings page: connection, product sync, order import,
 * webhook registration and recent activity.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Admin {

	const PAGE   = 'fetch-for-woocommerce';
	const ACTION = 'fetch_wc_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Fetch', 'fetch-for-woocommerce' ), __( 'Fetch', 'fetch-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** One-shot notices, from this page and from the order screen actions. */
	public static function notices() {
		$key    = 'fetch_wc_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}
		delete_transient( $key );
		list( $type, $message ) = $notice;
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( 'error' === $type ? 'error' : ( 'warning' === $type ? 'warning' : 'success' ) ),
			esc_html__( 'Fetch:', 'fetch-for-woocommerce' ),
			esc_html( $message )
		);
	}

	private static function notice( $type, $message ) {
		set_transient( 'fetch_wc_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s      = Fetch_WC_Settings::all();
		$stores = get_transient( 'fetch_wc_stores_' . get_current_user_id() );
		$url    = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fetch for WooCommerce', 'fetch-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Sell your WooCommerce products on Fetch. Products, prices and stock sync automatically; Fetch orders arrive as WooCommerce orders.', 'fetch-for-woocommerce' ); ?></p>

			<form method="post" action="<?php echo esc_url( $url ); ?>">
				<?php self::hidden( 'save' ); ?>

				<h2><?php esc_html_e( 'Connection', 'fetch-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fetch_wc_environment"><?php esc_html_e( 'Server', 'fetch-for-woocommerce' ); ?></label></th>
						<td>
							<select id="fetch_wc_environment" name="environment">
								<option value="production" <?php selected( $s['environment'], 'production' ); ?>><?php esc_html_e( 'Production (api.fetch.com.cy)', 'fetch-for-woocommerce' ); ?></option>
								<option value="custom" <?php selected( $s['environment'], 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'fetch-for-woocommerce' ); ?></option>
							</select>
							<p><input type="url" id="fetch_wc_custom_base_url" name="custom_base_url" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $s['custom_base_url'] ); ?>">
							<span class="description"><?php esc_html_e( 'Only used with "Custom URL".', 'fetch-for-woocommerce' ); ?></span></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_client_id"><?php esc_html_e( 'Client ID', 'fetch-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="fetch_wc_client_id" name="client_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_client_secret"><?php esc_html_e( 'Client secret', 'fetch-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="fetch_wc_client_secret" name="client_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['client_secret'] ? __( 'Saved — leave blank to keep', 'fetch-for-woocommerce' ) : '' ); ?>">
							<p class="description"><?php esc_html_e( 'From the API key Fetch issued you (developer.fetch.com.cy).', 'fetch-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_store_id"><?php esc_html_e( 'Fetch store', 'fetch-for-woocommerce' ); ?></label></th>
						<td>
							<?php if ( is_array( $stores ) && $stores ) : ?>
								<select id="fetch_wc_store_id" name="store_id">
									<?php foreach ( $stores as $store ) : ?>
										<option value="<?php echo esc_attr( $store['id'] ); ?>" <?php selected( $s['store_id'], $store['id'] ); ?>><?php echo esc_html( $store['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( $s['store_id'] ) : ?>
								<strong><?php echo esc_html( $s['store_name'] ? $s['store_name'] : $s['store_id'] ); ?></strong>
								<input type="hidden" name="store_id" value="<?php echo esc_attr( $s['store_id'] ); ?>">
							<?php else : ?>
								<em><?php esc_html_e( 'Save your credentials, then press "Connect & load stores".', 'fetch-for-woocommerce' ); ?></em>
							<?php endif; ?>
							<p><button type="submit" class="button" name="do" value="connect"><?php esc_html_e( 'Connect & load stores', 'fetch-for-woocommerce' ); ?></button></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Products', 'fetch-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync to Fetch', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="sync_products" value="yes" <?php checked( $s['sync_products'], 'yes' ); ?>> <?php esc_html_e( 'Send product, price and stock changes to Fetch automatically', 'fetch-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Simple and variable products. Each product (and each variation) is matched on Fetch by its SKU.', 'fetch-for-woocommerce' ); ?></p>
							<p><button type="submit" class="button" name="do" value="bulk_sync" <?php disabled( ! Fetch_WC_Settings::is_connected() ); ?>><?php esc_html_e( 'Sync all products now', 'fetch-for-woocommerce' ); ?></button></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Orders', 'fetch-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Import Fetch orders', 'fetch-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="import_orders" value="yes" <?php checked( $s['import_orders'], 'yes' ); ?>> <?php esc_html_e( 'Create a WooCommerce order for every Fetch order', 'fetch-for-woocommerce' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-accept', 'fetch-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="auto_accept" value="yes" <?php checked( $s['auto_accept'], 'yes' ); ?>> <?php esc_html_e( 'Accept new Fetch orders automatically', 'fetch-for-woocommerce' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_prep_minutes"><?php esc_html_e( 'Preparation time', 'fetch-for-woocommerce' ); ?></label></th>
						<td><input type="number" id="fetch_wc_prep_minutes" name="prep_minutes" min="0" max="180" style="width:80px" value="<?php echo esc_attr( (int) $s['prep_minutes'] ); ?>"> <?php esc_html_e( 'minutes (the courier is booked to collect after this)', 'fetch-for-woocommerce' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Instant order alerts', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<?php if ( $s['webhook_registered'] ) : ?>
								<p>
								<?php
								/* translators: %s: date */
								echo esc_html( sprintf( __( 'Webhook registered on %s.', 'fetch-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $s['webhook_registered'] ) ) );
								?>
								</p>
							<?php endif; ?>
							<p><code><?php echo esc_html( Fetch_WC_Webhook::url() ); ?></code></p>
							<p><button type="submit" class="button" name="do" value="webhook" <?php disabled( ! Fetch_WC_Settings::is_connected() ); ?>><?php echo esc_html( $s['webhook_registered'] ? __( 'Re-register webhook', 'fetch-for-woocommerce' ) : __( 'Register webhook', 'fetch-for-woocommerce' ) ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Fetch then sends new orders here instantly. Without it, orders are still picked up every 2 minutes.', 'fetch-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'fetch-for-woocommerce' ), 'primary', 'submit', true, array( 'value' => 'save' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent activity', 'fetch-for-woocommerce' ); ?></h2>
			<?php $activity = Fetch_WC_Logger::activity(); ?>
			<?php if ( ! $activity ) : ?>
				<p><em><?php esc_html_e( 'Nothing yet.', 'fetch-for-woocommerce' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:960px">
					<tbody>
					<?php foreach ( array_slice( $activity, 0, 20 ) as $row ) : ?>
						<tr>
							<td style="width:170px"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['time'] ) ); ?></td>
							<td style="width:60px"><?php echo 'error' === $row['level'] ? '<span style="color:#b32d2e">' . esc_html__( 'Error', 'fetch-for-woocommerce' ) . '</span>' : esc_html__( 'Info', 'fetch-for-woocommerce' ); ?></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Full log: WooCommerce → Status → Logs, source "fetch".', 'fetch-for-woocommerce' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function hidden( $do ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
	}

	public static function handle() {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'fetch-for-woocommerce' ) );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
		self::save( $_POST );

		switch ( $do ) {
			case 'connect':
				self::connect();
				break;
			case 'bulk_sync':
				if ( Fetch_WC_Settings::is_connected() ) {
					Fetch_WC_Product_Sync::start_bulk_sync();
					self::notice( 'success', __( 'Full product sync started. Progress shows under Recent activity.', 'fetch-for-woocommerce' ) );
				}
				break;
			case 'webhook':
				self::register_webhook();
				break;
			default:
				self::notice( 'success', __( 'Settings saved.', 'fetch-for-woocommerce' ) );
		}

		Fetch_WC_Order_Sync::schedule_poll();
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** @param array<string,mixed> $post Raw $_POST. */
	private static function save( array $post ) {
		$old = Fetch_WC_Settings::all();
		$get = static function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? sanitize_text_field( wp_unslash( $post[ $key ] ) ) : '';
		};

		$environment = $get( 'environment' );
		$new         = array(
			'environment'     => in_array( $environment, array( 'production', 'custom' ), true ) ? $environment : 'production',
			'custom_base_url' => esc_url_raw( $get( 'custom_base_url' ) ),
			'client_id'       => $get( 'client_id' ),
			'sync_products'   => 'yes' === $get( 'sync_products' ) ? 'yes' : 'no',
			'import_orders'   => 'yes' === $get( 'import_orders' ) ? 'yes' : 'no',
			'auto_accept'     => 'yes' === $get( 'auto_accept' ) ? 'yes' : 'no',
			'prep_minutes'    => max( 0, min( 180, (int) $get( 'prep_minutes' ) ) ),
		);

		$secret = isset( $post['client_secret'] ) ? trim( (string) wp_unslash( $post['client_secret'] ) ) : '';
		if ( '' !== $secret ) {
			$new['client_secret'] = $secret;
		}

		$store_id = $get( 'store_id' );
		if ( '' !== $store_id ) {
			$new['store_id'] = $store_id;
			$stores          = get_transient( 'fetch_wc_stores_' . get_current_user_id() );
			if ( is_array( $stores ) ) {
				foreach ( $stores as $store ) {
					if ( $store['id'] === $store_id ) {
						$new['store_name'] = $store['name'];
					}
				}
			}
		}

		$credentials_changed = $new['environment'] !== $old['environment']
			|| $new['custom_base_url'] !== $old['custom_base_url']
			|| $new['client_id'] !== $old['client_id']
			|| ( isset( $new['client_secret'] ) && $new['client_secret'] !== $old['client_secret'] );
		if ( $credentials_changed ) {
			Fetch_WC_Api::forget_token();
			// A different key or server: the old store and webhook secret no longer apply.
			if ( $new['environment'] !== $old['environment'] || $new['client_id'] !== $old['client_id'] || $new['custom_base_url'] !== $old['custom_base_url'] ) {
				$new['webhook_secret']     = '';
				$new['webhook_registered'] = '';
				if ( '' === $store_id || $store_id === $old['store_id'] ) {
					$new['store_id']   = '';
					$new['store_name'] = '';
				}
			}
		}

		Fetch_WC_Settings::update( $new );
	}

	private static function connect() {
		$result = ( new Fetch_WC_Api() )->list_stores();
		if ( ! Fetch_WC_Api::ok( $result ) ) {
			self::notice( 'error', __( 'Could not connect:', 'fetch-for-woocommerce' ) . ' ' . Fetch_WC_Api::error_message( $result ) );
			return;
		}
		$body   = $result['body'];
		$rows   = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : ( is_array( $body ) ? $body : array() );
		$stores = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['storeId'] ) ? $row['storeId'] : ( isset( $row['id'] ) ? $row['id'] : '' );
			if ( '' !== (string) $id ) {
				$stores[] = array(
					'id'   => (string) $id,
					'name' => isset( $row['name'] ) ? (string) $row['name'] : (string) $id,
				);
			}
		}
		if ( ! $stores ) {
			self::notice( 'warning', __( 'Connected, but this key has no stores. Ask Fetch to link a store to your key.', 'fetch-for-woocommerce' ) );
			return;
		}
		set_transient( 'fetch_wc_stores_' . get_current_user_id(), $stores, HOUR_IN_SECONDS );

		// One store, or the saved one is gone: pick for the user.
		$saved = (string) Fetch_WC_Settings::get( 'store_id' );
		$ids   = wp_list_pluck( $stores, 'id' );
		if ( 1 === count( $stores ) || ! in_array( $saved, $ids, true ) ) {
			Fetch_WC_Settings::update(
				array(
					'store_id'   => $stores[0]['id'],
					'store_name' => $stores[0]['name'],
				)
			);
		}
		/* translators: %d: number of stores */
		self::notice( 'success', sprintf( _n( 'Connected. %d store found.', 'Connected. %d stores found — pick one and save.', count( $stores ), 'fetch-for-woocommerce' ), count( $stores ) ) );
	}

	private static function register_webhook() {
		$url = Fetch_WC_Webhook::url();
		if ( 0 !== strpos( $url, 'https://' ) ) {
			self::notice( 'warning', __( 'Your site is not on https, so Fetch cannot send order alerts to it. Orders are still picked up every 2 minutes.', 'fetch-for-woocommerce' ) );
			return;
		}
		$result = ( new Fetch_WC_Api() )->register_webhook( $url );
		if ( ! Fetch_WC_Api::ok( $result ) || empty( $result['body']['signingSecret'] ) ) {
			self::notice( 'error', __( 'Could not register the webhook:', 'fetch-for-woocommerce' ) . ' ' . Fetch_WC_Api::error_message( $result ) );
			return;
		}
		Fetch_WC_Settings::update(
			array(
				'webhook_secret'     => (string) $result['body']['signingSecret'],
				'webhook_registered' => time(),
			)
		);
		Fetch_WC_Logger::info( 'Webhook registered at ' . $url );
		self::notice( 'success', __( 'Webhook registered. New Fetch orders now arrive instantly.', 'fetch-for-woocommerce' ) );
	}
}
