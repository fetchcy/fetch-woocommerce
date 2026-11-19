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
		self::mirror_notice();
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

	/** Shown on every admin screen, per store, until the merchant confirms or turns mirror mode off. */
	private static function mirror_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( array_keys( Fetch_WC_Settings::stores() ) as $store_id ) {
			$pending = Fetch_WC_Mirror::pending( $store_id );
			if ( $pending && Fetch_WC_Settings::store_mirror( $store_id ) ) {
				self::mirror_notice_for( $store_id, $pending );
			}
		}
	}

	private static function mirror_notice_for( $store_id, array $pending ) {
		?>
		<div class="notice notice-warning" id="fetch_wc_mirror_notice<?php echo esc_attr( Fetch_WC_Settings::suffix( $store_id ) ); ?>">
			<p>
				<strong><?php esc_html_e( 'Fetch:', 'fetch-for-woocommerce' ); ?></strong>
				<?php
				/* translators: 1: listings to switch off, 2: active listings */
				echo esc_html( Fetch_WC_Settings::label( $store_id ) . sprintf( __( '"Mirror this shop" was paused. It would switch off %1$d of your %2$d active Fetch products, more than half, so it waits for you to confirm. Nothing has been switched off yet.', 'fetch-for-woocommerce' ), (int) $pending['off'], (int) $pending['active'] ) );
				?>
			</p>
			<?php if ( ! empty( $pending['samples'] ) ) : ?>
				<p class="description"><?php echo esc_html( __( 'For example:', 'fetch-for-woocommerce' ) . ' ' . implode( ', ', array_slice( (array) $pending['samples'], 0, 5 ) ) ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::hidden( 'mirror_apply' ); ?>
				<input type="hidden" name="store" value="<?php echo esc_attr( $store_id ); ?>">
				<p>
					<button type="submit" class="button button-primary" name="do" value="mirror_apply"><?php esc_html_e( 'Apply anyway', 'fetch-for-woocommerce' ); ?></button>
					<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Review settings', 'fetch-for-woocommerce' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	private static function notice( $type, $message ) {
		set_transient( 'fetch_wc_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s       = Fetch_WC_Settings::all();
		$choices = self::store_choices();
		$url     = admin_url( 'admin-post.php' );
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
							<?php $environment = Fetch_WC_Settings::sanitize_environment( $s['environment'] ); ?>
							<select id="fetch_wc_environment" name="environment">
								<option value="production" <?php selected( $environment, 'production' ); ?>><?php esc_html_e( 'Production (api.fetch.com.cy)', 'fetch-for-woocommerce' ); ?></option>
								<option value="staging" <?php selected( $environment, 'staging' ); ?>><?php esc_html_e( 'Staging (api-stg.fetch.com.cy)', 'fetch-for-woocommerce' ); ?></option>
								<option value="custom" <?php selected( $environment, 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'fetch-for-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Live keys, and sandbox keys Fetch issues for testing, both use Production. Your key decides whether you see test data.', 'fetch-for-woocommerce' ); ?></p>
							<p class="description"><?php esc_html_e( 'Staging is only for keys Fetch has moved to staging.', 'fetch-for-woocommerce' ); ?></p>
							<p id="fetch_wc_custom_base_url_row" <?php echo 'custom' === $environment ? '' : 'hidden'; ?>><input type="url" id="fetch_wc_custom_base_url" name="custom_base_url" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $s['custom_base_url'] ); ?>"></p>
							<script>
								document.getElementById( 'fetch_wc_environment' ).addEventListener( 'change', function () {
									document.getElementById( 'fetch_wc_custom_base_url_row' ).hidden = 'custom' !== this.value;
								} );
							</script>
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
						<th scope="row"><?php esc_html_e( 'Fetch stores', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<?php if ( $choices ) : ?>
								<input type="hidden" name="stores_form" value="1">
								<fieldset id="fetch_wc_stores">
									<?php foreach ( $choices as $i => $choice ) : ?>
										<label style="display:block;margin-bottom:4px">
											<input type="hidden" name="stores[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $choice['id'] ); ?>">
											<input type="checkbox" name="stores[<?php echo (int) $i; ?>][connect]" value="yes" <?php checked( $choice['connected'] ); ?>>
											<?php esc_html_e( 'Connect', 'fetch-for-woocommerce' ); ?> <strong><?php echo esc_html( $choice['name'] ); ?></strong>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Tick every Fetch store this shop sells in. Each one gets this shop\'s products and sends its orders here. Stock and prices are shared: one WooCommerce stock feeds every store.', 'fetch-for-woocommerce' ); ?></p>
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
							<p class="description" id="fetch_wc_language"><?php esc_html_e( 'Multilingual shops (WPML, Polylang) sync products in the default language only; translations are not sent to Fetch.', 'fetch-for-woocommerce' ); ?>
								<?php
								$language = Fetch_WC_Language::default_language_name();
								if ( '' !== $language ) {
									/* translators: %s: language name, e.g. English */
									echo ' <strong>' . esc_html( sprintf( __( 'Synced language: %s (default)', 'fetch-for-woocommerce' ), $language ) ) . '</strong>';
								}
								?>
							</p>
							<p><label><input type="checkbox" name="exclude_backorders" value="yes" <?php checked( Fetch_WC_Settings::exclude_backorders() ); ?>> <?php esc_html_e( 'Exclude backorder items', 'fetch-for-woocommerce' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Products and variations WooCommerce would sell on backorder are shown as out of stock on Fetch (stock 0) and are not added to Fetch while on backorder. One already on Fetch stays listed, at stock 0, and sells again when stock returns. Applies to every connected store.', 'fetch-for-woocommerce' ); ?></p>
							<p><button type="submit" class="button" name="do" value="bulk_sync" <?php disabled( ! Fetch_WC_Settings::is_connected() ); ?>><?php esc_html_e( 'Sync all products now', 'fetch-for-woocommerce' ); ?></button></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_price_markup"><?php esc_html_e( 'Price markup (%)', 'fetch-for-woocommerce' ); ?></label></th>
						<td>
							<input type="number" id="fetch_wc_price_markup" name="price_markup" min="0" max="100" step="0.01" style="width:80px" value="<?php echo esc_attr( (string) Fetch_WC_Settings::markup() ); ?>"> %
							<p class="description"><?php esc_html_e( 'Added to every price and sale price sent to Fetch, e.g. 10 sends a €10.00 product as €11.00. Your WooCommerce prices are not changed.', 'fetch-for-woocommerce' ); ?></p>
							<?php if ( self::multi() ) : ?>
								<p class="description"><?php esc_html_e( 'This is the default; each store below can set its own.', 'fetch-for-woocommerce' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fetch_wc_brand_attribute"><?php esc_html_e( 'Brand attribute', 'fetch-for-woocommerce' ); ?></label></th>
						<td>
							<?php $brand_attribute = Fetch_WC_Settings::sanitize_brand_attribute( $s['brand_attribute'] ); ?>
							<select id="fetch_wc_brand_attribute" name="brand_attribute">
								<option value="" <?php selected( $brand_attribute, '' ); ?>><?php esc_html_e( 'Automatic', 'fetch-for-woocommerce' ); ?></option>
								<?php foreach ( self::attribute_taxonomies() as $taxonomy => $label ) : ?>
									<option value="<?php echo esc_attr( $taxonomy ); ?>" <?php selected( $brand_attribute, $taxonomy ); ?>><?php echo esc_html( $label . ' (' . $taxonomy . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Automatic uses a brand taxonomy (WooCommerce Brands, Perfect Brands, YITH Brands), then any product attribute named Brand or Brands. Choose an attribute if your shop keeps the brand somewhere else. Changing it resends all products to Fetch.', 'fetch-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<?php self::render_catalogue_mode( $s ); ?>

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

				<?php self::render_stores( $choices ); ?>

				<?php submit_button( __( 'Save settings', 'fetch-for-woocommerce' ), 'primary', 'submit', true, array( 'value' => 'save' ) ); ?>
			</form>
			<?php // Its own form, so a preview neither saves nor needs the settings above. ?>
			<form id="fetch_wc_mirror_preview_form" method="post" action="<?php echo esc_url( $url ); ?>">
				<?php self::hidden( 'mirror_preview' ); ?>
				<input type="hidden" name="do" value="mirror_preview">
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

	/** @return array<string,string> The shop's global attributes, taxonomy => label. */
	private static function attribute_taxonomies() {
		$out = array();
		foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attribute ) {
			$out[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name;
		}
		return $out;
	}

	private static function render_catalogue_mode( array $s ) {
		$mode    = Fetch_WC_Settings::sanitize_catalogue_mode( $s['catalogue_mode'] );
		$preview = get_transient( 'fetch_wc_mirror_preview_' . get_current_user_id() );
		?>
		<h2 id="fetch_wc_catalogue"><?php esc_html_e( 'Catalogue', 'fetch-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fetch_wc_catalogue_mode"><?php esc_html_e( 'Catalogue mode', 'fetch-for-woocommerce' ); ?></label></th>
				<td>
					<select id="fetch_wc_catalogue_mode" name="catalogue_mode">
						<option value="add_update" <?php selected( $mode, 'add_update' ); ?>><?php esc_html_e( 'Add and update only', 'fetch-for-woocommerce' ); ?></option>
						<option value="mirror" <?php selected( $mode, 'mirror' ); ?>><?php esc_html_e( 'Mirror this shop 1:1', 'fetch-for-woocommerce' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Add and update only: products from this shop are added to Fetch and kept up to date. Anything else in your Fetch store is left as it is.', 'fetch-for-woocommerce' ); ?></p>
					<p class="description"><?php esc_html_e( 'Mirror this shop 1:1: Products in your Fetch store that aren\'t in this shop will be switched off on Fetch (not deleted). Prices and stock always follow this shop.', 'fetch-for-woocommerce' ); ?></p>
					<p class="description"><?php esc_html_e( 'It runs at the end of every full sync ("Sync all products now"). Product names, photos and descriptions come from Fetch\'s shared catalogue and are not overwritten: 1:1 means which products are on sale, their price, stock and availability. A product you bring back is switched on again by the next sync.', 'fetch-for-woocommerce' ); ?></p>
					<?php if ( self::multi() ) : ?>
						<p class="description"><?php esc_html_e( 'This is the default; each store below can choose its own mode and has its own Preview.', 'fetch-for-woocommerce' ); ?></p>
					<?php else : ?>
						<p><button type="submit" form="fetch_wc_mirror_preview_form" class="button" name="do" value="mirror_preview" <?php disabled( ! Fetch_WC_Settings::is_connected() ); ?>><?php esc_html_e( 'Preview', 'fetch-for-woocommerce' ); ?></button></p>
					<?php endif; ?>
					<?php if ( is_array( $preview ) ) : ?>
						<div id="fetch_wc_mirror_preview" class="notice notice-info inline" style="max-width:720px">
							<?php if ( self::multi() && ! empty( $preview['store'] ) ) : ?>
								<p><strong><?php echo esc_html( Fetch_WC_Settings::store_name( $preview['store'] ) ); ?></strong></p>
							<?php endif; ?>
							<?php if ( 'scan_failed' === $preview['status'] ) : ?>
								<p><?php echo esc_html( __( 'Preview failed, could not read your Fetch store:', 'fetch-for-woocommerce' ) . ' ' . $preview['error'] ); ?></p>
							<?php else : ?>
								<p><strong>
								<?php
								/* translators: 1: listings to switch off, 2: active listings */
								echo esc_html( sprintf( __( '%1$d of %2$d Fetch products would be switched off.', 'fetch-for-woocommerce' ), $preview['off'], $preview['active'] ) );
								?>
								</strong></p>
								<p class="description">
								<?php
								/* translators: 1: listings scanned, 2: listings that match this shop, 3: date */
								echo esc_html( sprintf( __( 'Your Fetch store has %1$d products; %2$d match products in this shop. Checked %3$s.', 'fetch-for-woocommerce' ), $preview['scanned'], $preview['kept'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $preview['time'] ) ) );
								?>
								</p>
								<?php if ( $preview['samples'] ) : ?>
									<ul style="list-style:disc;margin-left:20px">
										<?php foreach ( $preview['samples'] as $name ) : ?>
											<li><?php echo esc_html( $name ); ?></li>
										<?php endforeach; ?>
									</ul>
									<?php if ( $preview['off'] > count( $preview['samples'] ) ) : ?>
										<p class="description">
										<?php
										/* translators: %d: listings not shown */
										echo esc_html( sprintf( __( '…and %d more.', 'fetch-for-woocommerce' ), $preview['off'] - count( $preview['samples'] ) ) );
										?>
										</p>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( $preview['needs_confirmation'] ) : ?>
									<p><?php esc_html_e( 'That is more than half of your active Fetch products, so the plugin will ask you to confirm before switching anything off.', 'fetch-for-woocommerce' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/** True when more than one Fetch store is connected: per-store settings are shown. */
	private static function multi() {
		return count( Fetch_WC_Settings::stores() ) > 1;
	}

	/**
	 * Connected stores first, in their order, then the other stores the key
	 * can access (from the last "Connect & load stores").
	 *
	 * @return array<int,array{id:string,name:string,connected:bool}>
	 */
	private static function store_choices() {
		$listed  = get_transient( 'fetch_wc_stores_' . get_current_user_id() );
		$listed  = is_array( $listed ) ? $listed : array();
		$names   = array_column( $listed, 'name', 'id' );
		$choices = array();
		foreach ( array_keys( Fetch_WC_Settings::stores() ) as $id ) {
			$choices[ $id ] = array(
				'id'        => $id,
				'name'      => isset( $names[ $id ] ) ? $names[ $id ] : Fetch_WC_Settings::store_name( $id ),
				'connected' => true,
			);
		}
		foreach ( $listed as $store ) {
			if ( ! isset( $choices[ $store['id'] ] ) ) {
				$choices[ $store['id'] ] = array(
					'id'        => $store['id'],
					'name'      => $store['name'],
					'connected' => false,
				);
			}
		}
		return array_values( $choices );
	}

	/** @return array<int,string> Product categories, term id => name indented by depth. */
	private static function category_options() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$out, $children ) {
			foreach ( isset( $children[ $parent ] ) ? $children[ $parent ] : array() as $term ) {
				$out[ (int) $term->term_id ] = str_repeat( '— ', $depth ) . $term->name;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/** Each connected store: its own settings (with two or more stores) and its status. */
	private static function render_stores( array $choices ) {
		$stores = Fetch_WC_Settings::stores();
		if ( ! $stores ) {
			return;
		}
		$multi      = count( $stores ) > 1;
		$categories = $multi ? self::category_options() : array();
		$global     = array(
			'markup' => Fetch_WC_Settings::markup(),
			'mirror' => Fetch_WC_Settings::mirror(),
			'accept' => Fetch_WC_Settings::enabled( 'auto_accept' ),
			'prep'   => (int) Fetch_WC_Settings::get( 'prep_minutes' ),
		);
		?>
		<h2 id="fetch_wc_store_settings"><?php echo esc_html( $multi ? __( 'Fetch stores', 'fetch-for-woocommerce' ) : __( 'Store status', 'fetch-for-woocommerce' ) ); ?></h2>
		<?php if ( $multi ) : ?>
			<p class="description"><?php esc_html_e( 'Leave a field blank to use the default set above. Stock and prices come from the same WooCommerce products for every store; a store\'s markup is added on top.', 'fetch-for-woocommerce' ); ?></p>
		<?php endif; ?>
		<?php
		foreach ( $choices as $i => $choice ) :
			if ( ! isset( $stores[ $choice['id'] ] ) ) {
				continue;
			}
			$store = $stores[ $choice['id'] ];
			$field = 'stores[' . (int) $i . ']';
			?>
			<div class="fetch-wc-store" data-store="<?php echo esc_attr( $store['id'] ); ?>">
			<?php if ( $multi ) : ?>
				<h3><?php echo esc_html( $choice['name'] ); ?></h3>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<?php if ( $multi ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Price markup (%)', 'fetch-for-woocommerce' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( $field ); ?>[price_markup]" min="0" max="100" step="0.01" style="width:80px" placeholder="<?php echo esc_attr( (string) $global['markup'] ); ?>" value="<?php echo esc_attr( (string) $store['price_markup'] ); ?>"> %</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Catalogue mode', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $field ); ?>[catalogue_mode]">
								<option value="" <?php selected( $store['catalogue_mode'], '' ); ?>><?php echo esc_html( sprintf( /* translators: %s: the default mode */ __( 'Default (%s)', 'fetch-for-woocommerce' ), $global['mirror'] ? __( 'Mirror this shop 1:1', 'fetch-for-woocommerce' ) : __( 'Add and update only', 'fetch-for-woocommerce' ) ) ); ?></option>
								<option value="add_update" <?php selected( $store['catalogue_mode'], 'add_update' ); ?>><?php esc_html_e( 'Add and update only', 'fetch-for-woocommerce' ); ?></option>
								<option value="mirror" <?php selected( $store['catalogue_mode'], 'mirror' ); ?>><?php esc_html_e( 'Mirror this shop 1:1', 'fetch-for-woocommerce' ); ?></option>
							</select>
							<?php if ( Fetch_WC_Settings::store_mirror( $store['id'] ) ) : ?>
								<button type="submit" form="fetch_wc_mirror_preview_form" class="button" name="store" value="<?php echo esc_attr( $store['id'] ); ?>"><?php esc_html_e( 'Preview', 'fetch-for-woocommerce' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Products', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<select multiple size="6" name="<?php echo esc_attr( $field ); ?>[categories][]" style="min-width:260px" aria-label="<?php esc_attr_e( 'Categories', 'fetch-for-woocommerce' ); ?>">
								<?php foreach ( $categories as $term_id => $label ) : ?>
									<option value="<?php echo (int) $term_id; ?>" <?php selected( in_array( Fetch_WC_Language::default_term_id( $term_id ), $store['categories'], true ) ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'None selected: all products. Otherwise only products in the selected categories (or their subcategories) go to this store; one that leaves them is switched off there.', 'fetch-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-accept', 'fetch-for-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $field ); ?>[auto_accept]">
								<option value="" <?php selected( $store['auto_accept'], '' ); ?>><?php echo esc_html( $global['accept'] ? __( 'Default (on)', 'fetch-for-woocommerce' ) : __( 'Default (off)', 'fetch-for-woocommerce' ) ); ?></option>
								<option value="yes" <?php selected( $store['auto_accept'], 'yes' ); ?>><?php esc_html_e( 'Accept new orders automatically', 'fetch-for-woocommerce' ); ?></option>
								<option value="no" <?php selected( $store['auto_accept'], 'no' ); ?>><?php esc_html_e( 'Accept by hand', 'fetch-for-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preparation time', 'fetch-for-woocommerce' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( $field ); ?>[prep_minutes]" min="0" max="180" style="width:80px" placeholder="<?php echo esc_attr( (string) $global['prep'] ); ?>" value="<?php echo esc_attr( (string) $store['prep_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'fetch-for-woocommerce' ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'fetch-for-woocommerce' ); ?></th>
					<td class="fetch-wc-store-status"><?php self::render_status( $store['id'] ); ?></td>
				</tr>
			</table>
			</div>
			<?php
		endforeach;
	}

	/** Last full sync, products sent, last order and errors for one store. */
	private static function render_status( $store_id ) {
		global $wpdb;
		$status = Fetch_WC_Settings::store_status( $store_id );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$when   = static function ( $time ) use ( $format ) {
			return $time ? wp_date( $format, (int) $time ) : __( 'not yet', 'fetch-for-woocommerce' );
		};
		$count  = static function ( $key, $value = null ) use ( $wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) ( null === $value
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $key ) )
				: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $key, $value ) ) );
		};
		$sent   = $count( Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::META_PRODUCT_ID, $store_id ) );
		$errors = $count( Fetch_WC_Product_Sync::key( Fetch_WC_Product_Sync::META_STATUS, $store_id ), 'error' );
		$lines  = array(
			/* translators: %s: date, or "not yet" */
			sprintf( __( 'Last full sync: %s', 'fetch-for-woocommerce' ), $when( isset( $status['full_sync'] ) ? $status['full_sync'] : 0 ) ) . ( ! empty( $status['full_sync_failed'] ) ? ' ' . __( '(could not reach Fetch for every product)', 'fetch-for-woocommerce' ) : '' ),
			/* translators: %d: number of products */
			sprintf( __( 'Products sent: %d', 'fetch-for-woocommerce' ), $sent ),
			/* translators: %s: date, or "not yet" */
			sprintf( __( 'Last order: %s', 'fetch-for-woocommerce' ), $when( isset( $status['last_order'] ) ? $status['last_order'] : 0 ) ),
			/* translators: %d: number of products */
			sprintf( __( 'Products with a sync error: %d', 'fetch-for-woocommerce' ), $errors ),
		);
		if ( ! empty( $status['last_error'] ) ) {
			/* translators: 1: date, 2: error message */
			$lines[] = sprintf( __( 'Last error (%1$s): %2$s', 'fetch-for-woocommerce' ), $when( isset( $status['last_error_at'] ) ? $status['last_error_at'] : 0 ), $status['last_error'] );
		}
		echo implode( '<br>', array_map( 'esc_html', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per line.
	}

	/** The store a standalone form names, else the primary store, else the first connected one. */
	private static function posted_store() {
		$id = isset( $_POST['store'] ) ? sanitize_text_field( wp_unslash( $_POST['store'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle().
		if ( '' !== $id && Fetch_WC_Settings::store( $id ) ) {
			return $id;
		}
		$primary = Fetch_WC_Settings::resolve_store( null );
		if ( Fetch_WC_Settings::store( $primary ) ) {
			return $primary;
		}
		$ids = array_keys( Fetch_WC_Settings::stores() );
		return $ids ? (string) $ids[0] : $primary;
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
		if ( 'mirror_preview' === $do || 'mirror_apply' === $do ) {
			'mirror_preview' === $do ? self::mirror_preview() : self::mirror_apply();
			wp_safe_redirect( self::page_url() . ( 'mirror_preview' === $do ? '#fetch_wc_catalogue' : '' ) );
			exit;
		}
		$resync = self::save( $_POST );

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
				$messages = array(
					''       => __( 'Settings saved.', 'fetch-for-woocommerce' ),
					'markup' => __( 'Settings saved. The price markup changed, so all products are being resent to Fetch.', 'fetch-for-woocommerce' ),
					'backorders' => __( 'Settings saved. The backorder setting changed, so all products are being resent to Fetch.', 'fetch-for-woocommerce' ),
					'brand'  => __( 'Settings saved. The brand attribute changed, so all products are being resent to Fetch.', 'fetch-for-woocommerce' ),
					'stores' => __( 'Settings saved. Products are being sent to the Fetch stores you connected or changed.', 'fetch-for-woocommerce' ),
				);
				self::notice( 'success', $messages[ $resync ] );
		}

		Fetch_WC_Order_Sync::schedule_poll();
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * @param array<string,mixed> $post Raw $_POST.
	 * @return string "markup", "brand" or "stores" when that change started a product resync, else "".
	 */
	private static function save( array $post ) {
		$old        = Fetch_WC_Settings::all();
		$old_stores = Fetch_WC_Settings::stores();
		$old_store  = array();
		foreach ( array_keys( $old_stores ) as $id ) {
			$old_store[ $id ] = array( Fetch_WC_Settings::store_markup( $id ), Fetch_WC_Settings::store_categories( $id ) );
		}
		$get = static function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? sanitize_text_field( wp_unslash( $post[ $key ] ) ) : '';
		};

		$new = array(
			'environment'     => Fetch_WC_Settings::sanitize_environment( $get( 'environment' ) ),
			'custom_base_url' => esc_url_raw( $get( 'custom_base_url' ) ),
			'client_id'       => $get( 'client_id' ),
			'sync_products'   => 'yes' === $get( 'sync_products' ) ? 'yes' : 'no',
			'exclude_backorders' => 'yes' === $get( 'exclude_backorders' ) ? 'yes' : 'no',
			'import_orders'   => 'yes' === $get( 'import_orders' ) ? 'yes' : 'no',
			'auto_accept'     => 'yes' === $get( 'auto_accept' ) ? 'yes' : 'no',
			'prep_minutes'    => max( 0, min( 180, (int) $get( 'prep_minutes' ) ) ),
			'price_markup'    => Fetch_WC_Settings::sanitize_markup( $get( 'price_markup' ) ),
			'catalogue_mode'  => Fetch_WC_Settings::sanitize_catalogue_mode( $get( 'catalogue_mode' ) ),
			'brand_attribute' => Fetch_WC_Settings::sanitize_brand_attribute( $get( 'brand_attribute' ), array_keys( self::attribute_taxonomies() ) ),
		);

		$secret = isset( $post['client_secret'] ) ? trim( (string) wp_unslash( $post['client_secret'] ) ) : '';
		if ( '' !== $secret ) {
			$new['client_secret'] = $secret;
		}

		// The 1.3.0 form posted one store_id; the 1.4.0 form posts the store list.
		$stores_form = isset( $post['stores_form'] );
		$store_id    = $get( 'store_id' );
		if ( ! $stores_form && '' !== $store_id ) {
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

		$key_changed         = $new['environment'] !== $old['environment'] || $new['client_id'] !== $old['client_id'] || $new['custom_base_url'] !== $old['custom_base_url'];
		$credentials_changed = $key_changed || ( isset( $new['client_secret'] ) && $new['client_secret'] !== $old['client_secret'] );
		if ( $credentials_changed ) {
			Fetch_WC_Api::forget_token();
			// A different key or server: the old stores and webhook secret no longer apply.
			if ( $key_changed ) {
				$new['webhook_secret']     = '';
				$new['webhook_registered'] = '';
				delete_transient( 'fetch_wc_stores_' . get_current_user_id() );
				if ( $stores_form || '' === $store_id || $store_id === $old['store_id'] ) {
					$new['store_id']   = '';
					$new['store_name'] = '';
					$new['stores']     = null;
				}
			}
		}
		if ( $stores_form && ! $key_changed ) {
			$new['stores'] = self::posted_stores( $post, $old_stores );
			if ( $new['stores'] && ( '' === (string) $old['store_id'] ) ) {
				// The first store connected becomes the primary store (see Fetch_WC_Settings).
				$first             = reset( $new['stores'] );
				$new['store_id']   = $first['id'];
				$new['store_name'] = $first['name'];
			} elseif ( isset( $new['stores'][ $old['store_id'] ] ) ) {
				$new['store_name'] = $new['stores'][ $old['store_id'] ]['name'];
			}
		}

		Fetch_WC_Settings::update( $new );
		$stores = Fetch_WC_Settings::stores();
		foreach ( array_keys( $old_stores ) as $id ) {
			// A disconnected store keeps nothing queued. The primary store's 1.3.0 queue survives a key change, as before.
			if ( ! isset( $stores[ $id ] ) && ( ! $key_changed || ! Fetch_WC_Settings::is_primary( $id ) ) ) {
				Fetch_WC_Product_Sync::forget_store( $id );
				Fetch_WC_Logger::info( sprintf( 'Fetch store %s disconnected.', isset( $old_stores[ $id ]['name'] ) && '' !== $old_stores[ $id ]['name'] ? $old_stores[ $id ]['name'] : $id ) );
			}
		}
		foreach ( array_keys( $stores ) as $id ) {
			if ( ! Fetch_WC_Settings::store_mirror( $id ) ) {
				delete_option( Fetch_WC_Mirror::PENDING_OPTION . Fetch_WC_Settings::suffix( $id ) );
			}
		}
		if ( $new['catalogue_mode'] !== Fetch_WC_Settings::sanitize_catalogue_mode( $old['catalogue_mode'] ) ) {
			Fetch_WC_Logger::info( 'mirror' === $new['catalogue_mode'] ? 'Catalogue mode: mirror this shop 1:1 (applies at the end of the next full sync).' : 'Catalogue mode: add and update only.' );
		}

		if ( ! Fetch_WC_Settings::is_connected() || 'yes' !== $new['sync_products'] ) {
			return '';
		}

		// Every Fetch price depends on the store's markup, every brand on the brand
		// attribute, and what a store sells on its category filter, so resend a
		// store's catalogue when one of them changes. A store connected next to
		// others gets its catalogue straight away.
		$reason = '';
		$resync = array();
		foreach ( array_keys( $stores ) as $id ) {
			$label = Fetch_WC_Settings::label( $id );
			if ( ! isset( $old_store[ $id ] ) ) {
				if ( $old_stores ) {
					Fetch_WC_Logger::info( $label . 'Store connected; sending all products.' );
					$resync[] = $id;
					$reason   = $reason ? $reason : 'stores';
				}
				continue;
			}
			list( $old_markup, $old_categories ) = $old_store[ $id ];
			$markup                              = Fetch_WC_Settings::store_markup( $id );
			if ( $markup !== $old_markup ) {
				Fetch_WC_Logger::info( $label . sprintf( 'Price markup changed from %s%% to %s%%; resending all products.', $old_markup, $markup ) );
				$resync[] = $id;
				$reason   = 'markup';
			} elseif ( Fetch_WC_Settings::store_categories( $id ) !== $old_categories ) {
				Fetch_WC_Logger::info( $label . 'Category filter changed; resending all products.' );
				$resync[] = $id;
				$reason   = $reason ? $reason : 'stores';
			}
		}
		$old_brand = Fetch_WC_Settings::sanitize_brand_attribute( $old['brand_attribute'] );
		if ( $new['brand_attribute'] !== $old_brand ) {
			Fetch_WC_Logger::info( sprintf( 'Brand attribute changed from %s to %s; resending all products.', '' === $old_brand ? 'Automatic' : $old_brand, '' === $new['brand_attribute'] ? 'Automatic' : $new['brand_attribute'] ) );
			$resync = null; // Every store.
			$reason = 'markup' === $reason ? $reason : 'brand';
		}
		if ( $new['exclude_backorders'] !== ( 'no' === $old['exclude_backorders'] ? 'no' : 'yes' ) ) {
			Fetch_WC_Logger::info( 'Exclude backorder items turned ' . ( 'yes' === $new['exclude_backorders'] ? 'on' : 'off' ) . '; resending all products.' );
			$resync = null; // Every store.
			$reason = $reason ? $reason : 'backorders';
		}
		if ( array() === $resync ) {
			return '';
		}
		Fetch_WC_Product_Sync::start_bulk_sync( $resync );
		return $reason;
	}

	/**
	 * The ticked stores from the settings form, with their own settings. Only
	 * stores this key was seen to have (the last store list, or already
	 * connected) are accepted.
	 *
	 * @param array<string,array<string,mixed>> $old_stores
	 * @return array<string,array<string,mixed>>
	 */
	private static function posted_stores( array $post, array $old_stores ) {
		$listed = get_transient( 'fetch_wc_stores_' . get_current_user_id() );
		$names  = is_array( $listed ) ? array_column( $listed, 'name', 'id' ) : array();
		$rows   = isset( $post['stores'] ) && is_array( $post['stores'] ) ? $post['stores'] : array();
		$out    = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['connect'] ) || 'yes' !== $row['connect'] ) {
				continue;
			}
			$id = sanitize_text_field( wp_unslash( (string) $row['id'] ) );
			if ( '' === $id || ( ! isset( $names[ $id ] ) && ! isset( $old_stores[ $id ] ) ) ) {
				continue;
			}
			$field      = static function ( $key ) use ( $row ) {
				return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $row[ $key ] ) ) ) : '';
			};
			$markup     = $field( 'price_markup' );
			$mode       = $field( 'catalogue_mode' );
			$accept     = $field( 'auto_accept' );
			$prep       = $field( 'prep_minutes' );
			$categories = isset( $row['categories'] ) && is_array( $row['categories'] ) ? array_map( 'absint', $row['categories'] ) : array();
			$out[ $id ] = array(
				'id'             => $id,
				'name'           => isset( $names[ $id ] ) ? (string) $names[ $id ] : (string) $old_stores[ $id ]['name'],
				'price_markup'   => '' === $markup ? '' : Fetch_WC_Settings::sanitize_markup( $markup ),
				'catalogue_mode' => '' === $mode ? '' : Fetch_WC_Settings::sanitize_catalogue_mode( $mode ),
				'categories'     => array_values( array_unique( array_filter( array_map( array( 'Fetch_WC_Language', 'default_term_id' ), $categories ) ) ) ),
				'auto_accept'    => in_array( $accept, array( 'yes', 'no' ), true ) ? $accept : '',
				'prep_minutes'   => '' === $prep ? '' : max( 0, min( 180, (int) $prep ) ),
			);
		}
		return $out;
	}

	private static function mirror_preview() {
		if ( ! Fetch_WC_Settings::is_connected() ) {
			return;
		}
		$store_id        = self::posted_store();
		$label           = Fetch_WC_Settings::label( $store_id );
		$result          = Fetch_WC_Mirror::preview( $store_id );
		$result['time']  = time();
		$result['store'] = $store_id;
		set_transient( 'fetch_wc_mirror_preview_' . get_current_user_id(), $result, HOUR_IN_SECONDS );
		if ( 'scan_failed' === $result['status'] ) {
			self::notice( 'error', $label . __( 'Preview failed:', 'fetch-for-woocommerce' ) . ' ' . $result['error'] );
			return;
		}
		Fetch_WC_Logger::info( $label . sprintf( 'Mirror preview: scanned %d Fetch products, %d would be kept, %d would be switched off.', $result['scanned'], $result['kept'], $result['off'] ) );
		/* translators: 1: listings to switch off, 2: active listings */
		self::notice( 'success', $label . sprintf( __( 'Preview: %1$d of %2$d Fetch products would be switched off. Nothing was changed.', 'fetch-for-woocommerce' ), $result['off'], $result['active'] ) );
	}

	/** The merchant confirms a store's paused run once; it applies in the background. */
	private static function mirror_apply() {
		$store_id = self::posted_store();
		$pending  = Fetch_WC_Mirror::pending( $store_id );
		if ( ! $pending || ! Fetch_WC_Settings::store_mirror( $store_id ) || ! Fetch_WC_Settings::is_connected() ) {
			return;
		}
		delete_option( Fetch_WC_Mirror::PENDING_OPTION . Fetch_WC_Settings::suffix( $store_id ) );
		as_enqueue_async_action( Fetch_WC_Mirror::HOOK, Fetch_WC_Settings::job_args( $store_id, array( 'up_to' => (int) $pending['off'] ) ), Fetch_WC_Product_Sync::GROUP );
		Fetch_WC_Logger::info( Fetch_WC_Settings::label( $store_id ) . sprintf( 'Mirror confirmed by %s: switching off up to %d Fetch products.', wp_get_current_user()->user_login, (int) $pending['off'] ) );
		self::notice( 'success', __( 'Confirmed. The products are being switched off on Fetch; progress shows under Recent activity.', 'fetch-for-woocommerce' ) );
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

		$names     = array_column( $stores, 'name', 'id' );
		$connected = Fetch_WC_Settings::stores();
		$saved     = Fetch_WC_Settings::get( 'stores' );
		if ( ! array_intersect_key( $connected, $names ) ) {
			// Nothing connected yet, or the connected stores are gone: connect the first for the user.
			$first = $stores[0];
			Fetch_WC_Settings::update(
				array(
					'store_id'   => $first['id'],
					'store_name' => $first['name'],
					'stores'     => is_array( $saved ) ? array( $first['id'] => $first ) : null,
				)
			);
		} else {
			// Keep the names current.
			$update = array();
			if ( is_array( $saved ) ) {
				foreach ( $saved as $id => $store ) {
					if ( isset( $names[ $id ] ) && is_array( $store ) ) {
						$saved[ $id ]['name'] = $names[ $id ];
					}
				}
				$update['stores'] = $saved;
			}
			if ( isset( $names[ (string) Fetch_WC_Settings::get( 'store_id' ) ] ) ) {
				$update['store_name'] = $names[ (string) Fetch_WC_Settings::get( 'store_id' ) ];
			}
			Fetch_WC_Settings::update( $update );
		}
		/* translators: %d: number of stores */
		self::notice( 'success', sprintf( _n( 'Connected. %d store found.', 'Connected. %d stores found — tick every store this shop sells in, then save.', count( $stores ), 'fetch-for-woocommerce' ), count( $stores ) ) );
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
