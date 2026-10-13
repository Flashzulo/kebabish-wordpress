<?php
/**
 * Plugin Name: Kebabish Catering
 * Description: Cateringbestilling for Kebabish Oslo: pakkevalg per person, ordre med varelinjer, kontroll og godkjenning, betalingslenke og bekreftelse.
 * Version: 1.1.8
 * Author: DBN Consulting
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 * Update URI: https://dbnconsulting.no/kebabish-catering
 * Text Domain: kebabish-catering
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KC_VERSION', '1.1.8' );
define( 'KC_FILE', __FILE__ );
define( 'KC_DIR', plugin_dir_path( __FILE__ ) );
define( 'KC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Shared helpers and WooCommerce integration.
 *
 * Payment model (1.1.0):
 *  - An order can be paid only while it holds an ACTIVE approval (_kc_approval_active = yes).
 *  - Every approval is a new price revision (_kc_revision) with a NEW order key, so links from
 *    earlier revisions stop working. Revoke/cancel also rotate the key.
 *  - Verified payment (1.1.1) requires GATEWAY PROOF: a gateway adapter must answer the filter
 *    'kc_gateway_payment_proof' with the revision, amount, currency and transaction id taken from
 *    the gateway's own payment data (e.g. metadata set when the payment was created, see
 *    KC_Core::payment_reference()). The proof must match the ACTIVE approved revision.
 *    Without an adapter nothing is auto-verified: the order is flagged for manual control.
 *    A verified payment is recorded as _kc_payment_verified = yes. Nothing else counts as paid.
 *  - Sending e-mail is tracked separately from payment (see KC_Emails::record()).
 */
final class KC_Core {

	const STATUS_RECEIVED = 'keb-cat-received';
	const STATUS_APPROVED = 'keb-cat-approved';
	const CATEGORY        = 'catering';
	const SCHEMA          = '1';

