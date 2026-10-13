<?php
/**
 * [kebabish_catering_menus] – «Cateringmenyer» cards on /catering, built from the SAME WooCommerce
 * catalog as the order advisor (issue #4). One product = one card; publish/unpublish, price, name, image,
 * short description (sales text) and description (menu contents) change both places with no extra work.
 *
 * Only catering PACKAGES are shown (add-ons belong to the advisor's add-on step). Campaign packages
 * appear and disappear by their Oslo-time window (KC_Catalog::is_live) and carry their label.
 * Cards never contain order fields; the CTA opens the order section (#foresporsel).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Menus {

	/** Set when the cards have been rendered on this request (the order shortcode then skips its own gallery). */
	public static $rendered = false;

	public static function init() {
		add_shortcode( 'kebabish_catering_menus', array( __CLASS__, 'render' ) );
	}

	/** Menu contents from the product description: one item per line or bullet; '' when empty. */
	public static function contents( WC_Product $p ) {
		$raw = (string) $p->get_description();
		$raw = preg_replace( '#<\s*(br|/p|/li|/div)[^>]*>#i', "\n", $raw );
		$raw = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );
		$items = array();
		foreach ( preg_split( '/\r\n|\r|\n|•/u', $raw ) as $line ) {
			$line = trim( preg_replace( '/^[\-\*·]\s*/u', '', trim( $line ) ) );
			if ( '' !== $line ) {
				$items[] = mb_substr( $line, 0, 120 );
			}
		}
		return array_slice( $items, 0, 12 );
	}

	public static function render( $atts = array() ) {
		$atts     = shortcode_atts(
			array(
				'title' => 'Cateringmenyer',
				'label' => 'Våre menyer',
				'intro' => 'Velg meny til arrangementet. Pris per person – minimum ' . (int) KC_Core::int_setting( 'min_persons' ) . ' personer totalt, og du kan fordele fritt mellom menyene.',
			),
			$atts,
			'kebabish_catering_menus'
		);
		$packages = KC_Catalog::packages();
		self::$rendered = true;
		if ( ! wp_style_is( 'kc-menus', 'registered' ) ) {
			wp_register_style( 'kc-menus', KC_URL . 'assets/kc-menus.css', array(), KC_VERSION . '-menus1' );
		}
		wp_enqueue_style( 'kc-menus' );
		if ( ! $packages ) {
			return '';
		}
		ob_start();
		?>
		<section class="kc-section kc-packages kcm" id="cateringmenyer" aria-labelledby="kcm-title">
			<div class="ko-shell">
				<div class="kc-section-head">
					<p class="kc-section-label"><span></span> <?php echo esc_html( $atts['label'] ); ?> <span></span></p>
					<h2 id="kcm-title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<p><?php echo esc_html( $atts['intro'] ); ?></p>
				</div>
				<div class="kc-package-grid kcm__grid">
					<?php foreach ( $packages as $p ) : ?>
						<?php
						$img      = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'large' ) : '';
						$alt      = $p->get_image_id() ? (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) : '';
						$sales    = trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
						$contents = self::contents( $p );
						$camp     = KC_Catalog::campaign_label( $p );
						?>
						<article class="kc-package-card kcm__card<?php echo $camp ? ' is-campaign' : ''; ?>" data-product="<?php echo esc_attr( $p->get_id() ); ?>">
							<div class="kc-package-card__media<?php echo $img ? '' : ' kcm__media--empty'; ?>">
								<?php if ( $img ) : ?>
									<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<?php if ( $camp ) : ?>
									<span class="kcm__badge"><?php echo esc_html( $camp ); ?></span>
								<?php endif; ?>
							</div>
							<div class="kc-package-card__body">
								<h3><?php echo esc_html( $p->get_name() ); ?></h3>
								<?php if ( '' !== $sales ) : ?>
									<p><?php echo esc_html( $sales ); ?></p>
								<?php endif; ?>
								<?php if ( $contents ) : ?>
									<ul class="kcm__contents" aria-label="<?php echo esc_attr( 'Innhold i ' . $p->get_name() ); ?>">
										<?php foreach ( $contents as $c ) : ?>
											<li><?php echo esc_html( $c ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<p class="kcm__price"><strong><?php echo wp_kses_post( wc_price( (float) $p->get_price() ) ); ?></strong> <span>per person</span></p>
								<a class="kcm__cta" href="#foresporsel">Bestill catering
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
								</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
