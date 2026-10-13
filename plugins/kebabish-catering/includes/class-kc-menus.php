<?php
/**
 * [kebabish_catering_menus] – «Cateringmenyer» on /catering, built from the SAME WooCommerce catalog as
 * the order advisor (issue #4). Same markup and look as the approved static showcase (.kc-menu-showcase,
 * live template 13.10), but every card is a published catering package:
 *   image            = product image
 *   eyebrow          = product meta _kc_eyebrow   (optional, e.g. «KULLGRILL OG KARAKTER»)
 *   name             = product name
 *   taste line       = product meta _kc_taste     (optional, e.g. «Saftig · Krydret · Grillpreget»)
 *   description      = product meta _kc_card_text (sales text); falls back to the short description
 *   «I menyen»       = product meta _kc_includes, one item per line; falls back to the product description
 * The card fields are separate meta so the product's own short description / description (portion sizes,
 * per-person weights, 10-person example, notes) are never overwritten to feed the card (QA V2.5.2).
 *   price            = current product price per person
 *   campaign badge   = KC_Catalog::campaign_label() for time-limited menus
 * Publish/unpublish, price and text changes show up here and in the advisor with no extra work.
 * Only packages are shown; add-ons belong to the advisor's add-on step. No order fields on the cards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Menus {

	/** Set when the cards have been rendered on this request (the order shortcode then skips its own gallery). */
	public static $rendered = false;

	const TEXT_FIELDS = array(
		'_kc_eyebrow' => array( 'Menykort: overlinje', 'Kort tekst over navnet på menykortet, f.eks. «KULLGRILL OG KARAKTER». Valgfritt.' ),
		'_kc_taste'   => array( 'Menykort: smaksprofil', 'F.eks. «Saftig · Krydret · Grillpreget». Valgfritt.' ),
	);

	/** Multi-line card fields. Empty = fall back to the product's short description / description. */
	const AREA_FIELDS = array(
		'_kc_card_text' => array( 'Menykort: salgstekst', 'Teksten på menykortet. Tom = produktets korte beskrivelse brukes.', 400 ),
		'_kc_includes'  => array( 'Menykort: «I menyen»', 'Én rett per linje. Tom = produktbeskrivelsen brukes.', 1200 ),
	);

	public static function init() {
		add_shortcode( 'kebabish_catering_menus', array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'seed_existing_menu_cards_once' ), 25 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ), 30 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ), 30 );
	}


	/**
	 * One-time, non-destructive marketing-data bootstrap for the 3 existing package products.
	 * Runs on the next logged-in WooCommerce manager admin request, not on public traffic.
	 * Never changes IDs, prices, product description, or order data. Never overwrites
	 * any non-empty card field edited by the restaurant. Future products remain dynamic.
	 * Mark complete ONLY if all three products were found and saved successfully.
	 */
	public static function seed_existing_menu_cards_once() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$option = 'kc_menu_cards_marketing_bootstrap_20261013_v253';
		if ( 'done' === get_option( $option ) ) {
			return;
		}
		$seed = array(
			112 => array(
				'name' => 'Kebabish Grill',
				'_kc_eyebrow' => 'KULLGRILL OG KARAKTER',
				'_kc_taste' => 'Saftig · Krydret · Grillpreget',
				'_kc_card_text' => 'Saftige grillretter med karakteristisk grillsmak, servert med klassisk tilbehør.',
				'_kc_includes' => "Chicken tikka\nSeekh kebab\nRis\nNaan\nSalat\nRaita",
			),
			113 => array(
				'name' => 'Kebabish Curry',
				'_kc_eyebrow' => 'VARME OG KRYDDER',
				'_kc_taste' => 'Fyldig · Aromatisk · Varmende',
				'_kc_card_text' => 'Fyldige curryretter, aromatiske krydder og varme smaker, laget for å deles.',
				'_kc_includes' => "Butter chicken\nChana masala\nRis\nNaan\nSalat\nRaita",
			),
			114 => array(
				'name' => 'Kebabish Mix',
				'_kc_eyebrow' => 'TO SMAKSVERDENER',
				'_kc_taste' => 'Variert · Sosial · Komplett',
				'_kc_card_text' => 'En kombinasjon av kullgrill og curry for dem som ønsker variasjon rundt bordet.',
				'_kc_includes' => "Chicken tikka\nSeekh kebab\nButter chicken\nChana masala\nRis\nNaan\nSalat\nRaita",
			),
		);
		// Preflight all 3 before writing any product (not a partial import).
		$products = array();
		foreach ( $seed as $id => $fields ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || 'package' !== KC_Catalog::role( $product ) || ! KC_Core::is_catering_product( $product ) ) {
				return;
			}
			$products[ $id ] = $product;
		}
		foreach ( $seed as $id => $fields ) {
			$product = $products[ $id ];
			// Rename only the exact legacy/test names; don't override user-renamed products.
			if ( in_array( $product->get_name(), (array) ( array( 112 => array( 'Kebabish Grill [TEST]', 'Grillpakken [TEST]' ), 113 => array( 'Kebabish Curry [TEST]', 'Currypakken [TEST]' ), 114 => array( 'Kebabish Mix [TEST]' ) )[ $id ] ), true ) ) {
				$product->set_name( $fields['name'] );
			}
			foreach ( $fields as $key => $value ) {
				if ( 'name' !== $key && '' === trim( (string) $product->get_meta( $key ) ) ) {
					$product->update_meta_data( $key, $value );
				}
			}
			$product->save();
		}
		update_option( $option, 'done', false );
	}

	/* ------------------------------------------------------------------ */
	/* Admin fields (packages)                                             */
	/* ------------------------------------------------------------------ */

	public static function product_fields() {
		global $product_object;
		echo '<div class="options_group kc-menu-card-fields"><p class="form-field"><strong>Catering – menykort</strong><br><span class="description">Vises på «Cateringmenyer» på /catering. Produktets egne beskrivelser (porsjoner, mengde per person) endres ikke av disse feltene.</span></p>';
		foreach ( self::TEXT_FIELDS as $key => $f ) {
			woocommerce_wp_text_input(
				array(
					'id'                => $key,
					'label'             => $f[0],
					'value'             => $product_object instanceof WC_Product ? (string) $product_object->get_meta( $key ) : '',
					'desc_tip'          => true,
					'description'       => $f[1],
					'custom_attributes' => array( 'maxlength' => '80' ),
				)
			);
		}
		foreach ( self::AREA_FIELDS as $key => $f ) {
			woocommerce_wp_textarea_input(
				array(
					'id'          => $key,
					'label'       => $f[0],
					'value'       => $product_object instanceof WC_Product ? (string) $product_object->get_meta( $key ) : '',
					'desc_tip'    => true,
					'description' => $f[1],
					'rows'        => 4,
				)
			);
		}
		echo '</div>';
	}

	public static function save_product_fields( $product ) {
		foreach ( array_keys( self::TEXT_FIELDS ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
			$v = isset( $_POST[ $key ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, 80 ) : '';
			if ( '' === $v ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $v );
			}
		}
		foreach ( self::AREA_FIELDS as $key => $f ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
			if ( ! isset( $_POST[ $key ] ) ) {
				continue; // Field not on this form: leave stored value alone.
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$v = trim( mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ), 0, $f[2] ) );
			if ( '' === $v ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $v );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	/** Card sales text: _kc_card_text, else the short description. */
	public static function card_text( WC_Product $p ) {
		$t = trim( (string) $p->get_meta( '_kc_card_text' ) );
		return '' !== $t ? $t : trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
	}

	/** «I menyen»: _kc_includes, else the product description; one item per line or bullet. */
	public static function contents( WC_Product $p ) {
		$raw = trim( (string) $p->get_meta( '_kc_includes' ) );
		if ( '' === $raw ) {
			return array(); // Never show operational portion/weight descriptions as menu contents.
		}
		$raw = preg_replace( '#<\s*(br|/p|/li|/div)[^>]*>#i', "\n", $raw );
		$raw = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );
		$items = array();
		foreach ( preg_split( '/\r\n|\r|\n|•|·/u', $raw ) as $line ) {
			$line = trim( preg_replace( '/^[\-\*]\s*/u', '', trim( $line ) ) );
			if ( '' !== $line ) {
				$items[] = mb_substr( $line, 0, 80 );
			}
		}
		return array_slice( $items, 0, 14 );
	}

	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label' => 'Våre cateringmenyer',
				'title' => '', // Default: «Tre måter å dele smaken på», with the number following the published menus.
				'intro' => 'Fra saftig kullgrill til fyldige curryretter. Velg smaksopplevelsen som passer selskapet – og sett sammen bestillingen i vår cateringveileder.',
				'foot'  => 'Velg cateringmeny i bestillingsveilederen. Kebabish bekrefter innhold og pris før betaling.',
				'cta'   => 'Velg anledning og start bestillingen',
				'href'  => '#anledninger',
			),
			$atts,
			'kebabish_catering_menus'
		);
		self::$rendered = true;
		if ( ! wp_style_is( 'kc-menus', 'registered' ) ) {
			wp_register_style( 'kc-menus', KC_URL . 'assets/kc-menus.css', array(), KC_VERSION . '-menus2' );
		}
		wp_enqueue_style( 'kc-menus' );

		$packages = KC_Catalog::packages();
		if ( ! $packages ) {
			return '';
		}
		$href = 0 === strpos( (string) $atts['href'], '#' ) ? (string) $atts['href'] : '#anledninger';
		if ( '' === (string) $atts['title'] ) {
			$words         = array( 2 => 'To', 3 => 'Tre', 4 => 'Fire', 5 => 'Fem', 6 => 'Seks' );
			$atts['title'] = isset( $words[ count( $packages ) ] ) ? $words[ count( $packages ) ] . ' måter å dele smaken på' : 'Velg smaken som passer selskapet';
		}
		ob_start();
		?>
		<section class="kc-menu-showcase kc-section" id="cateringmenyer" aria-labelledby="kc-menu-showcase-title">
			<div class="ko-shell">
				<div class="kc-section-head">
					<p class="kc-section-label"><span></span> <?php echo esc_html( $atts['label'] ); ?> <span></span></p>
					<h2 id="kc-menu-showcase-title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<p><?php echo esc_html( $atts['intro'] ); ?></p>
				</div>
				<div class="kc-menu-showcase__grid">
					<?php foreach ( $packages as $p ) : ?>
						<?php
						$img      = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'large' ) : '';
						$alt      = $p->get_image_id() ? (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) : '';
						$eyebrow  = trim( (string) $p->get_meta( '_kc_eyebrow' ) );
						$taste    = trim( (string) $p->get_meta( '_kc_taste' ) );
						$sales    = self::card_text( $p );
						$contents = self::contents( $p );
						$camp     = KC_Catalog::campaign_label( $p );
						?>
						<article class="kc-menu-showcase__card<?php echo $camp ? ' is-campaign' : ''; ?>" data-product="<?php echo esc_attr( $p->get_id() ); ?>">
							<div class="kc-menu-showcase__photo">
								<?php if ( $img ) : ?>
									<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( '' !== $alt ? $alt : $p->get_name() ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<?php if ( $camp ) : ?>
									<span class="kc-menu-showcase__badge"><?php echo esc_html( $camp ); ?></span>
								<?php endif; ?>
							</div>
							<div class="kc-menu-showcase__body">
								<?php if ( '' !== $eyebrow ) : ?>
									<p class="kc-menu-showcase__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
								<?php endif; ?>
								<h3><?php echo esc_html( $p->get_name() ); ?></h3>
								<?php if ( '' !== $taste ) : ?>
									<p class="kc-menu-showcase__taste"><?php echo esc_html( $taste ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $sales ) : ?>
									<p class="kc-menu-showcase__description"><?php echo esc_html( $sales ); ?></p>
								<?php endif; ?>
								<?php if ( $contents ) : ?>
									<p class="kc-menu-showcase__includes"><strong>I menyen:</strong> <?php echo esc_html( implode( ' · ', $contents ) ); ?></p>
								<?php endif; ?>
								<p class="kc-menu-showcase__price"><strong><?php echo esc_html( 'kr ' . number_format( (float) $p->get_price(), 0, ',', ' ' ) ); ?></strong> per person</p>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
				<p class="kc-menu-showcase__foot"><?php echo esc_html( $atts['foot'] ); ?></p>
				<a class="kc-button kc-button--red kc-menu-showcase__cta" href="<?php echo esc_attr( $href ); ?>"><?php echo esc_html( $atts['cta'] ); ?> <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg></a>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