	/** Order id currently inside WC_Order::payment_complete(). */
	public static $paying_order_id = 0;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
		add_filter( 'wc_order_is_editable', array( __CLASS__, 'order_is_editable' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'valid_payment_statuses' ), 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'needs_payment' ), 10, 3 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payment_complete_from_statuses' ), 10, 2 );
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'payment_complete_status' ), 10, 3 );
		add_action( 'woocommerce_pre_payment_complete', array( __CLASS__, 'mark_paying' ), 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'record_payment_attempt' ), 5 );

		foreach ( array( 'new_order', 'cancelled_order', 'failed_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_failed_order' ) as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'suppress_wc_email' ), 10, 2 );
		}

		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable_in_shop' ), 10, 2 );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'hide_from_catalog' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_category' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );
		add_action( 'kc_cleanup_submission', array( __CLASS__, 'cleanup_submission' ) );

		// 0.x registered this shortcode without a callback (fatal). Harmless stub for old test page 100.
		add_shortcode( 'kebabish_catering_request', array( __CLASS__, 'legacy_shortcode' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Statuses                                                            */
	/* ------------------------------------------------------------------ */

	public static function register_statuses() {
		foreach ( array(
			self::STATUS_RECEIVED => 'Catering – mottatt',
			self::STATUS_APPROVED => 'Catering – godkjent',
		) as $key => $label ) {
			register_post_status(
				'wc-' . $key,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: count */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ), // phpcs:ignore WordPress.WP.I18n
				)
			);
		}
	}

	public static function add_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$out[ 'wc-' . self::STATUS_RECEIVED ] = 'Catering – mottatt';
				$out[ 'wc-' . self::STATUS_APPROVED ] = 'Catering – godkjent';
			}
		}
		return $out;
	}

	public static function order_is_editable( $editable, $order ) {
		if ( self::is_catering_order( $order ) ) {
			return $order->has_status( self::STATUS_RECEIVED );
		}
		return $editable;
	}

	/* ------------------------------------------------------------------ */
	/* Payment                                                             */
	/* ------------------------------------------------------------------ */

	/** True while the order holds an approval that has not been revoked or cancelled. */
	public static function approval_active( WC_Order $order ) {
		return 'yes' === $order->get_meta( '_kc_approval_active' );
	}

	/** The only notion of "paid" in the catering flow. */
	public static function is_verified_paid( WC_Order $order ) {
		return 'yes' === $order->get_meta( '_kc_payment_verified' );
	}

	/**
	 * Payable: approved (link sent) or failed (gateway rejected – customer may retry the same revision),
	 * and only with an active approval. MOTTATT, revoked and cancelled orders stay closed.
	 */
	public static function valid_payment_statuses( $statuses, $order = null ) {
		if ( $order instanceof WC_Order && self::is_catering_order( $order ) ) {
			return self::approval_active( $order ) ? array( self::STATUS_APPROVED, 'failed' ) : array();
		}
		return $statuses;
	}

	public static function needs_payment( $needs, $order, $valid_statuses ) {
		if ( $order instanceof WC_Order && self::is_v1( $order ) ) {
			return $needs && self::approval_active( $order ) && ! self::is_verified_paid( $order );
		}
		return $needs;
	}

	/** WooCommerce completes payment only from pending/on-hold/failed/cancelled by default. */
	public static function payment_complete_from_statuses( $statuses, $order = null ) {
		if ( $order instanceof WC_Order && self::is_catering_order( $order ) ) {
			$statuses[] = self::STATUS_APPROVED;
			return array_values( array_unique( $statuses ) );
		}
		return $statuses;
	}

	/**
	 * BETALT/BEKREFTET is always WooCommerce "processing".
	 * WooCommerce also uses this filter in maybe_set_date_paid() on every status change. Outside a
	 * gateway payment_complete() (and for unverified orders) we return a status no order can have,
	 * so a manual processing/completed change never stamps a "paid" date (1.1.2).
	 */
	public static function payment_complete_status( $status, $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && self::is_catering_order( $order ) ) {
			if ( (int) $order_id === self::$paying_order_id || self::is_verified_paid( $order ) ) {
				return 'processing';
			}
			return 'kc-not-paid';
		}
		return $status;
	}

	/** Remove a "paid" date from an order without verified payment. Returns true if cleared. */
	public static function clear_unverified_date_paid( WC_Order $order ) {
		if ( self::is_v1( $order ) && ! self::is_verified_paid( $order ) && $order->get_date_paid( 'edit' ) ) {
			$order->set_date_paid( null );
			$order->add_order_note( 'Catering: «Betalt»-dato fjernet – ingen verifisert betaling er registrert.' );
			return true;
		}
		return false;
	}

	/** One-time cleanup on upgrade: unverified catering orders must not show a paid date. */
	public static function maybe_upgrade() {
		if ( KC_VERSION === get_option( 'kc_version' ) ) {
			return;
		}
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( array( 'key' => '_kc_schema', 'value' => self::SCHEMA ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order && self::clear_unverified_date_paid( $order ) ) {
				$order->save();
			}
		}
		update_option( 'kc_version', KC_VERSION, false );
	}

	/** Technical item meta is not shown in the order editor. */
	public static function hidden_item_meta( $keys ) {
		return array_merge( $keys, array( '_kc_unit', '_kc_role' ) );
	}

	public static function mark_paying( $order_id ) {
		self::$paying_order_id = (int) $order_id;
	}

	/** Reference a gateway adapter should attach to the payment (order id + approved revision). */
	public static function payment_reference( WC_Order $order ) {
		return $order->get_id() . '-r' . (int) $order->get_meta( '_kc_revision' );
	}

	/** Log each payment start (order-pay form submit) with the revision it was started for. */
	public static function record_payment_attempt( $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_v1( $order ) ) {
			return;
		}
		$attempts   = $order->get_meta( '_kc_payment_attempts' );
		$attempts   = is_array( $attempts ) ? $attempts : array();
		$attempts[] = array(
			'revision' => (int) $order->get_meta( '_kc_revision' ),
			'at'       => time(),
			'total'    => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'currency' => $order->get_currency(),
			'ref'      => self::payment_reference( $order ),
		);
		$order->update_meta_data( '_kc_payment_attempts', array_slice( $attempts, -20 ) );
		$order->save();
	}

	/**
	 * Gateway reported payment. Verify against GATEWAY proof for the active revision.
	 * Duplicate callbacks are ignored. Mails are sent and tracked separately (retryable).
	 */
	public static function on_payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::is_v1( $order ) ) {
			return;
		}
		if ( self::is_verified_paid( $order ) ) {
			return; // Duplicate callback.
		}

		$tx = (string) $order->get_transaction_id();
		/**
		 * Gateway adapter contract. Return null if unknown, or an array taken from the GATEWAY's data:
		 * array( 'revision' => int, 'amount' => string, 'currency' => string, 'transaction' => string ).
		 */
		$proof    = apply_filters( 'kc_gateway_payment_proof', null, $order, $tx );
		$decimals = wc_get_price_decimals();
		$revision = (int) $order->get_meta( '_kc_revision' );
		$problems = array();

		if ( ! self::approval_active( $order ) ) {
			$problems[] = 'ingen aktiv godkjenning (tilbaketrukket eller avslått)';
		}
		if ( ! is_array( $proof ) ) {
			$problems[] = 'mangler betalingsbevis fra betalingsløsningen (ingen gateway-adapter)';
		} else {
			if ( (int) ( $proof['revision'] ?? -1 ) !== $revision ) {
				$problems[] = sprintf( 'betalingen gjelder revisjon %s, aktiv revisjon er %d', isset( $proof['revision'] ) ? (int) $proof['revision'] : '?', $revision );
			}
			$expected = (string) $order->get_meta( '_kc_final_total' );
			if ( '' === $expected || wc_format_decimal( (string) ( $proof['amount'] ?? '' ), $decimals ) !== wc_format_decimal( $expected, $decimals ) ) {
				$problems[] = sprintf( 'betalt beløp %s, godkjent %s', (string) ( $proof['amount'] ?? '?' ), $expected ? $expected : '?' );
			}
			$currency = (string) $order->get_meta( '_kc_final_currency' );
			if ( strtoupper( (string) ( $proof['currency'] ?? '' ) ) !== strtoupper( $currency ? $currency : $order->get_currency() ) ) {
				$problems[] = sprintf( 'valuta %s, godkjent %s', (string) ( $proof['currency'] ?? '?' ), $currency );
			}
		}

		if ( $problems ) {
			self::$paying_order_id = 0;
			self::clear_unverified_date_paid( $order );
			$order->update_meta_data( '_kc_payment_mismatch', implode( '; ', $problems ) );
			$order->add_order_note( 'Catering: BETALING KREVER KONTROLL – ' . implode( '; ', $problems ) . '. Ikke registrert som verifisert betaling. Ingen bekreftelse sendt til kunden.' );
			$order->save();
			KC_Emails::operator_payment_mismatch( $order );
			return;
		}

		$order->update_meta_data( '_kc_payment_verified', 'yes' );
		$order->update_meta_data( '_kc_paid_revision', $revision );
		$order->update_meta_data( '_kc_paid_transaction', (string) ( $proof['transaction'] ?? $tx ) );
		$order->update_meta_data( '_kc_paid_at', time() );
		$order->delete_meta_data( '_kc_payment_mismatch' );
		$order->add_order_note( sprintf( 'Catering: verifisert betaling (betalingsbevis) for revisjon %d, %s %s, transaksjon %s.', $revision, (string) $proof['amount'], (string) $proof['currency'], (string) ( $proof['transaction'] ?? $tx ) ) );
		$order->save();

		KC_Emails::customer_confirmed( $order );
		KC_Emails::operator_paid( $order );
	}

	public static function suppress_wc_email( $enabled, $order ) {
		if ( $order instanceof WC_Order && self::is_catering_order( $order ) ) {
			return false;
		}
		return $enabled;
	}

	public static function not_purchasable_in_shop( $purchasable, $product ) {
		return self::is_catering_product( $product ) ? false : $purchasable;
	}

	public static function hide_from_catalog( $visible, $product_id ) {
		return self::is_catering_product( wc_get_product( $product_id ) ) ? false : $visible;
	}

	public static function ensure_category() {
		if ( ! taxonomy_exists( 'product_cat' ) || term_exists( self::CATEGORY, 'product_cat' ) ) {
			return;
		}
		wp_insert_term( 'Catering', 'product_cat', array( 'slug' => self::CATEGORY ) );
	}

	public static function legacy_shortcode() {
		return '<p>Denne testsiden er erstattet av cateringsiden.</p>';
	}

	public static function cleanup_submission( $sid ) {
		self::release( 'kc_sub_' . sanitize_key( $sid ) );
	}

	/* ------------------------------------------------------------------ */
	/* Atomic claims (R7/R8). add_option() is NOT atomic (INSERT … ON       */
	/* DUPLICATE KEY UPDATE + per-request cache), so use INSERT IGNORE on   */
	/* the unique option_name and check the affected row count.            */
	/* ------------------------------------------------------------------ */

	public static function claim( $name, $value ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $wpdb->rows_affected;
	}

	public static function claim_value( $name ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $v ? null : (string) $v;
	}

	public static function claim_update( $name, $value ) {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
	}

	/** Delete only if the row still holds $expected (owner check). Returns true if deleted. */
	public static function release_if( $name, $expected ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $expected ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Short DB lock with an OWNER TOKEN ("<unix time>|<random>").
	 * Returns the token on success, false on timeout. Only the holder of the token can release it.
	 * A stale lock (> 30 s, crashed request) is taken over with a conditional delete of exactly the
	 * stale value, so a newer lock is never removed by an old request.
	 */
	public static function acquire_lock( $name ) {
		$token = time() . '|' . wp_generate_password( 16, false, false );
		for ( $i = 0; $i < 50; $i++ ) {
			if ( self::claim( $name, $token ) ) {
				return $token;
			}
			$current = self::claim_value( $name );
			if ( null !== $current && (int) strtok( $current, '|' ) < time() - 30 ) {
				self::release_if( $name, $current );
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	/** Release a lock only if we still own it. */
	public static function release_lock( $name, $token ) {
		return is_string( $token ) && '' !== $token ? self::release_if( $name, $token ) : false;
	}

	public static function capacity_lock_name( $date ) {
		return 'kc_cap_lock_' . preg_replace( '/[^0-9-]/', '', (string) $date );
	}

	public static function release( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	public static function is_catering_order( $order ) {
		return $order instanceof WC_Order && 'yes' === $order->get_meta( '_kebabish_catering' );
	}

	public static function is_v1( $order ) {
		return self::is_catering_order( $order ) && self::SCHEMA === (string) $order->get_meta( '_kc_schema' );
	}

	public static function is_catering_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return has_term( self::CATEGORY, 'product_cat', $id );
	}

	public static function setting( $key, $default = '' ) {
		$value = get_option( 'kc_' . $key, $default );
		return is_string( $value ) ? trim( $value ) : $value;
	}

	/** Integer setting, or null if empty/non-numeric. */
	public static function int_setting( $key ) {
		$v = (string) self::setting( $key );
		return ( '' !== $v && preg_match( '/^-?\d+$/', $v ) ) ? (int) $v : null;
	}

	public static function slots() {
		$start = self::setting( 'pickup_start' );
		$end   = self::setting( 'pickup_end' );
		$step  = max( 15, (int) self::setting( 'slot_minutes', '30' ) );
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) ) {
			return array();
		}
		list( $sh, $sm ) = array_map( 'intval', explode( ':', $start ) );
		list( $eh, $em ) = array_map( 'intval', explode( ':', $end ) );
		$slots = array();
		for ( $m = $sh * 60 + $sm; $m <= $eh * 60 + $em; $m += $step ) {
			$slots[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
		}
		return $slots;
	}

	/**
	 * Intake is closed unless enabled AND every operational value is present and valid
	 * AND at least one package exists AND the tax model is the one V1 supports.
	 * Returns array( bool $open, string[] $problems, bool $enabled ).
	 */
	public static function intake_status() {
		$problems = array();

		if ( ! is_email( self::setting( 'operator_email' ) ) ) {
			$problems[] = 'Gyldig operatør-e-post';
		}
		if ( '' === self::setting( 'pickup_address' ) ) {
			$problems[] = 'Henteadresse';
		}
		$min  = self::int_setting( 'min_persons' );
		$cap  = self::int_setting( 'daily_capacity' );
		$lead = self::int_setting( 'lead_time_hours' );
		if ( null === $min || $min < 1 ) {
			$problems[] = 'Minimum personer (heltall ≥ 1)';
		}
		if ( null === $cap || $cap < 1 ) {
			$problems[] = 'Kapasitet personer per dag (heltall ≥ 1)';
		} elseif ( null !== $min && $min >= 1 && $cap < $min ) {
			$problems[] = 'Kapasitet per dag må være minst like stor som minimum personer';
		}
		if ( null === $lead || $lead < 0 ) {
			$problems[] = 'Ledetid (heltall ≥ 0)';
		}
		if ( ! self::slots() ) {
			$problems[] = 'Gyldig hentetidsvindu (TT:MM, fra før til)';
		}
		// R6: V1 is defined for WooCommerce tax calculation OFF (as mapped in production 05.10).
		// Turning tax on requires an agreed price/tax model first.
		if ( wc_tax_enabled() ) {
			$problems[] = 'Avgiftsmodell: WooCommerce-avgift er slått på, men V1 er kun definert for avgift av';
		}
		if ( ! KC_Catalog::packages() ) {
			$problems[] = 'Minst én cateringpakke';
		}
		$enabled = 'yes' === self::setting( 'intake_enabled', 'no' );
		return array( $enabled && ! $problems, $problems, $enabled );
	}

	public static function persons_label( $n ) {
		return (int) $n . ' ' . ( 1 === (int) $n ? 'person' : 'personer' );
	}

	public static function event_datetime( $order ) {
		$date = (string) $order->get_meta( '_kc_event_date' );
		$time = (string) $order->get_meta( '_kc_event_time' );
		if ( ! $date ) {
			return '';
		}
		try {
			$dt = new DateTimeImmutable( $date . ' ' . ( $time ? $time : '00:00' ), wp_timezone() );
		} catch ( Exception $e ) {
			return trim( $date . ' ' . $time );
		}
		return wp_date( 'l j. F Y', $dt->getTimestamp() ) . ( $time ? ' kl. ' . $time : '' );
	}

	public static function format_money( $amount ) {
		return wp_strip_all_tags( html_entity_decode( wc_price( $amount ) ) );
	}
}

require_once KC_DIR . 'includes/class-kc-catalog.php';
require_once KC_DIR . 'includes/class-kc-settings.php';
require_once KC_DIR . 'includes/class-kc-emails.php';
require_once KC_DIR . 'includes/class-kc-intake.php';
require_once KC_DIR . 'includes/class-kc-operator.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		KC_Core::init();
		KC_Catalog::init();
		KC_Settings::init();
		KC_Intake::init();
		KC_Operator::init();
	}
);
