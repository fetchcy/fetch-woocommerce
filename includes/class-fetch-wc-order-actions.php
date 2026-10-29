<?php
/**
 * The "Fetch" box on the WooCommerce order screen: status plus Accept /
 * Mark ready / Reject, each calling the Fetch API and then mirroring the
 * result onto the WooCommerce order.
 */

defined( 'ABSPATH' ) || exit;

class Fetch_WC_Order_Actions {

	const ACTION = 'fetch_wc_order_action';

	const REJECT_REASONS = array(
		'OUT_OF_STOCK'  => 'Out of stock',
		'STORE_CLOSED'  => 'Store closed',
		'CANNOT_FULFIL' => 'Cannot fulfil',
		'OTHER'         => 'Other',
	);

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
		// Not admin_post_{action}: the order screen's own form posts its own
		// `action` field, which admin-post.php would dispatch on instead.
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_post' ) );
	}

	public static function add_meta_box( $post_type, $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order || ! $order->get_meta( Fetch_WC_Order_Sync::META_ORDER_ID ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'fetch-wc-order', __( 'Fetch', 'fetch-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$status  = (string) $order->get_meta( Fetch_WC_Order_Sync::META_STATUS );
		$actions = Fetch_WC_Order_Mapper::allowed_actions( $status );
		$url     = admin_url( 'admin-post.php' );

		echo '<p><strong>' . esc_html__( 'Fetch order', 'fetch-for-woocommerce' ) . ':</strong> ' . esc_html( (string) $order->get_meta( Fetch_WC_Order_Sync::META_ORDER_NUMBER ) ) . '<br>';
		echo '<strong>' . esc_html__( 'Status', 'fetch-for-woocommerce' ) . ':</strong> ' . esc_html( Fetch_WC_Order_Mapper::label( $status ) ) . '</p>';

		if ( ! $actions ) {
			echo '<p class="description">' . esc_html__( 'Nothing to do here — Fetch handles the delivery from this point.', 'fetch-for-woocommerce' ) . '</p>';
			return;
		}

		// The order screen is already one big <form>, so these are submit buttons
		// in it that post to admin-post.php via `formaction`. The hidden fields
		// carry unique names; handle_post() reads only those.
		echo '<input type="hidden" name="fetch_wc_order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( self::ACTION . '_' . $order->get_id(), 'fetch_wc_nonce', false );
		$formaction = esc_url( $url );

		if ( in_array( 'accept', $actions, true ) ) {
			echo '<p><label>' . esc_html__( 'Ready in (minutes)', 'fetch-for-woocommerce' ) . ' <input type="number" name="fetch_wc_prep_minutes" min="0" max="180" style="width:70px" value="' . esc_attr( (int) Fetch_WC_Settings::get( 'prep_minutes' ) ) . '"></label></p>';
			echo '<p><button type="submit" class="button button-primary" formaction="' . $formaction . '" formmethod="post" name="fetch_wc_action" value="accept">' . esc_html__( 'Accept order', 'fetch-for-woocommerce' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		if ( in_array( 'ready', $actions, true ) ) {
			echo '<p><button type="submit" class="button button-primary" formaction="' . $formaction . '" formmethod="post" name="fetch_wc_action" value="ready">' . esc_html__( 'Mark ready for pickup', 'fetch-for-woocommerce' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		if ( in_array( 'reject', $actions, true ) ) {
			echo '<hr><p><select name="fetch_wc_reason">';
			foreach ( self::REJECT_REASONS as $code => $label ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></p><p><input type="text" name="fetch_wc_note" maxlength="300" placeholder="' . esc_attr__( 'Note (optional)', 'fetch-for-woocommerce' ) . '" style="width:100%"></p>';
			echo '<p><button type="submit" class="button" formaction="' . $formaction . '" formmethod="post" name="fetch_wc_action" value="reject" onclick="return confirm(\'' . esc_js( __( 'Reject this Fetch order? The customer is refunded.', 'fetch-for-woocommerce' ) ) . '\')">' . esc_html__( 'Reject order', 'fetch-for-woocommerce' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}

	public static function maybe_handle_post() {
		if ( isset( $_POST['fetch_wc_action'], $_POST['fetch_wc_nonce'], $_POST['fetch_wc_order_id'] ) ) {
			self::handle_post();
		}
	}

	public static function handle_post() {
		$order_id = isset( $_POST['fetch_wc_order_id'] ) ? absint( $_POST['fetch_wc_order_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $order_id, 'fetch_wc_nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to change orders.', 'fetch-for-woocommerce' ) );
		}
		$order  = wc_get_order( $order_id );
		$action = isset( $_POST['fetch_wc_action'] ) ? sanitize_key( wp_unslash( $_POST['fetch_wc_action'] ) ) : '';
		if ( $order ) {
			$args  = array(
				'prepMinutes' => isset( $_POST['fetch_wc_prep_minutes'] ) ? (int) $_POST['fetch_wc_prep_minutes'] : (int) Fetch_WC_Settings::get( 'prep_minutes' ),
				'reason'      => isset( $_POST['fetch_wc_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['fetch_wc_reason'] ) ) : 'OTHER',
				'note'        => isset( $_POST['fetch_wc_note'] ) ? sanitize_text_field( wp_unslash( $_POST['fetch_wc_note'] ) ) : '',
			);
			$error = self::run( $order, $action, $args );
			set_transient(
				'fetch_wc_notice_' . get_current_user_id(),
				$error ? array( 'error', $error ) : array( 'success', __( 'Fetch updated.', 'fetch-for-woocommerce' ) ),
				60
			);
		}
		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}

	/**
	 * Call Fetch for one action and mirror the new status.
	 *
	 * @return string|null Error message, or null on success.
	 */
	public static function run( WC_Order $order, $action, array $args = array() ) {
		$store_id = (string) Fetch_WC_Settings::get( 'store_id' );
		$fetch_id = (string) $order->get_meta( Fetch_WC_Order_Sync::META_ORDER_ID );
		$api      = new Fetch_WC_Api();

		switch ( $action ) {
			case 'accept':
				$prep   = max( 0, min( 180, isset( $args['prepMinutes'] ) ? (int) $args['prepMinutes'] : 15 ) );
				$result = $api->accept_order( $store_id, $fetch_id, $prep );
				break;
			case 'ready':
				$result = $api->ready_order( $store_id, $fetch_id );
				break;
			case 'reject':
				$reason = isset( $args['reason'] ) && isset( self::REJECT_REASONS[ $args['reason'] ] ) ? $args['reason'] : 'OTHER';
				$result = $api->reject_order( $store_id, $fetch_id, $reason, isset( $args['note'] ) ? $args['note'] : '' );
				break;
			default:
				return __( 'Unknown action.', 'fetch-for-woocommerce' );
		}

		if ( ! Fetch_WC_Api::ok( $result ) ) {
			$message = Fetch_WC_Api::error_message( $result );
			$order->add_order_note( sprintf( /* translators: 1: action, 2: error */ __( 'Fetch %1$s failed: %2$s', 'fetch-for-woocommerce' ), $action, $message ) );
			Fetch_WC_Logger::error( sprintf( 'Fetch %s failed for order #%d: %s', $action, $order->get_id(), $message ) );
			return $message;
		}

		// The API answers with the order; re-read it if the shape is unexpected.
		$data = is_array( $result['body'] ) && isset( $result['body']['status'] ) ? $result['body'] : null;
		if ( ! $data ) {
			$fresh = $api->get_order( $store_id, $fetch_id );
			$data  = Fetch_WC_Api::ok( $fresh ) && is_array( $fresh['body'] ) ? $fresh['body'] : null;
		}
		if ( $data && isset( $data['status'] ) ) {
			Fetch_WC_Order_Sync::apply_status( $order, (string) $data['status'] );
		}
		return null;
	}
}
