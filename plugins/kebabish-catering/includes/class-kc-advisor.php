<?php
/**
 * Kebabish Catering – occasion advisor V2 (architecture 1NsNr-t92lFdPpPzzx-M5Mh_vlhilC9g5, Saulo D1–D13).
 *
 * - Packages are neutral base food (no package recommendation).
 * - Occasion answers only steer ADD-ON suggestions (max 3, each with a reason). Data driven:
 *   product meta _kc_tags / _kc_suggest / _kc_group set in WP-admin or by CSV import.
 * - Nothing is added automatically. Order lines stay the only truth for products, quantities and price.
 * - The server never trusts client suggestions or prices; it only stores whitelisted answers as context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Advisor {

	const SCHEMA   = '2.0';
	const META     = '_kc_advisor';
	const MAX_INT  = 999;
	const TEXT_MAX = 300;

	/** Product tags the engine understands (claims Kebabish has approved for that dish). */
	const TAGS = array(
		'vegetarian' => 'Vegetar',
		'mild'       => 'Mild',
		'kids'       => 'Barnevennlig',
		'protein'    => 'Ekstra protein',
		'finger'     => 'Fingermat',
		'bread'      => 'Brød',
		'rice'       => 'Ris',
		'sauce'      => 'Saus / chutney',
		'salad'      => 'Salat',
		'dessert'    => 'Dessert',
		'drink'      => 'Drikke',
		'party'      => 'Fest',
		'grill'      => 'Grill',
		'curry'      => 'Curry',
	);

	/** Suggested quantity rule per product. */
	const SUGGEST = array(
		'guest'  => 'Per gjest',
		'guest4' => 'Per 4 gjester',
		'group'  => 'Per gruppe (f.eks. antall vegetarianere)',
		'order'  => 'Én per bestilling',
		'none'   => 'Foreslås ikke automatisk',
	);

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ), 20 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ), 20 );
	}

	/* ================================================================== */
	/* Configuration: questions per occasion + what each answer leads to   */
	/* ================================================================== */

	public static function break_times() {
		$out = array();
		for ( $m = 8 * 60; $m <= 20 * 60; $m += 30 ) {
			$t         = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
			$out[ $t ] = $t;
		}
		$out['unknown'] = 'Vet ikke ennå';
		return $out;
	}

	public static function config() {
		$needs = array(
			'vegetarian' => 'Vegetar',
			'mild'       => 'Mild mat',
			'allergies'  => 'Allergier / andre behov',
		);
		return array(
			'office-friday' => array(
				'label' => 'Fredag på kontoret',
				'tone'  => 'office',
				'intro' => 'Tre raske spørsmål, så setter vi sammen en enkel fredagslunsj.',
				'steps' => array(
					array( 'id' => 'count', 'type' => 'number', 'label' => 'Hvor mange kollegaer?', 'hint' => 'Omtrentlig holder. Du velger antall porsjoner selv.', 'min' => 1, 'required' => true ),
					array( 'id' => 'priority', 'type' => 'single', 'label' => 'Hva er viktigst?', 'options' => array( 'variety' => 'Variasjon', 'filling' => 'Mettende mat', 'easy' => 'Enkel organisering', 'none' => 'Ingen preferanse' ) ),
					array( 'id' => 'needs', 'type' => 'multi', 'label' => 'Spesielle matbehov?', 'options' => $needs + array( 'none' => 'Ingen kjente' ), 'exclusive' => array( 'none' ) ),
				),
				// answer value => intents (tags in priority order, reason shown to the customer)
				'intents' => array(
					'variety' => array( array( 'tags' => array( 'sauce' ), 'reason' => 'Dere ønsket variasjon' ) ),
					'filling' => array( array( 'tags' => array( 'protein' ), 'reason' => 'Dere ønsket mettende mat' ), array( 'tags' => array( 'bread', 'rice' ), 'reason' => 'Dere ønsket mettende mat' ) ),
					'easy'    => array( array( 'tags' => array( 'drink' ), 'reason' => 'Enkelt: drikke til alle' ) ),
				),
			),
			'meeting-team'  => array(
				'label' => 'Møte & team',
				'tone'  => 'meeting',
				'intro' => 'Tre korte spørsmål for en ryddig matløsning til møtet.',
				'steps' => array(
					array( 'id' => 'count', 'type' => 'number', 'label' => 'Hvor mange deltakere?', 'hint' => 'Omtrentlig holder. Du velger antall porsjoner selv.', 'min' => 1, 'required' => true ),
					array( 'id' => 'break_time', 'type' => 'time', 'label' => 'Når er matpausen?', 'hint' => 'Vi foreslår en hentetid litt før pausen. Du kan endre den.', 'options' => self::break_times() ),
					array( 'id' => 'priority', 'type' => 'multi', 'label' => 'Hva er viktig for dere?', 'options' => array( 'easy' => 'Enkel servering', 'variety' => 'Variasjon' ) + $needs, 'exclusive' => array() ),
				),
				'intents' => array(
					'easy'    => array( array( 'tags' => array( 'finger' ), 'reason' => 'Lett å servere i pausen' ) ),
					'variety' => array( array( 'tags' => array( 'sauce', 'finger' ), 'reason' => 'Dere ønsket variasjon' ) ),
				),
			),
			'family-party'  => array(
				'label' => 'Fest & familie',
				'tone'  => 'family',
				'intro' => 'Fortell litt om selskapet, så hjelper vi dere å velge.',
				'steps' => array(
					array( 'id' => 'celebration', 'type' => 'single', 'label' => 'Hva skal feires?', 'options' => array( 'birthday' => 'Bursdag', 'eid' => 'Eid / høytid', 'family' => 'Familiesamling', 'other' => 'Annet', 'undisclosed' => 'Ønsker ikke å oppgi' ) ),
					array( 'id' => 'guests', 'type' => 'pair', 'label' => 'Hvor mange gjester?', 'hint' => 'Bare for å hjelpe dere. Dere velger selv antall porsjoner.', 'fields' => array( 'adults' => array( 'label' => 'Voksne', 'min' => 0 ), 'children' => array( 'label' => 'Barn', 'min' => 0 ) ), 'required' => true ),
					array( 'id' => 'prefs', 'type' => 'multi', 'label' => 'Matpreferanser?', 'options' => array( 'variety' => 'Variasjon' ) + $needs + array( 'none' => 'Ingen preferanse' ), 'exclusive' => array( 'none' ) ),
				),
				'intents' => array(
					'birthday' => array( array( 'tags' => array( 'dessert' ), 'reason' => 'Dere feirer bursdag' ) ),
					'eid'      => array( array( 'tags' => array( 'dessert' ), 'reason' => 'Dere feirer Eid' ) ),
					'family'   => array( array( 'tags' => array( 'dessert' ), 'reason' => 'Til familiesamlingen' ) ),
					'other'    => array( array( 'tags' => array( 'dessert' ), 'reason' => 'Til feiringen' ) ),
					'variety'  => array( array( 'tags' => array( 'sauce' ), 'reason' => 'Dere ønsket variasjon' ) ),
				),
			),
		);
	}

	/** Fields revealed on the same screen when a need is ticked. */
	public static function need_fields() {
		return array(
			'vegetarian' => array(
				'veg_count' => array( 'type' => 'count', 'label' => 'Hvor mange spiser vegetar?', 'required' => false ),
			),
			'allergies'  => array(
				'allergy_text'  => array( 'type' => 'text', 'label' => 'Hvilke allergier eller behov?', 'hint' => 'Kort, f.eks. «nøtter» eller «glutenfri». Maks 300 tegn.', 'required' => true ),
				'allergy_count' => array( 'type' => 'count', 'label' => 'Hvor mange gjester gjelder det?', 'required' => true ),
				'cross_contact' => array( 'type' => 'flag', 'label' => 'Vi er bekymret for krysskontakt (spor av allergener)' ),
			),
		);
	}

	public static function texts() {
		return array(
			'allergy'    => 'Allergitilpasning er en forespørsel. Kebabish bekrefter før bestillingen godkjennes.',
			'veg_none'   => 'Ingen vegetarretter er publisert i nettbestillingen ennå. Skriv behovet i kommentaren, så avklarer Kebabish.',
			'mild_none'  => 'Ingen retter er merket mild ennå. Ønsket blir med i kommentaren.',
			'general'    => 'Tilbehør til maten',
			'note'       => array(
				'mild'          => 'Ønsker mild mat.',
				'veg'           => 'Vegetar: %d personer.',
				'veg_unknown'   => 'Noen spiser vegetar.',
				'allergy'       => 'Allergi/behov: %s',
				'allergy_count' => ' – gjelder %d gjester.',
				'cross'         => 'Bekymret for krysskontakt.',
				'break'         => 'Matpause kl. %s.',
				'children'      => 'Barn i selskapet: %d.',
			),
		);
	}

	/* ================================================================== */
	/* Catalog bridge (WooCommerce is the source of truth)                 */
	/* ================================================================== */

	public static function product_tags( $product ) {
		$raw  = $product->get_meta( '_kc_tags' );
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$list = array_map( 'sanitize_key', array_map( 'trim', $list ) );
		return array_values( array_intersect( array_unique( $list ), array_keys( self::TAGS ) ) );
	}

	public static function product_rule( $product ) {
		$r = sanitize_key( (string) $product->get_meta( '_kc_suggest' ) );
		return isset( self::SUGGEST[ $r ] ) ? $r : 'none';
	}

	private static function row( $p ) {
		$unit  = KC_Catalog::unit( $p );
		$img   = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'medium_large' ) : '';
		$group = trim( wp_strip_all_tags( (string) $p->get_meta( '_kc_group' ) ) );
		return array(
			'id'    => (int) $p->get_id(),
			'name'  => $p->get_name(),
			'price' => (float) $p->get_price(),
			'unit1' => KC_Catalog::unit_label( $unit, 1 ),
			'unitN' => KC_Catalog::unit_label( $unit, 2 ),
			'per'   => KC_Catalog::unit_label( $unit, 1, 'per' ),
			'min'   => KC_Catalog::min_qty( $p ),
			'desc'  => mb_substr( trim( wp_strip_all_tags( (string) $p->get_short_description() ) ), 0, 220 ),
			'img'   => $img ? $img : '',
			'tags'  => self::product_tags( $p ),
			'rule'  => self::product_rule( $p ),
			'group' => '' !== $group ? mb_substr( $group, 0, 40 ) : 'Annet',
		);
	}

	public static function catalog() {
		$out = array( 'packages' => array(), 'addons' => array() );
		foreach ( KC_Catalog::packages() as $p ) {
			$out['packages'][] = self::row( $p );
		}
		foreach ( KC_Catalog::addons() as $p ) {
			$out['addons'][] = self::row( $p );
		}
		return $out;
	}

	/* ================================================================== */
	/* Input (server never trusts the client: whitelist + clamp)           */
	/* ================================================================== */

	public static function from_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified in KC_Intake::handle(); sanitized below.
		$raw = isset( $_POST['kc_adv'] ) && is_array( $_POST['kc_adv'] ) ? wp_unslash( $_POST['kc_adv'] ) : null;
		return self::sanitize( $raw );
	}

	public static function sanitize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$raw      = array_filter( $raw, 'is_scalar' );
		$occasion = isset( $raw['occasion'] ) ? sanitize_key( (string) $raw['occasion'] ) : '';
		$config   = self::config();
		if ( ! isset( $config[ $occasion ] ) ) {
			return null;
		}
		$answers = array();
		foreach ( $config[ $occasion ]['steps'] as $step ) {
			$id = $step['id'];
			switch ( $step['type'] ) {
				case 'number':
					if ( isset( $raw[ $id ] ) && '' !== (string) $raw[ $id ] ) {
						$answers[ $id ] = min( self::MAX_INT, max( (int) $step['min'], absint( $raw[ $id ] ) ) );
					}
					break;
				case 'pair':
					foreach ( $step['fields'] as $fid => $f ) {
						if ( isset( $raw[ $fid ] ) && '' !== (string) $raw[ $fid ] ) {
							$answers[ $fid ] = min( self::MAX_INT, max( (int) $f['min'], absint( $raw[ $fid ] ) ) );
						}
					}
					break;
				case 'single':
				case 'time':
					$v = isset( $raw[ $id ] ) ? (string) $raw[ $id ] : '';
					if ( isset( $step['options'][ $v ] ) ) {
						$answers[ $id ] = $v;
					}
					break;
				case 'multi':
					$keep = array();
					foreach ( array_slice( explode( ',', isset( $raw[ $id ] ) ? (string) $raw[ $id ] : '' ), 0, 8 ) as $v ) {
						$v = sanitize_key( $v );
						if ( isset( $step['options'][ $v ] ) && ! in_array( $v, $keep, true ) ) {
							$keep[] = $v;
						}
					}
					$excl = array_values( array_intersect( $keep, (array) ( $step['exclusive'] ?? array() ) ) );
					if ( $excl ) {
						$keep = array( $excl[0] );
					}
					if ( $keep ) {
						$answers[ $id ] = $keep;
					}
					break;
			}
		}
		if ( ! $answers ) {
			return null;
		}
		$out = array( 'occasion' => $occasion, 'answers' => $answers );

		// Need details only when their trigger is selected.
		$selected = array();
		foreach ( $answers as $v ) {
			if ( is_array( $v ) ) {
				$selected = array_merge( $selected, $v );
			}
		}
		$needs = array();
		foreach ( self::need_fields() as $trigger => $fields ) {
			if ( ! in_array( $trigger, $selected, true ) ) {
				continue;
			}
			foreach ( $fields as $key => $f ) {
				$val = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
				if ( 'text' === $f['type'] ) {
					$val = trim( mb_substr( sanitize_textarea_field( $val ), 0, self::TEXT_MAX ) );
					if ( '' !== $val ) {
						$needs[ $key ] = $val;
					}
				} elseif ( 'count' === $f['type'] ) {
					if ( absint( $val ) > 0 ) {
						$needs[ $key ] = min( self::MAX_INT, absint( $val ) );
					}
				} elseif ( 'flag' === $f['type'] && '1' === $val ) {
					$needs[ $key ] = 1;
				}
			}
		}
		if ( $needs ) {
			$out['needs'] = $needs;
		}

		// Upsell measurement: which add-ons were shown as suggestions (ids only, max 20).
		$shown = array();
		foreach ( array_slice( explode( ',', isset( $raw['shown'] ) ? (string) $raw['shown'] : '' ), 0, 20 ) as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $shown, true ) ) {
				$shown[] = $id;
			}
		}
		if ( $shown ) {
			$out['shown'] = $shown;
		}
		return $out;
	}

	/** Meta stored on the order. Selected/value computed from real (server-priced) order lines. */
	public static function build_meta( array $adv, WC_Order $order ) {
		$shown    = array_values( array_filter( (array) ( $adv['shown'] ?? array() ), function ( $id ) {
			$p = KC_Catalog::find( $id );
			return $p && 'addon' === KC_Catalog::role( $p );
		} ) );
		$selected = array();
		$value    = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$pid = (int) $item->get_product_id();
			if ( in_array( $pid, $shown, true ) ) {
				$selected[] = $pid;
				$value     += (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}
		return array(
			'v'        => self::SCHEMA,
			'occasion' => $adv['occasion'],
			'answers'  => $adv['answers'],
			'needs'    => (array) ( $adv['needs'] ?? array() ),
			'upsell'   => array(
				'shown'    => $shown,
				'selected' => $selected,
				'value'    => round( $value, 2 ),
			),
		);
	}

	/* ================================================================== */
	/* Rendering                                                           */
	/* ================================================================== */

	public static function hidden_fields( $adv ) {
		$html = '<div data-kca-fields hidden>';
		if ( is_array( $adv ) && isset( $adv['occasion'], $adv['answers'] ) ) {
			$all = array_merge( array( 'occasion' => $adv['occasion'] ), $adv['answers'], (array) ( $adv['needs'] ?? array() ) );
			if ( ! empty( $adv['shown'] ) ) {
				$all['shown'] = $adv['shown'];
			}
			foreach ( $all as $k => $v ) {
				$html .= '<input type="hidden" name="kc_adv[' . esc_attr( $k ) . ']" value="' . esc_attr( is_array( $v ) ? implode( ',', $v ) : (string) $v ) . '">';
			}
		}
		return $html . '</div>';
	}

	public static function client_config() {
		return array(
			'v'          => self::SCHEMA,
			'occasions'  => self::config(),
			'needFields' => self::need_fields(),
			'catalog'    => self::catalog(),
			'texts'      => self::texts(),
			'minPersons' => (int) KC_Core::int_setting( 'min_persons' ),
			'slots'      => array_values( KC_Core::slots() ),
			'breakLead'  => 30,
			'textMax'    => self::TEXT_MAX,
		);
	}

	/** Dialog shell. Steps 1–4 are drawn by kc-advisor.js; step 5 (contact) is server-rendered form fields. */
	public static function render_dialog( $contact_html, array $boot = array() ) {
		ob_start();
		?>
		<dialog class="kca" id="kca" aria-labelledby="kca-title" data-kca-config="<?php echo esc_attr( wp_json_encode( self::client_config() ) ); ?>" data-kca-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
			<div class="kca__sheet">
				<header class="kca__head">
					<p class="kca__kicker" data-kca-kicker></p>
					<h2 class="kca__title" id="kca-title" tabindex="-1" data-kca-title>Bestill catering</h2>
					<ol class="kca__steps" data-kca-steps aria-label="Fremdrift"></ol>
					<button type="button" class="kca__close" data-kca-close aria-label="Lukk">×</button>
				</header>
				<div class="kca__body" data-kca-body></div>
				<div class="kca__body kca__contact" data-kca-contact hidden><?php echo $contact_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped field helpers. ?></div>
				<footer class="kca__foot">
					<button type="button" class="kca__btn kca__btn--ghost" data-kca-back>Tilbake</button>
					<span class="kca__total" data-kca-total aria-live="polite"></span>
					<button type="button" class="kca__btn kca__btn--primary" data-kca-next>Fortsett</button>
				</footer>
			</div>
		</dialog>
		<?php
		return (string) ob_get_clean();
	}

	/* ================================================================== */
	/* Operator box / operator e-mail                                      */
	/* ================================================================== */

	public static function summary_rows( WC_Order $order ) {
		$meta = $order->get_meta( self::META );
		if ( ! is_array( $meta ) || empty( $meta['occasion'] ) ) {
			return array();
		}
		$config = self::config();
		if ( ! isset( $config[ $meta['occasion'] ] ) ) {
			return array();
		}
		$rows = array( 'Anledning' => $config[ $meta['occasion'] ]['label'] );
		foreach ( $config[ $meta['occasion'] ]['steps'] as $step ) {
			if ( 'pair' === $step['type'] ) {
				$parts = array();
				foreach ( $step['fields'] as $fid => $f ) {
					if ( isset( $meta['answers'][ $fid ] ) ) {
						$parts[] = $f['label'] . ': ' . (int) $meta['answers'][ $fid ];
					}
				}
				if ( $parts ) {
					$rows[ $step['label'] ] = implode( ', ', $parts );
				}
				continue;
			}
			if ( ! isset( $meta['answers'][ $step['id'] ] ) ) {
				continue;
			}
			$val = $meta['answers'][ $step['id'] ];
			if ( 'number' === $step['type'] ) {
				$rows[ $step['label'] ] = (string) (int) $val;
				continue;
			}
			$labels = array();
			foreach ( (array) $val as $v ) {
				if ( isset( $step['options'][ $v ] ) ) {
					$labels[] = $step['options'][ $v ];
				}
			}
			if ( $labels ) {
				$rows[ $step['label'] ] = implode( ', ', $labels );
			}
		}
		$n = (array) ( $meta['needs'] ?? array() );
		if ( isset( $n['veg_count'] ) ) {
			$rows['Antall vegetar'] = (string) (int) $n['veg_count'];
		}
		if ( isset( $n['allergy_text'] ) || isset( $n['allergy_count'] ) ) {
			$rows['Allergier / behov'] = ( $n['allergy_text'] ?? 'Ikke beskrevet' ) . ( isset( $n['allergy_count'] ) ? ' (gjelder ' . (int) $n['allergy_count'] . ' gjester)' : '' );
			$rows['Krysskontakt']      = ! empty( $n['cross_contact'] ) ? 'Kunden er bekymret' : 'Ikke oppgitt';
			$rows['Merk']              = 'Allergitilpasning må bekreftes med kunden før godkjenning.';
		}
		$u = (array) ( $meta['upsell'] ?? array() );
		if ( ! empty( $u['shown'] ) ) {
			$rows['Tilleggsforslag'] = count( (array) $u['shown'] ) . ' vist, ' . count( (array) ( $u['selected'] ?? array() ) ) . ' valgt (' . wp_strip_all_tags( wc_price( (float) ( $u['value'] ?? 0 ) ) ) . ')';
		}
		return $rows;
	}

	public static function operator_box( WC_Order $order ) {
		$rows = self::summary_rows( $order );
		if ( ! $rows ) {
			return '';
		}
		$html = '<div style="margin:0 0 18px;padding:12px 14px;border-left:3px solid #C69A50;background:#faf6ee"><p style="margin:0 0 6px"><strong>Veiledning (kundens svar)</strong></p><dl style="display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:0">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt style="font-weight:600">' . esc_html( $label ) . '</dt><dd style="margin:0">' . esc_html( $value ) . '</dd>';
		}
		return $html . '</dl></div>';
	}

	public static function email_block( WC_Order $order ) {
		$rows = self::summary_rows( $order );
		if ( ! $rows ) {
			return '';
		}
		$html = '<h3 style="margin:24px 0 8px">Veiledning (kundens svar)</h3><table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th style="padding:6px 10px 6px 0;text-align:left;vertical-align:top;width:38%">' . esc_html( $label ) . '</th><td style="padding:6px 0;vertical-align:top">' . esc_html( $value ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/* ================================================================== */
	/* Product admin fields                                                */
	/* ================================================================== */

	public static function product_fields() {
		global $product_object;
		$p = $product_object instanceof WC_Product ? $product_object : null;
		wp_nonce_field( 'kc_save_advisor', 'kc_advisor_nonce' );
		echo '<div class="options_group kc-advisor-fields"><p class="form-field"><strong>Catering – veileder</strong><br><span class="description">Merker styrer hvilke tillegg veilederen foreslår. Sett bare merker Kebabish står inne for.</span></p>';
		woocommerce_wp_text_input(
			array(
				'id'          => '_kc_tags',
				'label'       => 'Merker',
				'value'       => $p ? implode( ',', self::product_tags( $p ) ) : '',
				'desc_tip'    => true,
				'description' => 'Kommaseparert: ' . implode( ', ', array_keys( self::TAGS ) ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_kc_suggest',
				'label'   => 'Forslag antall',
				'value'   => $p ? self::product_rule( $p ) : 'none',
				'options' => self::SUGGEST,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_kc_group',
				'label'       => 'Menykategori',
				'value'       => $p ? (string) $p->get_meta( '_kc_group' ) : '',
				'desc_tip'    => true,
				'description' => 'Gruppe i «Se hele tilleggsmenyen», f.eks. Vegetar, Dessert, Drikke.',
			)
		);
		echo '</div>';
	}

	public static function save_product_fields( $product ) {
		if ( ! isset( $_POST['kc_advisor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kc_advisor_nonce'] ) ), 'kc_save_advisor' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$tags = isset( $_POST['_kc_tags'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['_kc_tags'] ) ) ) : array();
		$tags = array_values( array_intersect( array_unique( array_map( 'sanitize_key', array_map( 'trim', $tags ) ) ), array_keys( self::TAGS ) ) );
		$rule = isset( $_POST['_kc_suggest'] ) ? sanitize_key( wp_unslash( $_POST['_kc_suggest'] ) ) : 'none';
		$grp  = isset( $_POST['_kc_group'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['_kc_group'] ) ), 0, 40 ) : '';
		$product->update_meta_data( '_kc_tags', implode( ',', $tags ) );
		$product->update_meta_data( '_kc_suggest', isset( self::SUGGEST[ $rule ] ) ? $rule : 'none' );
		$product->update_meta_data( '_kc_group', $grp );
	}
}
