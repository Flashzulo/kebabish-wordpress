<?php
/**
 * WooCommerce → Innstillinger → Catering.
 * Operational values live here, not in code. Missing values keep intake closed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Settings {

	const TAB = 'kebabish_catering';

	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( __CLASS__, 'save' ) );
	}

	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = 'Catering';
		return $tabs;
	}

	public static function fields() {
		$weekdays = array(
			'1' => 'Mandag',
			'2' => 'Tirsdag',
			'3' => 'Onsdag',
			'4' => 'Torsdag',
			'5' => 'Fredag',
			'6' => 'Lørdag',
			'7' => 'Søndag',
		);
		return array(
			array(
				'title' => 'Cateringbestilling',
				'type'  => 'title',
				'desc'  => 'Bestilling er stengt til alle obligatoriske felt er fylt ut og minst én pakke finnes. Verdiene vises for kunden – fyll inn kun bekreftede tall.',
				'id'    => 'kc_section_main',
			),
			array(
				'title'   => 'Ta imot bestillinger',
				'id'      => 'kc_intake_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => 'Åpne bestillingsskjemaet på /catering',
			),
			array(
				'title'   => 'Testmodus-banner',
				'id'      => 'kc_test_banner',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Vis «Testbestilling» øverst i skjemaet',
			),
			array( 'type' => 'sectionend', 'id' => 'kc_section_main' ),

			array(
				'title' => 'Varsler',
				'type'  => 'title',
				'id'    => 'kc_section_mail',
			),
			array(
				'title'       => 'Operatør-e-post (obligatorisk)',
				'id'          => 'kc_operator_email',
				'type'        => 'email',
				'placeholder' => 'catering@…',
				'desc_tip'    => 'Får beskjed om nye og betalte cateringordrer.',
			),
			array(
				'title'    => 'Avsenderadresse',
				'id'       => 'kc_from_email',
				'type'     => 'email',
				'desc_tip' => 'Avsender på cateringe-poster. Tom = WooCommerce sin avsender.',
			),
			array(
				'title'    => 'Avsendernavn',
				'id'       => 'kc_from_name',
				'type'     => 'text',
				'default'  => 'Kebabish Oslo',
			),
			array( 'type' => 'sectionend', 'id' => 'kc_section_mail' ),

			array(
				'title' => 'Henting og kapasitet',
				'type'  => 'title',
				'desc'  => 'V1 tilbyr kun henting.',
				'id'    => 'kc_section_ops',
			),
			array(
				'title' => 'Henteadresse (obligatorisk)',
				'id'    => 'kc_pickup_address',
				'type'  => 'text',
			),
			array(
				'title'             => 'Minimum personer per bestilling (obligatorisk)',
				'id'                => 'kc_min_persons',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
				'desc_tip'          => 'Summen av personer i alle pakker. Tillegg teller ikke.',
			),
			array(
				'title'             => 'Ledetid, timer (obligatorisk)',
				'id'                => 'kc_lead_time_hours',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				'desc_tip'          => 'Hvor lang tid før henting bestillingen senest må komme inn.',
			),
			array(
				'title'             => 'Kapasitet, personer per dag (obligatorisk)',
				'id'                => 'kc_daily_capacity',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
				'desc_tip'          => 'Reserveres ved innsending (MOTTATT). Teller mottatte, godkjente, betalte og fullførte ordrer for hentedatoen. Avslått/avbrutt frigjør plass.',
			),
			array(
				'title'       => 'Hentetid fra (obligatorisk)',
				'id'          => 'kc_pickup_start',
				'type'        => 'text',
				'placeholder' => '11:00',
			),
			array(
				'title'       => 'Hentetid til (obligatorisk)',
				'id'          => 'kc_pickup_end',
				'type'        => 'text',
				'placeholder' => '20:00',
			),
			array(
				'title'   => 'Intervall for hentetid',
				'id'      => 'kc_slot_minutes',
				'type'    => 'select',
				'default' => '30',
				'options' => array(
					'15' => '15 minutter',
					'30' => '30 minutter',
					'60' => '60 minutter',
				),
			),
			array(
				'title'   => 'Stengte ukedager',
				'id'      => 'kc_closed_weekdays',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $weekdays,
				'default' => array(),
			),
			array(
				'title'             => 'Hvor langt frem kan man bestille, dager',
				'id'                => 'kc_horizon_days',
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
			),
			array( 'type' => 'sectionend', 'id' => 'kc_section_ops' ),
		);
	}

	public static function render() {
		list( $open, $missing, $enabled ) = KC_Core::intake_status();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$msg = isset( $_GET['kc_msg'] ) ? sanitize_key( wp_unslash( $_GET['kc_msg'] ) ) : '';
		if ( 'seeded' === $msg ) {
			echo '<div class="notice notice-success"><p>Testkatalog opprettet (6 produkter merket [TEST]).</p></div>';
		} elseif ( 'deleted' === $msg ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$n = isset( $_GET['kc_n'] ) ? absint( $_GET['kc_n'] ) : 0;
			echo '<div class="notice notice-success"><p>' . esc_html( $n ) . ' testprodukter flyttet til papirkurven.</p></div>';
		}

		if ( $open ) {
			echo '<div class="notice notice-success inline"><p><strong>Bestilling er åpen.</strong></p></div>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>Bestilling er stengt.</strong> ';
			if ( ! $enabled ) {
				echo 'Avkrysning «Ta imot bestillinger» er av. ';
			}
			if ( $missing ) {
				echo 'Mangler eller ugyldig: ' . esc_html( implode( '; ', $missing ) ) . '.';
			}
			echo '</p></div>';
		}

		woocommerce_admin_fields( self::fields() );

		$seed = wp_nonce_url( admin_url( 'admin-post.php?action=kc_seed_test_catalog' ), 'kc_seed_test_catalog' );
		$del  = wp_nonce_url( admin_url( 'admin-post.php?action=kc_delete_test_catalog' ), 'kc_delete_test_catalog' );
		$tests = KC_Catalog::test_products();
		echo '<h2>Testkatalog</h2><p>Oppretter 3 pakker og 3 tillegg merket [TEST] i kategorien Catering. Skal fjernes før publisering.</p>';
		echo '<p>«Fjern testkatalog» flytter bare produkter til papirkurven som både er merket «Testprodukt» og fortsatt heter noe med [TEST]. Produkter som er omdøpt til ekte navn, røres ikke.</p>';
		if ( $tests ) {
			echo '<p><strong>Blir flyttet til papirkurven:</strong> ' . esc_html( implode( ', ', array_map( function ( $p ) { return $p->get_name() . ' (#' . $p->get_id() . ')'; }, $tests ) ) ) . '</p>';
		} else {
			echo '<p><strong>Ingen testprodukter å fjerne.</strong></p>';
		}
		echo '<p><a class="button" href="' . esc_url( $seed ) . '">Opprett testkatalog</a> ';
		if ( $tests ) {
			echo '<a class="button" href="' . esc_url( $del ) . '" onclick="return confirm(\'Flytte ' . count( $tests ) . ' testprodukter til papirkurven?\')">Fjern testkatalog</a>';
		}
		echo '</p>';
	}

	/** Server-side validation (R8): invalid values are cleared, which keeps intake closed. */
	public static function save() {
		woocommerce_update_options( self::fields() );
		foreach ( array( 'kc_pickup_start', 'kc_pickup_end' ) as $key ) {
			$value = trim( (string) get_option( $key, '' ) );
			if ( '' !== $value && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
				update_option( $key, '' );
				WC_Admin_Settings::add_error( 'Hentetid må skrives som TT:MM, for eksempel 11:00.' );
			}
		}
		$start = (string) get_option( 'kc_pickup_start', '' );
		$end   = (string) get_option( 'kc_pickup_end', '' );
		if ( $start && $end && strcmp( $start, $end ) >= 0 ) {
			update_option( 'kc_pickup_end', '' );
			WC_Admin_Settings::add_error( 'Hentetid til må være senere enn hentetid fra.' );
		}
		$ints = array(
			'kc_min_persons'     => array( 1, 'Minimum personer' ),
			'kc_daily_capacity'  => array( 1, 'Kapasitet per dag' ),
			'kc_lead_time_hours' => array( 0, 'Ledetid' ),
			'kc_horizon_days'    => array( 1, 'Bestillingshorisont' ),
		);
		foreach ( $ints as $key => $rule ) {
			$value = trim( (string) get_option( $key, '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( ! preg_match( '/^\d+$/', $value ) || (int) $value < $rule[0] ) {
				update_option( $key, '' );
				WC_Admin_Settings::add_error( sprintf( '%s må være et heltall på minst %d.', $rule[1], $rule[0] ) );
			}
		}
		foreach ( array( 'kc_operator_email', 'kc_from_email' ) as $key ) {
			$value = trim( (string) get_option( $key, '' ) );
			if ( '' !== $value && ! is_email( $value ) ) {
				update_option( $key, '' );
				WC_Admin_Settings::add_error( 'Ugyldig e-postadresse ble fjernet.' );
			}
		}
	}
}
