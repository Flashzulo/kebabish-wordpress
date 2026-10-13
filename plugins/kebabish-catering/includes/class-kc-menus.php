<?php
/**
 * [kebabish_catering_menus] – «Cateringmenyer» on /catering, built from the SAME WooCommerce catalog as
 * the order advisor (issue #4). Same markup and look as the approved static showcase (.kc-menu-showcase,
 * live template 13.10), but every card is a published catering package:
 *   image            = product image
 *   eyebrow          = product meta _kc_eyebrow   (optional, e.g. «KULLGRILL OG KARAKTER»)
 *   name             = product name
 *   taste line       = product meta _kc_taste     (optional, e.g. «Saftig · Krydret · Grillpreget»)
 *   description      = product short description (sales text)
 *   «I menyen»       = product description, one item per line/bullet
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

	public static function init() {
		add_shortcode( 'kebabish_catering_menus', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ), 30 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ), 30 );
	}

	/* ------------------------------------------------------------------ */
	/* Admin fields (packages)                                             */
	/* ------------------------------------------------------------------ */

	public static function product_fields() {
		global $product_object;
		echo '<div class="options_group kc-menu-card-fields"><p class="form-field"><strong>Catering – menykort</strong><br><span class="description">Vises på «Cateringmenyer» på /catering. Salgsteksten er produktets korte beskrivelse; «I menyen» er produktbeskrivelsen, én rett per linje.</span></p>';
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
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	/** Menu contents from the product description: one item per line or bullet. */
	public static function contents( WC_Product $p ) {
		$raw = (string) $p->get_description();
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
						$sales    = trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
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
