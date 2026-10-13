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
					return '' !== $p->get_price() && (float) $p->get_price() >= 0;
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
