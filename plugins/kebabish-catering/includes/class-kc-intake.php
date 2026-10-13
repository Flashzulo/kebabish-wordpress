<?php
/**
 * Customer side: [kebabish_catering_order] on /catering and the submit handler.
 *
 * V2 (Saulo D1–D13, 12.10): all ordering happens in the occasion advisor (KC_Advisor + kc-advisor.js):
 * spørsmål -> pakker -> tillegg -> gjennomgang/bekreft -> kontakt/henting -> send -> ordre MOTTATT.
 * The page itself only shows package pictures + names (#pakker) and the occasion entry points (#foresporsel).
 * Without JavaScript a plain order form is shown instead (<noscript>), so nobody gets stuck.
 * Price is always recalculated on the server; the browser total is display only.
 * Errors and entered values survive a redirect via a short-lived transient.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-kc-advisor.php';
require_once __DIR__ . '/class-kc-menu-review.php';
require_once __DIR__ . '/class-kc-menus.php';

final class KC_Intake {

	const ACTION     = 'kc_submit';
	const NONCE      = 'kc_submit_order';
	const STATE_TTL  = 20 * MINUTE_IN_SECONDS;
	const RATE_LIMIT = 5; // Submissions per IP per hour.

	public static function init() {
		add_shortcode( 'kebabish_catering_order', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		KC_Advisor::init();
		KC_Menu_Review::init();
		KC_Menus::init();
	}

	public static function register_assets() {
		// Asset revision suffix busts caches without changing KC_VERSION (which would run maybe_upgrade()).
		$asset_ver = KC_VERSION . '-v2.4';
		wp_register_style( 'kc-order', KC_URL . 'assets/kc-order.css', array(), $asset_ver );
		wp_register_style( 'kc-advisor', KC_URL . 'assets/kc-advisor.css', array( 'kc-order' ), $asset_ver );
		wp_register_script( 'kc-advisor', KC_URL . 'assets/kc-advisor.js', array(), $asset_ver, true );
	}

	/* ================================================================== */
	/* Render                                                              */
	/* ================================================================== */

	public static function render() {
		if ( ! wp_style_is( 'kc-order', 'registered' ) || ! wp_script_is( 'kc-advisor', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'kc-order' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- tokens only select our own transient.
		$done_token  = isset( $_GET['kc_done'] ) ? sanitize_key( wp_unslash( $_GET['kc_done'] ) ) : '';
		$state_token = isset( $_GET['kc'] ) ? sanitize_key( wp_unslash( $_GET['kc'] ) ) : '';
		// phpcs:enable

		if ( $done_token ) {
			$done = get_transient( 'kc_done_' . $done_token );
			if ( is_array( $done ) ) {
				return self::render_done( $done );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pending_sid = isset( $_GET['kc_pending'] ) ? sanitize_key( wp_unslash( $_GET['kc_pending'] ) ) : '';
		if ( $pending_sid ) {
			$state = KC_Core::claim_value( 'kc_sub_' . $pending_sid );
			if ( null !== $state && 0 === strpos( $state, 'done:' ) ) {
				$done = get_transient( 'kc_done_' . substr( $state, 5 ) );
				if ( is_array( $done ) ) {
					return self::render_done( $done );
				}
			}
			if ( 'pending' === $state ) {
				return self::render_pending();
			}
		}

		list( $open, , ) = KC_Core::intake_status();
		if ( ! $open ) {
			return self::render_closed();
		}

		wp_enqueue_style( 'kc-advisor' );
		wp_enqueue_script( 'kc-advisor' );

		$state  = $state_token ? get_transient( 'kc_state_' . $state_token ) : false;
		$errors = is_array( $state ) ? (array) ( $state['errors'] ?? array() ) : array();
		$old    = is_array( $state ) ? (array) ( $state['data'] ?? array() ) : array();
		$qty    = (array) ( $old['qty'] ?? array() );
		$adv    = ( isset( $old['advisor'] ) && is_array( $old['advisor'] ) ) ? $old['advisor'] : null;

		// Deep link / card link without JS-click (?occasion=…): whitelisted, opens the advisor on load.
		$autostart = '';
		if ( ! $state && isset( $_GET['occasion'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display default only.
			$requested = sanitize_key( wp_unslash( $_GET['occasion'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( array_key_exists( $requested, self::occasions() ) ) {
				$autostart       = $requested;
				$old['occasion'] = $requested;
			}
		}
		$occasion = (string) ( $old['occasion'] ?? '' );
		if ( '' === $occasion && $adv ) {
			$occasion = (string) $adv['occasion'];
		}

		$boot = array(
			'autostart'  => $autostart,
			'reopen'     => $errors ? ( ( isset( $errors['lines'] ) && 1 === count( $errors ) ) ? 'packages' : 'contact' ) : '',
			'linesError' => isset( $errors['lines'] ) ? (string) $errors['lines'] : '',
		);

		$packages = KC_Catalog::packages();
		$return   = get_permalink() ? get_permalink() : home_url( '/catering/' );

		ob_start();
		// The «Cateringmenyer» cards ([kebabish_catering_menus]) replace this minimal gallery when they are on the page.
		if ( ! KC_Menus::$rendered ) {
			echo self::package_gallery( $packages ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
		?>
		<section class="kc-inquiry kca-start kc-story-v255" id="foresporsel" aria-labelledby="kc-inquiry-title">
			<div class="kc-inquiry__media" aria-hidden="true"></div>
			<div class="kc-inquiry__shade" aria-hidden="true"></div>
			<div class="ko-shell kc-story-v255__grid kca-start__inner">
				<div class="kc-story-v255__story">
					<p class="kc-kicker">Vår historie <span></span></p>
					<h2 id="kc-inquiry-title">Et bord har alltid plass til én til.</h2>
					<div class="kc-story__text">
						<p>I pakistansk matkultur har måltidet en helt spesiell plass. Fra store Eid-feiringer til små sammenkomster med familien – maten er ofte det som bringer oss sammen.</p>
						<p>Rundt bordet deles mer enn mat. Her møtes generasjoner, historier fortelles og fellesskapet får leve videre.</p>
						<p>Det er denne følelsen vi ønsker å ta med inn i Kebabish. Smaken av grill, duften av krydder og gleden ved å servere mat som mennesker kan samles rundt.</p>
						<p class="kc-story__signature">Charcoal &amp; Spice</p>
					</div>
				</div>
				<aside class="kc-story-v255__order" aria-label="Start cateringbestilling">
					<p class="kc-story-v255__eyebrow">Catering fra Kebabish</p>
					<h3>La maten samle gjengen.</h3>
					<p class="kc-story-v255__description">Velg anledning, cateringmeny og antall personer. Send forespørselen – vi bekrefter før betaling.</p>
					<?php if ( 'yes' === KC_Core::setting( 'test_banner', 'yes' ) ) : ?>
						<p class="kco-test">Testbestilling – pakker og priser er ikke endelige.</p>
					<?php endif; ?>
					<details class="kc-story-v255__picker">
						<summary class="kc-story-v255__cta">Start cateringbestilling <span aria-hidden="true">→</span></summary>
						<div class="kca-start__choices kc-story-v255__choices" data-kca-start hidden>
							<?php foreach ( self::occasions() as $key => $label ) : ?>
								<a class="kc-story-v255__choice" href="<?php echo esc_url( add_query_arg( 'occasion', $key, $return ) . '#foresporsel' ); ?>" data-kc-occasion="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><span aria-hidden="true">→</span></a>
							<?php endforeach; ?>
						</div>
					</details>
					<p class="kca-start__resume" data-kca-resume hidden><button type="button" class="kc-button kc-button--line" data-kca-resume-btn>Fortsett bestillingen</button></p>
					<p class="kc-story-v255__fine">Henting · Breigata 12, Oslo<br>Ingen betaling ved innsending</p>
				</aside>
			</div>
			<noscript>
				<div class="ko-shell kc-story-v255__fallback">
					<?php echo self::fallback_form( $old, $errors, $qty, $return ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				</div>
			</noscript>
		</section>

		<form class="kco kco--v2" id="kca-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate
			data-decimals="<?php echo esc_attr( wc_get_price_decimals() ); ?>"
			data-dec-sep="<?php echo esc_attr( wc_get_price_decimal_separator() ); ?>"
			data-thou-sep="<?php echo esc_attr( wc_get_price_thousand_separator() ); ?>">
			<?php echo self::form_basics( $return ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<input type="hidden" name="kc_occasion" value="<?php echo esc_attr( $occasion ); ?>" data-kca-occasion>
			<div data-kca-qty hidden>
				<?php foreach ( KC_Catalog::products() as $product ) : ?>
					<input type="hidden" name="kc_qty[<?php echo esc_attr( $product->get_id() ); ?>]" value="<?php echo esc_attr( max( 0, (int) ( $qty[ $product->get_id() ] ?? 0 ) ) ); ?>" data-id="<?php echo esc_attr( $product->get_id() ); ?>">
				<?php endforeach; ?>
			</div>
			<?php echo KC_Advisor::hidden_fields( $adv ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<?php echo KC_Advisor::render_dialog( self::contact_fields( $old, $errors ), $boot ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** Hidden basics shared by the advisor form and the no-JS form (own sid per form = R7 per form). */
	private static function form_basics( $return ) {
		$html  = '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$html .= '<input type="hidden" name="kc_return" value="' . esc_url( $return ) . '">';
		$html .= '<input type="hidden" name="kc_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">';
		$html .= '<input type="hidden" name="kc_sid" value="' . esc_attr( strtolower( wp_generate_password( 24, false, false ) ) ) . '">';
		$html .= '<div class="kco-hp" aria-hidden="true"><label>Nettside <input type="text" name="kc_website" tabindex="-1" autocomplete="off"></label></div>';
		return $html;
	}

	/** D11: packages on the page = picture + name only. No buttons, quantities or prices. */
	private static function package_gallery( array $packages ) {
		if ( ! $packages ) {
			return '';
		}
		ob_start();
		?>
		<section class="kc-section kc-packages kca-gallery" id="pakker" aria-labelledby="kca-gallery-title">
			<div class="ko-shell">
				<div class="kc-section-head">
					<p class="kc-section-label"><span></span> Våre pakker <span></span></p>
					<h2 id="kca-gallery-title">Pakkene</h2>
					<p>Velg anledning for å bestille – der velger dere pakker, antall og tillegg.</p>
				</div>
				<ul class="kca-gallery__grid">
					<?php foreach ( $packages as $product ) : ?>
						<?php $img = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'large' ) : ''; ?>
						<li class="kca-gallery__card<?php echo $img ? '' : ' is-empty'; ?>">
							<?php if ( $img ) : ?>
								<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" decoding="async">
							<?php endif; ?>
							<span class="kca-gallery__name"><?php echo esc_html( $product->get_name() ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Step 5 of the advisor: contact + pickup. Real form fields (server-rendered, keep values after errors). */
	private static function contact_fields( array $old, array $errors ) {
		ob_start();
		?>
		<?php if ( $errors ) : ?>
			<div class="kco-error kco-error--block" role="alert" tabindex="-1" id="kco-errors">
				<strong>Bestillingen ble ikke sendt.</strong>
				<ul>
					<?php foreach ( $errors as $message ) : ?>
						<li><?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<div class="kca-allergy" data-kca-allergy hidden></div>
		<fieldset class="kco-fieldset">
			<legend>Henting</legend>
			<p class="kco-pickup"><?php echo esc_html( KC_Core::setting( 'pickup_address' ) ); ?></p>
			<div class="kco-row">
				<?php echo self::field( 'date', 'Dato for henting', 'date', $old, $errors, self::date_attrs() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo self::time_field( $old, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<p class="kco-hint kca-pickup-hint" data-kca-pickup-hint hidden></p>
			<p class="kco-hint"><?php echo esc_html( self::lead_hint() ); ?></p>
		</fieldset>
		<fieldset class="kco-fieldset">
			<legend>Kontaktinformasjon</legend>
			<?php echo self::field( 'name', 'Navn', 'text', $old, $errors, array( 'autocomplete' => 'name', 'required' => 'required', 'maxlength' => '120' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="kco-row">
				<?php echo self::field( 'phone', 'Telefon', 'tel', $old, $errors, array( 'autocomplete' => 'tel', 'required' => 'required', 'maxlength' => '30' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo self::field( 'email', 'E-post', 'email', $old, $errors, array( 'autocomplete' => 'email', 'required' => 'required', 'maxlength' => '120' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php echo self::field( 'company', 'Firma (valgfritt)', 'text', $old, $errors, array( 'autocomplete' => 'organization', 'maxlength' => '120' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</fieldset>
		<fieldset class="kco-fieldset">
			<legend>Kommentar til Kebabish</legend>
			<?php echo self::field( 'note', 'Allergier, ønsker eller kommentar', 'textarea', $old, $errors, array( 'maxlength' => '1000', 'rows' => '5', 'aria-describedby' => 'kca-note-hint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="kco-hint" id="kca-note-hint">Fylt ut fra svarene dine. Du kan endre eller legge til tekst.</p>
		</fieldset>
		<p class="kc-form__note">Du betaler ikke nå. Når Kebabish har godkjent bestillingen, får du endelig pris og betalingslenke på e-post.</p>
		<?php
		return (string) ob_get_clean();
	}

	/** No-JS reserve (D11): the plain V1.1 order form. Only parsed by browsers without JavaScript. */
	private static function fallback_form( array $old, array $errors, array $qty, $return ) {
		$p = 'kcn-';
		ob_start();
		?>
		<form class="kco kco--plain" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php echo self::form_basics( $return ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="kc-form kco-form">
				<?php if ( $errors ) : ?>
					<div class="kco-error kco-error--block" role="alert">
						<strong>Bestillingen ble ikke sendt.</strong>
						<ul>
							<?php foreach ( $errors as $message ) : ?>
								<li><?php echo esc_html( $message ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<fieldset class="kco-fieldset">
					<legend>Pakker (antall personer)</legend>
					<p class="kco-hint"><?php echo esc_html( sprintf( 'Minimum %s totalt.', KC_Core::persons_label( (int) KC_Core::int_setting( 'min_persons' ) ) ) ); ?></p>
					<?php foreach ( KC_Catalog::packages() as $product ) : ?>
						<?php echo self::plain_qty( $product, (int) ( $qty[ $product->get_id() ] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endforeach; ?>
				</fieldset>
				<?php $addons = KC_Catalog::addons(); ?>
				<?php if ( $addons ) : ?>
					<fieldset class="kco-fieldset">
						<legend>Tillegg (valgfritt)</legend>
						<?php foreach ( $addons as $product ) : ?>
							<?php echo self::plain_qty( $product, (int) ( $qty[ $product->get_id() ] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<fieldset class="kco-fieldset">
					<legend>Anledning (valgfritt)</legend>
					<label class="kco-field" for="<?php echo esc_attr( $p ); ?>occasion"><span class="kco-field__label">Hva gjelder bestillingen?</span>
						<select id="<?php echo esc_attr( $p ); ?>occasion" name="kc_occasion">
							<option value="">Velg anledning</option>
							<?php foreach ( self::occasions() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $old['occasion'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</fieldset>
				<fieldset class="kco-fieldset">
					<legend>Henting</legend>
					<p class="kco-pickup"><?php echo esc_html( KC_Core::setting( 'pickup_address' ) ); ?></p>
					<?php echo self::field( 'date', 'Dato for henting', 'date', $old, $errors, self::date_attrs(), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::time_field( $old, $errors, $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</fieldset>
				<fieldset class="kco-fieldset">
					<legend>Kontaktinformasjon</legend>
					<?php echo self::field( 'name', 'Navn', 'text', $old, $errors, array( 'autocomplete' => 'name', 'required' => 'required', 'maxlength' => '120' ), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::field( 'phone', 'Telefon', 'tel', $old, $errors, array( 'autocomplete' => 'tel', 'required' => 'required', 'maxlength' => '30' ), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::field( 'email', 'E-post', 'email', $old, $errors, array( 'autocomplete' => 'email', 'required' => 'required', 'maxlength' => '120' ), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::field( 'company', 'Firma (valgfritt)', 'text', $old, $errors, array( 'autocomplete' => 'organization', 'maxlength' => '120' ), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::field( 'note', 'Allergier eller kommentar (valgfritt)', 'textarea', $old, $errors, array( 'maxlength' => '1000', 'rows' => '4' ), $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</fieldset>
				<button type="submit">Send bestilling</button>
				<p class="kc-form__note">Foreløpig pris beregnes når du sender. Du betaler ikke nå.</p>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private static function plain_qty( WC_Product $product, $qty ) {
		$unit = KC_Catalog::unit( $product );
		$id   = 'kcn-qty-' . (int) $product->get_id();
		$text = $product->get_name() . ' – ' . wp_strip_all_tags( wc_price( (float) $product->get_price() ) ) . ' ' . KC_Catalog::unit_label( $unit, 1, 'per' );
		return '<label class="kco-field kco-field--qty" for="' . esc_attr( $id ) . '"><span class="kco-field__label">' . esc_html( $text ) . '</span>'
			. '<input id="' . esc_attr( $id ) . '" type="number" inputmode="numeric" min="0" max="9999" step="1" name="kc_qty[' . esc_attr( $product->get_id() ) . ']" value="' . esc_attr( max( 0, (int) $qty ) ) . '"></label>';
	}

	private static function field( $key, $label, $type, array $old, array $errors, array $attrs = array(), $prefix = 'kco-' ) {
		$id    = $prefix . $key;
		$value = isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
		$err   = $errors[ $key ] ?? '';
		$extra = '';
		foreach ( $attrs as $attr => $val ) {
			$extra .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $val ) . '"';
		}
		if ( $err ) {
			$described = trim( ( $attrs['aria-describedby'] ?? '' ) . ' ' . $id . '-err' );
			$extra     = str_replace( ' aria-describedby="' . esc_attr( $attrs['aria-describedby'] ?? '' ) . '"', '', $extra );
			$extra    .= ' aria-invalid="true" aria-describedby="' . esc_attr( $described ) . '"';
		}

		$html  = '<label class="kco-field' . ( $err ? ' has-error' : '' ) . '" for="' . esc_attr( $id ) . '">';
		$html .= '<span class="kco-field__label">' . esc_html( $label ) . '</span>';
		if ( 'textarea' === $type ) {
			$html .= '<textarea id="' . esc_attr( $id ) . '" name="kc_' . esc_attr( $key ) . '"' . $extra . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			$html .= '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="kc_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"' . $extra . '>';
		}
		if ( $err ) {
			$html .= '<span class="kco-error" id="' . esc_attr( $id ) . '-err">' . esc_html( $err ) . '</span>';
		}
		return $html . '</label>';
	}

	private static function time_field( array $old, array $errors, $prefix = 'kco-' ) {
		$id    = $prefix . 'time';
		$value = (string) ( $old['time'] ?? '' );
		$err   = $errors['time'] ?? '';
		$html  = '<label class="kco-field' . ( $err ? ' has-error' : '' ) . '" for="' . esc_attr( $id ) . '">';
		$html .= '<span class="kco-field__label">Tidspunkt for henting</span>';
		$html .= '<select id="' . esc_attr( $id ) . '" name="kc_time" required' . ( $err ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-err"' : '' ) . '>';
		$html .= '<option value="">Velg hentetid</option>';
		foreach ( KC_Core::slots() as $slot ) {
			$html .= '<option value="' . esc_attr( $slot ) . '"' . selected( $value, $slot, false ) . '>' . esc_html( $slot ) . '</option>';
		}
		$html .= '</select>';
		if ( $err ) {
			$html .= '<span class="kco-error" id="' . esc_attr( $id ) . '-err">' . esc_html( $err ) . '</span>';
		}
		return $html . '</label>';
	}

	private static function date_attrs() {
		$now     = new DateTimeImmutable( 'now', wp_timezone() );
		$lead    = (int) KC_Core::setting( 'lead_time_hours', '0' );
		$horizon = max( 1, (int) KC_Core::setting( 'horizon_days', '60' ) );
		return array(
			'required' => 'required',
			'min'      => $now->modify( '+' . $lead . ' hours' )->format( 'Y-m-d' ),
			'max'      => $now->modify( '+' . $horizon . ' days' )->format( 'Y-m-d' ),
		);
	}

	private static function lead_hint() {
		$lead = (int) KC_Core::setting( 'lead_time_hours', '0' );
		if ( $lead <= 0 ) {
			return 'Velg dato og tidspunkt for henting.';
		}
		return sprintf( 'Bestill senest %d timer før henting.', $lead );
	}

	private static function render_closed() {
		return '<section class="kc-inquiry kco-closed" id="foresporsel"><div class="kc-inquiry__shade" aria-hidden="true"></div><div class="ko-shell"><p class="kc-kicker">Catering <span></span></p><h2>Bestilling åpner snart</h2><p>Nettbestilling av catering er ikke åpen ennå.</p></div></section>';
	}

	private static function render_pending() {
		wp_enqueue_style( 'kc-order' );
		return '<section class="kc-inquiry kco-done" id="foresporsel"><div class="kc-inquiry__shade" aria-hidden="true"></div><div class="ko-shell"><p class="kc-kicker">Bestilling <span></span></p><h2>Bestillingen din behandles</h2><p>Vi registrerer bestillingen akkurat nå. Ikke send den på nytt.</p><p><a href="" onclick="location.reload();return false;">Oppdater siden</a> om noen sekunder for å se ordrenummeret.</p></div></section>';
	}

	private static function render_done( array $done ) {
		wp_enqueue_style( 'kc-order' );
		ob_start();
		?>
		<section class="kc-inquiry kco-done" id="foresporsel" aria-labelledby="kco-done-title">
			<div class="kc-inquiry__shade" aria-hidden="true"></div>
			<div class="ko-shell">
				<p class="kc-kicker">Bestilling mottatt <span></span></p>
				<h2 id="kco-done-title" tabindex="-1">Takk – vi har mottatt bestillingen</h2>
				<?php if ( ! empty( $done['pickup'] ) ) : ?>
					<p class="kco-done-pickup">Hentetid: <strong><?php echo esc_html( $done['pickup'] ); ?></strong></p>
				<?php endif; ?>
				<?php if ( ! empty( $done['mailed'] ) ) : ?>
					<p>Ordrenummer <strong><?php echo esc_html( $done['number'] ?? '' ); ?></strong>. Vi har sendt en bekreftelse til <?php echo esc_html( $done['email'] ?? '' ); ?>.</p>
				<?php else : ?>
					<p>Ordrenummer <strong><?php echo esc_html( $done['number'] ?? '' ); ?></strong>. Bestillingen er registrert, men bekreftelsen på e-post kunne ikke sendes akkurat nå. Kebabish ser bestillingen og tar kontakt.</p>
				<?php endif; ?>
				<?php if ( ! empty( $done['needs'] ) && is_array( $done['needs'] ) ) : ?>
					<div class="kca-allergy kca-allergy--done">
						<?php foreach ( $done['needs'] as $line ) : ?>
							<p><?php echo esc_html( $line ); ?></p>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p>Kebabish går gjennom bestillingen. Når den er godkjent, får du endelig pris og betalingslenke på e-post. Du betaler ikke før da.</p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* ================================================================== */
	/* Submit                                                              */
	/* ================================================================== */

	public static function handle() {
		$return = isset( $_POST['kc_return'] ) ? esc_url_raw( wp_unslash( $_POST['kc_return'] ) ) : '';
		$return = wp_validate_redirect( $return, home_url( '/catering/' ) );

		if ( ! isset( $_POST['kc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kc_nonce'] ) ), self::NONCE ) ) {
			self::back( $return, array( 'form' => 'Skjemaet var utløpt. Last siden på nytt og prøv igjen.' ), array() );
		}

		// Honeypot: pretend success without creating anything.
		if ( ! empty( $_POST['kc_website'] ) ) {
			wp_safe_redirect( $return );
			exit;
		}

		$sid = isset( $_POST['kc_sid'] ) ? sanitize_key( wp_unslash( $_POST['kc_sid'] ) ) : '';
		if ( strlen( $sid ) < 16 ) {
			self::back( $return, array( 'form' => 'Skjemaet var ugyldig. Last siden på nytt og prøv igjen.' ), array() );
		}
		// R7: one form instance = one order. Atomic claim (INSERT IGNORE on unique option_name).
		if ( ! KC_Core::claim( 'kc_sub_' . $sid, 'pending' ) ) {
			$existing = (string) KC_Core::claim_value( 'kc_sub_' . $sid );
			if ( 0 === strpos( $existing, 'done:' ) ) {
				wp_safe_redirect( add_query_arg( 'kc_done', substr( $existing, 5 ), $return ) . '#foresporsel' );
				exit;
			}
			// Same submission still being processed: show a "behandles" state, never a fresh form.
			wp_safe_redirect( add_query_arg( 'kc_pending', $sid, $return ) . '#foresporsel' );
			exit;
		}
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'kc_cleanup_submission', array( $sid ) );

		if ( self::rate_limited() ) {
			self::back( $return, array( 'form' => 'For mange bestillinger fra samme nettverk. Prøv igjen om en time, eller kontakt oss direkte.' ), array() );
		}

		list( $open, , ) = KC_Core::intake_status();
		if ( ! $open ) {
			wp_safe_redirect( $return );
			exit;
		}

		$data = self::read_input();
		// Advisor answers are context only. Answers for another occasion than the one sent are dropped.
		if ( $data['advisor'] ) {
			if ( '' === $data['occasion'] ) {
				$data['occasion'] = $data['advisor']['occasion'];
			} elseif ( $data['advisor']['occasion'] !== $data['occasion'] ) {
				$data['advisor'] = null;
			}
		}
		$errors = array();
		$lines  = self::validate_lines( $data['qty'], $errors );
		self::validate_when( $data, $errors );
		// Campaign menus: pickup date must be inside each product's window (rechecked here, never trusted from the browser).
		if ( ! isset( $errors['date'] ) ) {
			foreach ( $lines as $line ) {
				if ( ! KC_Catalog::pickup_ok( $line['product'], $data['date'] ) ) {
					$label = KC_Catalog::campaign_label( $line['product'] );
					$errors['date'] = sprintf( '%s kan ikke hentes denne datoen.%s', $line['product']->get_name(), '' !== $label ? ' ' . $label . '.' : '' );
					break;
				}
			}
		}
		self::validate_contact( $data, $errors );
		if ( '' !== $data['occasion'] && ! array_key_exists( $data['occasion'], self::occasions() ) ) {
			$errors['occasion'] = 'Velg en gyldig anledning.';
		}

		$persons = 0;
		foreach ( $lines as $line ) {
			if ( 'package' === KC_Catalog::role( $line['product'] ) ) {
				$persons += (int) $line['qty'];
			}
		}
		if ( $lines && ! isset( $errors['lines'] ) ) {
			$min_persons = (int) KC_Core::int_setting( 'min_persons' );
			if ( $persons < $min_persons ) {
				$errors['lines'] = sprintf( 'Minste bestilling er %s totalt. Du har valgt %s.', KC_Core::persons_label( $min_persons ), KC_Core::persons_label( $persons ) );
			}
		}

		if ( $errors ) {
			KC_Core::release( 'kc_sub_' . $sid );
			self::back( $return, $errors, $data );
		}

		// Capacity (persons per pickup date) is reserved at MOTTATT. Serialize per date so two
		// simultaneous submissions cannot both pass the same remaining capacity.
		$lock = KC_Core::capacity_lock_name( $data['date'] );
		$lock_token = KC_Core::acquire_lock( $lock );
		if ( ! $lock_token ) {
			KC_Core::release( 'kc_sub_' . $sid );
			self::back( $return, array( 'form' => 'Mange bestillinger akkurat nå. Prøv igjen om et øyeblikk.' ), $data );
		}
		$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
		$booked   = self::persons_on( $data['date'] );
		if ( $booked + $persons > $capacity ) {
			KC_Core::release_lock( $lock, $lock_token );
			KC_Core::release( 'kc_sub_' . $sid );
			$free = max( 0, $capacity - $booked );
			self::back(
				$return,
				array( 'date' => 0 === $free ? 'Denne datoen er full. Velg en annen dato.' : sprintf( 'Denne datoen har bare plass til %s til. Velg en annen dato eller færre personer.', KC_Core::persons_label( $free ) ) ),
				$data
			);
		}

		$order = self::create_order( $lines, $data, $persons );
		KC_Core::release_lock( $lock, $lock_token );
		if ( is_wp_error( $order ) ) {
			KC_Core::release( 'kc_sub_' . $sid );
			self::back( $return, array( 'form' => 'Bestillingen kunne ikke lagres. Prøv igjen, eller kontakt oss direkte.' ), $data );
		}

		$order->update_meta_data( '_kc_submission', $sid );
		$order->save();
		self::count_submission();
		$mailed = KC_Emails::customer_received( $order );
		KC_Emails::operator_new( $order );

		// Receipt exists BEFORE the submission is marked done, so a parallel duplicate never sees
		// "done" without a receipt. Until then duplicates see the "behandles" state.
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient(
			'kc_done_' . $token,
			array(
				'number' => $order->get_order_number(),
				'email'  => $order->get_billing_email(),
				'mailed' => $mailed,
				'pickup' => KC_Core::event_datetime( $order ),
				'needs'  => self::needs_receipt( $data['advisor'] ),
			),
			DAY_IN_SECONDS
		);
		KC_Core::claim_update( 'kc_sub_' . $sid, 'done:' . $token );
		wp_safe_redirect( add_query_arg( 'kc_done', $token, $return ) . '#foresporsel' );
		exit;
	}

	/** D9: the customer sees their own needs on the receipt (plain lines, escaped on output). */
	private static function needs_receipt( $adv ) {
		$n   = is_array( $adv ) ? (array) ( $adv['needs'] ?? array() ) : array();
		$out = array();
		if ( isset( $n['allergy_text'] ) ) {
			$out[] = 'Ditt allergivalg: ' . $n['allergy_text'] . ( isset( $n['allergy_count'] ) ? ' – gjelder ' . (int) $n['allergy_count'] . ' gjester.' : '' );
			if ( ! empty( $n['cross_contact'] ) ) {
				$out[] = 'Du er bekymret for krysskontakt.';
			}
			$out[] = KC_Advisor::texts()['allergy'];
		}
		return $out;
	}

	public static function occasions() {
		return array(
			'office-friday' => 'Fredag på kontoret',
			'meeting-team' => 'Møte & team',
			'family-party' => 'Fest & familie',
		);
	}

	private static function read_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$qty = array();
		if ( isset( $_POST['kc_qty'] ) && is_array( $_POST['kc_qty'] ) ) {
			foreach ( wp_unslash( $_POST['kc_qty'] ) as $id => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$id = absint( $id );
				$n  = absint( $value );
				if ( $id && $n > 0 ) {
					$qty[ $id ] = min( $n, 9999 );
				}
			}
		}
		$text = function ( $key, $max ) {
			return isset( $_POST[ $key ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : '';
		};
		$data = array(
			'qty'     => $qty,
			'date'    => $text( 'kc_date', 10 ),
			'time'    => $text( 'kc_time', 5 ),
			'name'    => $text( 'kc_name', 120 ),
			'phone'   => $text( 'kc_phone', 30 ),
			'email'   => isset( $_POST['kc_email'] ) ? mb_substr( sanitize_email( wp_unslash( $_POST['kc_email'] ) ), 0, 120 ) : '',
			'company' => $text( 'kc_company', 120 ),
			'occasion' => $text( 'kc_occasion', 40 ),
			'note'    => isset( $_POST['kc_note'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['kc_note'] ) ), 0, 1000 ) : '',
			'advisor' => KC_Advisor::from_post(),
		);
		// phpcs:enable
		return $data;
	}

	/** @return array<int,array{product:WC_Product,qty:int}> */
	private static function validate_lines( array $qty, array &$errors ) {
		$lines       = array();
		$has_package = false;
		foreach ( $qty as $id => $n ) {
			$product = KC_Catalog::find( $id );
			if ( ! $product ) {
				$errors['lines'] = 'En av varene er ikke lenger tilgjengelig. Kontroller valget ditt.';
				continue;
			}
			$min = KC_Catalog::min_qty( $product );
			if ( $n < $min ) {
				$unit            = KC_Catalog::unit( $product );
				$errors['lines'] = sprintf( '%s krever minst %d %s.', $product->get_name(), $min, KC_Catalog::unit_label( $unit, $min ) );
				continue;
			}
			if ( 'package' === KC_Catalog::role( $product ) ) {
				$has_package = true;
			}
			$lines[] = array(
				'product' => $product,
				'qty'     => (int) $n,
			);
		}
		if ( ! $has_package && ! isset( $errors['lines'] ) ) {
			$errors['lines'] = 'Velg minst én pakke.';
		}
		return $lines;
	}

	private static function validate_when( array $data, array &$errors ) {
		$tz   = wp_timezone();
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $data['date'], $tz );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $data['date'] ) {
			$errors['date'] = 'Velg dato for henting.';
			return;
		}
		if ( ! in_array( $data['time'], KC_Core::slots(), true ) ) {
			$errors['time'] = 'Velg hentetid fra listen.';
			return;
		}

		$when    = new DateTimeImmutable( $data['date'] . ' ' . $data['time'], $tz );
		$now     = new DateTimeImmutable( 'now', $tz );
		$lead    = (int) KC_Core::setting( 'lead_time_hours', '0' );
		$horizon = max( 1, (int) KC_Core::setting( 'horizon_days', '60' ) );

		if ( $when < $now->modify( '+' . $lead . ' hours' ) ) {
			$errors['date'] = $lead > 0 ? sprintf( 'Hentetiden må være minst %d timer frem i tid.', $lead ) : 'Velg en hentetid frem i tid.';
			return;
		}
		if ( $when > $now->modify( '+' . $horizon . ' days' ) ) {
			$errors['date'] = sprintf( 'Vi tar imot bestillinger inntil %d dager frem.', $horizon );
			return;
		}
		$closed = array_map( 'strval', (array) get_option( 'kc_closed_weekdays', array() ) );
		if ( in_array( $when->format( 'N' ), $closed, true ) ) {
			$errors['date'] = 'Vi tar ikke imot catering denne ukedagen. Velg en annen dato.';
			return;
		}
	}

	private static function validate_contact( array $data, array &$errors ) {
		if ( mb_strlen( $data['name'] ) < 2 ) {
			$errors['name'] = 'Skriv inn navn.';
		}
		$digits = preg_replace( '/\D+/', '', $data['phone'] );
		if ( strlen( (string) $digits ) < 8 || strlen( (string) $digits ) > 15 ) {
			$errors['phone'] = 'Skriv inn et gyldig telefonnummer.';
		}
		if ( ! is_email( $data['email'] ) ) {
			$errors['email'] = 'Skriv inn en gyldig e-postadresse.';
		}
	}

	/** Persons already reserved on a pickup date (capacity unit = persons). */
	public static function persons_on( $date, $exclude_id = 0 ) {
		$sum = 0;
		foreach ( self::order_ids_on( $date ) as $id ) {
			if ( (int) $id === (int) $exclude_id ) {
				continue;
			}
			$order = wc_get_order( $id );
			if ( $order ) {
				$sum += (int) $order->get_meta( '_kc_persons' );
			}
		}
		return $sum;
	}

	/** Orders that occupy capacity on a pickup date. */
	public static function order_ids_on( $date ) {
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				// Everything except cancelled/refunded/trash keeps its reservation – incl. failed
				// (still payable while approval is active) and on-hold/pending (payment in progress).
				'status'     => array( KC_Core::STATUS_RECEIVED, KC_Core::STATUS_APPROVED, 'failed', 'on-hold', 'pending', 'processing', 'completed' ),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_kc_event_date',
						'value' => $date,
					),
				),
			)
		);
		return $ids;
	}


	/** @return WC_Order|WP_Error */
	private static function create_order( array $lines, array $data, $persons ) {
		try {
			$order = new WC_Order();
			$order->set_created_via( 'kebabish-catering' );
			$order->set_currency( get_woocommerce_currency() );
			$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
			$order->set_customer_id( 0 );

			foreach ( $lines as $line ) {
				$item_id = $order->add_product( $line['product'], $line['qty'] );
				$item    = $order->get_item( $item_id );
				if ( $item ) {
					$item->add_meta_data( '_kc_unit', KC_Catalog::unit( $line['product'] ), true );
					$item->add_meta_data( '_kc_role', KC_Catalog::role( $line['product'] ), true );
					$item->save();
				}
			}

			$parts = preg_split( '/\s+/', trim( $data['name'] ) );
			$last  = count( $parts ) > 1 ? array_pop( $parts ) : '';
			$order->set_billing_first_name( implode( ' ', $parts ) );
			$order->set_billing_last_name( $last );
			$order->set_billing_phone( $data['phone'] );
			$order->set_billing_email( $data['email'] );
			$order->set_billing_company( $data['company'] );
			$order->set_billing_country( 'NO' );
			$order->set_customer_note( $data['note'] );
			$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
			$order->set_customer_user_agent( wc_get_user_agent() );

			$order->update_meta_data( '_kebabish_catering', 'yes' );
			$order->update_meta_data( '_kc_schema', KC_Core::SCHEMA );
			$order->update_meta_data( '_kc_event_date', $data['date'] );
			$order->update_meta_data( '_kc_event_time', $data['time'] );
			if ( '' !== $data['occasion'] ) {
				$order->update_meta_data( '_kc_occasion', $data['occasion'] );
			}
			$order->update_meta_data( '_kc_fulfillment', 'pickup' );
			$order->update_meta_data( '_kc_persons', (int) $persons );
			$order->update_meta_data( '_kc_revision', 0 );
			$order->update_meta_data( '_kc_approval_active', 'no' );
			$order->update_meta_data( '_kc_pickup_address', KC_Core::setting( 'pickup_address' ) );
			$order->update_meta_data( '_kebabish_company', $data['company'] );

			$order->calculate_totals( wc_tax_enabled() );
			if ( ! empty( $data['advisor'] ) ) {
				$order->update_meta_data( KC_Advisor::META, KC_Advisor::build_meta( $data['advisor'], $order ) );
			}
			$order->update_meta_data( '_kc_prelim_total', wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) );
			$order->set_status( KC_Core::STATUS_RECEIVED, 'Catering: bestilling mottatt fra nettsiden.' );
			$order->save();
			return $order;
		} catch ( Exception $e ) {
			wc_get_logger()->error( 'Catering order failed: ' . $e->getMessage(), array( 'source' => 'kebabish-catering' ) );
			return new WP_Error( 'kc_order_failed', $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Redirect with state / rate limit                                    */
	/* ------------------------------------------------------------------ */

	private static function back( $return, array $errors, array $data ) {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient(
			'kc_state_' . $token,
			array(
				'errors' => $errors,
				'data'   => $data,
			),
			self::STATE_TTL
		);
		wp_safe_redirect( add_query_arg( 'kc', $token, remove_query_arg( array( 'kc', 'kc_done', 'occasion' ), $return ) ) . '#foresporsel' );
		exit;
	}

	private static function ip_key() {
		return 'kc_rl_' . md5( (string) WC_Geolocation::get_ip_address() . wp_salt( 'nonce' ) );
	}

	private static function rate_limited() {
		return (int) get_transient( self::ip_key() ) >= self::RATE_LIMIT;
	}

	private static function count_submission() {
		$key = self::ip_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
}
