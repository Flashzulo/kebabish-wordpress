<?php
/**
 * Catering catalog: WooCommerce products in the "catering" category,
 * each with a role (package/add-on), a unit (person/portion/package) and a minimum quantity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Catalog {

	const ROLES = array(
		'package' => 'Pakke',
		'addon'   => 'Tillegg',
	);

	const UNITS = array(
		'person'  => array( 'person', 'personer', 'per person' ),
		'portion' => array( 'porsjon', 'porsjoner', 'per porsjon' ),
		'piece'   => array( 'stk', 'stk', 'per stk' ),
	);

	/** Saulo 05.10: packages are always per person (R9); add-ons only per piece or portion. */
	const ADDON_UNITS = array( 'piece', 'portion' );

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'admin_post_kc_seed_test_catalog', array( __CLASS__, 'seed_test_catalog' ) );
		add_action( 'admin_post_kc_delete_test_catalog', array( __CLASS__, 'delete_test_catalog' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Product admin fields                                                */
	/* ------------------------------------------------------------------ */

	public static function product_fields() {
		echo '<div class="options_group kc-product-fields">';
		echo '<p class="form-field"><strong>Catering</strong><br><span class="description">Gjelder kun produkter i kategorien «Catering».</span></p>';
		woocommerce_wp_select(
			array(
				'id'      => '_kc_role',
				'label'   => 'Type',
				'options' => self::ROLES,
			)
		);
		$units = array();
		foreach ( self::ADDON_UNITS as $key ) {
			$units[ $key ] = ucfirst( self::UNITS[ $key ][2] );
		}
		woocommerce_wp_select(
			array(
				'id'          => '_kc_unit',
				'label'       => 'Enhet (kun tillegg)',
				'options'     => $units,
				'desc_tip'    => true,
				'description' => 'Pakker prises alltid per person. Tillegg selges per stk eller per porsjon.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_kc_min_qty',
				'label'             => 'Minimum antall (kun tillegg)',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => 'Gjelder kun tillegg. For pakker gjelder «Minimum personer» totalt for hele bestillingen (Catering-innstillinger).',
			)
		);
		echo '<p class="form-field"><strong>Kampanje / tidsbegrenset meny</strong><br><span class="description">La stå tomt for faste produkter. Tidspunkt er norsk tid (Europe/Oslo). Utenfor perioden vises produktet ikke og kan ikke bestilles.</span></p>';
		global $product_object;
		foreach ( self::CAMPAIGN_FIELDS as $key => $f ) {
			$cur = $product_object instanceof WC_Product ? (string) $product_object->get_meta( $key ) : '';
			woocommerce_wp_text_input(
				array(
					'id'          => $key,
					'value'       => 'datetime-local' === $f[1] ? str_replace( ' ', 'T', $cur ) : $cur,
					'label'       => $f[0],
					'type'        => $f[1],
					'desc_tip'    => true,
					'description' => $f[2],
				)
			);
		}
		echo '</div>';
	}

	public static function save_product_fields( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
		$role = isset( $_POST['_kc_role'] ) ? sanitize_key( wp_unslash( $_POST['_kc_role'] ) ) : '';
		$unit = isset( $_POST['_kc_unit'] ) ? sanitize_key( wp_unslash( $_POST['_kc_unit'] ) ) : '';
		$min  = isset( $_POST['_kc_min_qty'] ) ? absint( wp_unslash( $_POST['_kc_min_qty'] ) ) : 1;
		// phpcs:enable
		$product->update_meta_data( '_kc_role', array_key_exists( $role, self::ROLES ) ? $role : 'package' );
		$role = array_key_exists( $role, self::ROLES ) ? $role : 'package';
		$product->update_meta_data( '_kc_unit', 'package' === $role ? 'person' : ( in_array( $unit, self::ADDON_UNITS, true ) ? $unit : 'piece' ) );
		$product->update_meta_data( '_kc_min_qty', max( 1, $min ) );
		foreach ( self::CAMPAIGN_FIELDS as $key => $f ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
			$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$val = self::clean_campaign_value( $raw, 'date' === $f[1] );
			if ( '' === $val ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $val );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Campaign / time-limited menus (issue #4)                            */
	/* ------------------------------------------------------------------ */

	/** meta key => [label, input type, help]. Datetimes are Oslo local time 'Y-m-d H:i'; dates 'Y-m-d'. */
	const CAMPAIGN_FIELDS = array(
		'_kc_show_from'    => array( 'Vises fra', 'datetime-local', 'Første tidspunkt produktet vises og kan bestilles. Tomt = med en gang.' ),
		'_kc_show_until'   => array( 'Vises til', 'datetime-local', 'Siste tidspunkt produktet vises og kan bestilles. Tomt = ingen sluttdato.' ),
		'_kc_pickup_from'  => array( 'Henting fra dato', 'date', 'Tidligste hentedato for dette produktet. Tomt = ingen grense.' ),
		'_kc_pickup_until' => array( 'Henting til dato', 'date', 'Seneste hentedato for dette produktet. Tomt = ingen grense.' ),
	);

	/** Accepts 'Y-m-d', 'Y-m-dTH:i' or 'Y-m-d H:i'; returns normalised value or '' when invalid. */
	public static function clean_campaign_value( $raw, $date_only ) {
		$raw = trim( str_replace( 'T', ' ', (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Europe/Oslo' );
		$fmt = $date_only ? '!Y-m-d' : '!Y-m-d H:i';
		$d   = DateTimeImmutable::createFromFormat( $fmt, $raw, $tz );
		if ( ! $d || $d->format( $date_only ? 'Y-m-d' : 'Y-m-d H:i' ) !== $raw ) {
			return '';
		}
		return $raw;
	}

	private static function campaign_time( $product, $key ) {
		$v = (string) $product->get_meta( $key );
		if ( '' === $v ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $v, wp_timezone() );
		return $d ? $d : null;
	}

	/** Visible and orderable right now (Oslo time)? Products without campaign fields always are. */
	public static function is_live( $product, $now = null ) {
		$now   = $now ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$from  = self::campaign_time( $product, '_kc_show_from' );
		$until = self::campaign_time( $product, '_kc_show_until' );
		if ( $from && $now < $from ) {
			return false;
		}
		if ( $until && $now > $until ) {
			return false;
		}
		return true;
	}

	/** Pickup date window for the product: array( from 'Y-m-d'|'' , until 'Y-m-d'|'' ). */
	public static function pickup_window( $product ) {
		return array( (string) $product->get_meta( '_kc_pickup_from' ), (string) $product->get_meta( '_kc_pickup_until' ) );
	}

	/** Is the pickup date (Y-m-d) inside the product's window? */
	public static function pickup_ok( $product, $date ) {
		list( $from, $until ) = self::pickup_window( $product );
		if ( '' !== $from && $date < $from ) {
			return false;
		}
		if ( '' !== $until && $date > $until ) {
			return false;
		}
		return true;
	}

	/** True when the product has any campaign field set. */
	public static function is_campaign( $product ) {
		foreach ( array_keys( self::CAMPAIGN_FIELDS ) as $key ) {
			if ( '' !== (string) $product->get_meta( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** Short customer label, e.g. «Kun til 31.10» / «Hentes 20.10–31.10». '' for permanent products. */
	public static function campaign_label( $product ) {
		$parts = array();
		$until = self::campaign_time( $product, '_kc_show_until' );
		if ( $until ) {
			$parts[] = 'Bestill innen ' . $until->format( 'd.m' );
		}
		list( $pf, $pu ) = self::pickup_window( $product );
		$f = function ( $d ) {
			$x = DateTimeImmutable::createFromFormat( '!Y-m-d', $d, wp_timezone() );
			return $x ? $x->format( 'd.m' ) : $d;
		};
		if ( '' !== $pf && '' !== $pu ) {
			$parts[] = 'hentes ' . $f( $pf ) . '–' . $f( $pu );
		} elseif ( '' !== $pf ) {
			$parts[] = 'hentes fra ' . $f( $pf );
		} elseif ( '' !== $pu ) {
			$parts[] = 'hentes senest ' . $f( $pu );
		}
		return $parts ? ucfirst( implode( ', ', $parts ) ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                             */
	/* ------------------------------------------------------------------ */

	/** @return WC_Product[] */
	public static function products() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$found = wc_get_products(
			array(
				'status'   => 'publish',
				'type'     => array( 'simple' ),
				'category' => array( KC_Core::CATEGORY ),
				'limit'    => 100,
				'orderby'  => 'menu_order',
				'order'    => 'ASC',
			)
		);
		$cache = array_values(
			array_filter(
				$found,
				function ( $p ) {
					// Published + priced + inside any campaign window (Oslo time). Drafts never reach here.
					return '' !== $p->get_price() && (float) $p->get_price() >= 0 && self::is_live( $p );
				}
			)
		);
		return $cache;
	}

	public static function packages() {
		return array_values(
			array_filter(
				self::products(),
				function ( $p ) {
					return 'package' === self::role( $p );
				}
			)
		);
	}

	public static function addons() {
		return array_values(
			array_filter(
				self::products(),
				function ( $p ) {
					return 'addon' === self::role( $p );
				}
			)
		);
	}

	/** Catalog product by id, or null if it is not an orderable catering product. */
	public static function find( $product_id ) {
		foreach ( self::products() as $p ) {
			if ( $p->get_id() === (int) $product_id ) {
				return $p;
			}
		}
		return null;
	}

	public static function role( $product ) {
		$role = (string) $product->get_meta( '_kc_role' );
		return array_key_exists( $role, self::ROLES ) ? $role : 'package';
	}

	/** Packages are always per person, whatever is stored (R9). */
	public static function unit( $product ) {
		if ( 'package' === self::role( $product ) ) {
			return 'person';
		}
		$unit = (string) $product->get_meta( '_kc_unit' );
		return in_array( $unit, self::ADDON_UNITS, true ) ? $unit : 'piece';
	}

	/** Persons on an order = sum of package line quantities. Add-ons never add persons. */
	public static function persons_in_order( WC_Order $order ) {
		$n = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$role = (string) $item->get_meta( '_kc_role' );
			if ( '' === $role ) {
				// Line without role meta (e.g. added by a tool): derive from the catering product.
				$product = $item->get_product();
				$role    = ( $product && KC_Core::is_catering_product( $product ) ) ? self::role( $product ) : '';
			}
			if ( 'package' === $role ) {
				$n += (int) $item->get_quantity();
			}
		}
		return $n;
	}

	/**
	 * Saulo 06.10: the minimum applies to the TOTAL number of persons in the order (setting
	 * «Minimum personer»), freely split between packages. A package therefore has no own
	 * minimum (1). Add-ons keep their product minimum.
	 */
	public static function min_qty( $product ) {
		if ( 'package' === self::role( $product ) ) {
			return 1;
		}
		return max( 1, (int) $product->get_meta( '_kc_min_qty' ) );
	}

	public static function unit_label( $unit, $qty = 1, $form = null ) {
		$labels = self::UNITS[ $unit ] ?? self::UNITS['person'];
		if ( 'per' === $form ) {
			return $labels[2];
		}
		return 1 === (int) $qty ? $labels[0] : $labels[1];
	}

	/* ------------------------------------------------------------------ */
	/* Test catalog (clearly marked, removable)                            */
	/* ------------------------------------------------------------------ */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Ingen tilgang.', 403 );
		}
		check_admin_referer( $action );
	}

	public static function seed_test_catalog() {
		self::guard( 'kc_seed_test_catalog' );
		KC_Core::ensure_category();
		$term = get_term_by( 'slug', KC_Core::CATEGORY, 'product_cat' );

		$items = array(
			array( 'Grillpakken [TEST]', 'package', 'person', 1, '249', 'TESTINNHOLD – ikke reell pakke eller pris.' ),
			array( 'Currypakken [TEST]', 'package', 'person', 1, '199', 'TESTINNHOLD – ikke reell pakke eller pris.' ),
			array( 'Kebabish Mix [TEST]', 'package', 'person', 1, '229', 'TESTINNHOLD – ikke reell pakke eller pris.' ),
			array( 'Ekstra naan [TEST]', 'addon', 'piece', 1, '25', 'TESTINNHOLD.' ),
			array( 'Raita [TEST]', 'addon', 'portion', 1, '30', 'TESTINNHOLD.' ),
			array( 'Mango lassi [TEST]', 'addon', 'piece', 1, '45', 'TESTINNHOLD.' ),
		);

		$order = 0;
		foreach ( $items as $item ) {
			list( $name, $role, $unit, $min, $price, $desc ) = $item;
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_regular_price( $price );
			$product->set_short_description( $desc );
			$product->set_virtual( false );
			$product->set_menu_order( $order++ );
			if ( $term ) {
				$product->set_category_ids( array( (int) $term->term_id ) );
			}
			$product->update_meta_data( '_kc_role', $role );
			$product->update_meta_data( '_kc_unit', $unit );
			$product->update_meta_data( '_kc_min_qty', $min );
			$product->update_meta_data( '_kc_test', 'yes' );
			$product->save();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=kebabish_catering&kc_msg=seeded' ) );
		exit;
	}

	public static function delete_test_catalog() {
		self::guard( 'kc_delete_test_catalog' );
		$ids = wc_get_products(
			array(
				'status'     => array( 'publish', 'draft', 'private' ),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_kc_test', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$p->delete( false ); // To trash, recoverable.
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=kebabish_catering&kc_msg=deleted&kc_n=' . count( $ids ) ) );
		exit;
	}
}
