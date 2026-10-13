<?php
/**
 * Catering emails. Content follows the approved acceptance criteria (Saulo, 05.10.2026).
 * Sent explicitly by the catering flow – never as a side effect of a status change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Emails {

	/* ------------------------------------------------------------------ */
	/* Customer                                                            */
	/* ------------------------------------------------------------------ */

	/** E-post 1: «Vi har mottatt cateringbestillingen din.» */
	public static function customer_received( WC_Order $order ) {
		$body  = self::p( 'Vi har mottatt cateringbestillingen din.' );
		$body .= self::facts( $order, true );
		$body .= self::lines( $order, 'Foreløpig pris' );
		$body .= self::contact( $order );
		$body .= self::p( '<strong>Kebabish går gjennom bestillingen før betaling.</strong> Vi kontrollerer kapasitet, pris, henting og eventuelle spesialønsker. Du betaler ikke nå. Når bestillingen er godkjent, får du en ny e-post med endelig pris og betalingslenke.' );

		$to_for_log = $order->get_billing_email();
		return self::record(
			$order,
			'received',
			self::send(
			$order->get_billing_email(),
			sprintf( 'Vi har mottatt cateringbestillingen din (#%s)', $order->get_order_number() ),
			'Bestilling mottatt',
			$body
			),
			$to_for_log
		);
	}

	/** E-post 2: «Bestillingen din er godkjent.» + BETAL HER */
	public static function customer_approved( WC_Order $order ) {
		// 1.1.7: an approval created by «Endre hentetid og send ny lenke» gets its own wording.
		if ( 'reschedule' === $order->get_meta( '_kc_approval_reason' ) ) {
			return self::customer_rescheduled( $order );
		}
		$body  = self::p( 'Bestillingen din er godkjent.' );
		$body .= self::facts( $order, false );
		$body .= self::lines( $order, 'Endelig pris' );
		$body .= self::button( $order->get_checkout_payment_url(), 'BETAL HER' );
		$body .= self::p( 'Bestillingen er bekreftet når betalingen er mottatt.' );

		$to_for_log = $order->get_billing_email();
		return self::record(
			$order,
			'approved',
			self::send(
			$order->get_billing_email(),
			sprintf( 'Bestillingen din er godkjent (#%s)', $order->get_order_number() ),
			'Bestilling godkjent',
			$body
			),
			$to_for_log
		);
	}

	/** Ny hentetid etter godkjenning: oppdatert sammendrag + ny BETAL HER (record type «approved»). */
	public static function customer_rescheduled( WC_Order $order ) {
		$body  = self::p( 'Hentetiden for cateringbestillingen din er endret. Bestillingen er fortsatt godkjent.' );
		$body .= self::facts( $order, false );
		$body .= self::lines( $order, 'Endelig pris' );
		$body .= self::button( $order->get_checkout_payment_url(), 'BETAL HER' );
		$body .= self::p( 'Bruk denne nye lenken. En tidligere tilsendt betalingslenke virker ikke lenger. Bestillingen er bekreftet når betalingen er mottatt.' );
		$to_for_log = $order->get_billing_email();
		return self::record(
			$order,
			'approved',
			self::send( $to_for_log, sprintf( 'Ny hentetid for cateringbestillingen din (#%s)', $order->get_order_number() ), 'Ny hentetid', $body ),
			$to_for_log
		);
	}

	/** E-post 3: «Betalingen er mottatt. Cateringbestillingen din er nå bekreftet.» */
	public static function customer_confirmed( WC_Order $order ) {
		$body  = self::p( 'Betalingen er mottatt. Cateringbestillingen din er nå bekreftet.' );
		$body .= self::facts( $order, false );
		$body .= self::lines( $order, 'Betalt' );

		$to_for_log = $order->get_billing_email();
		return self::record(
			$order,
			'confirmed',
			self::send(
			$order->get_billing_email(),
			sprintf( 'Betalingen er mottatt – cateringbestillingen er bekreftet (#%s)', $order->get_order_number() ),
			'Cateringbestilling bekreftet',
			$body
			),
			$to_for_log
		);
	}

	/** Avslått / avbrutt før betaling. Sent only when the operator chooses to notify the customer. */
	public static function customer_cancelled( WC_Order $order ) {
		$reason = (string) $order->get_meta( '_kc_cancel_reason' );
		$body   = self::p( 'Cateringbestillingen din kan dessverre ikke gjennomføres.' );
		if ( $reason ) {
			$body .= self::table( array( 'Begrunnelse' => nl2br( esc_html( $reason ) ) ) );
		}
		$body      .= self::facts( $order, false );
		$body      .= self::p( 'Du er ikke belastet. Ta gjerne kontakt hvis du vil finne en annen løsning.' );
		$to_for_log = $order->get_billing_email();
		return self::record(
			$order,
			'cancelled',
			self::send( $to_for_log, sprintf( 'Cateringbestillingen din kan ikke gjennomføres (#%s)', $order->get_order_number() ), 'Bestilling avslått', $body ),
			$to_for_log
		);
	}

	/* ------------------------------------------------------------------ */
	/* Operator                                                            */
	/* ------------------------------------------------------------------ */

	public static function operator_new( WC_Order $order ) {
		$body  = self::p( 'En ny cateringbestilling venter på kontroll og godkjenning.' );
		$body .= self::facts( $order, false );
		$body .= self::lines( $order, 'Foreløpig pris' );
		$body .= self::contact( $order );
		if ( class_exists( 'KC_Advisor' ) ) {
			$body .= KC_Advisor::email_block( $order );
		}
		$body .= self::button( $order->get_edit_order_url(), 'Åpne bestillingen' );

		$to_for_log = KC_Core::setting( 'operator_email' );
		return self::record(
			$order,
			'operator_new',
			self::send(
			KC_Core::setting( 'operator_email' ),
			sprintf( 'Ny cateringbestilling #%s – henting %s', $order->get_order_number(), KC_Core::event_datetime( $order ) ),
			'Ny cateringbestilling',
			$body
			),
			$to_for_log
		);
	}

	public static function operator_paid( WC_Order $order ) {
		$body  = self::p( 'Betaling er mottatt. Bestillingen er bekreftet og kan planlegges i produksjon.' );
		$body .= self::facts( $order, false );
		$body .= self::lines( $order, 'Betalt' );
		$body .= self::button( $order->get_edit_order_url(), 'Åpne bestillingen' );

		$to_for_log = KC_Core::setting( 'operator_email' );
		return self::record(
			$order,
			'operator_paid',
			self::send(
			KC_Core::setting( 'operator_email' ),
			sprintf( 'Cateringordre #%s er betalt – henting %s', $order->get_order_number(), KC_Core::event_datetime( $order ) ),
			'Catering betalt',
			$body
			),
			$to_for_log
		);
	}

	public static function operator_payment_mismatch( WC_Order $order ) {
		$body  = self::p( '<strong>En betaling krever kontroll.</strong> Den er IKKE registrert som verifisert, og kunden har IKKE fått bekreftelse.' );
		$body .= self::table(
			array(
				'Ordrenummer' => esc_html( $order->get_order_number() ),
				'Avvik'       => esc_html( (string) $order->get_meta( '_kc_payment_mismatch' ) ),
			)
		);
		$body      .= self::button( $order->get_edit_order_url(), 'Åpne bestillingen' );
		$to_for_log = KC_Core::setting( 'operator_email' );
		return self::record(
			$order,
			'operator_mismatch',
			self::send( $to_for_log, sprintf( 'KONTROLL: betaling for cateringordre #%s', $order->get_order_number() ), 'Betaling krever kontroll', $body ),
			$to_for_log
		);
	}

	/* ------------------------------------------------------------------ */
	/* Send status (R1)                                                    */
	/* ------------------------------------------------------------------ */

	const LABELS = array(
		'received'          => 'E-post 1 – mottatt',
		'approved'          => 'E-post 2 – godkjent + BETAL HER',
		'confirmed'         => 'E-post 3 – betaling bekreftet',
		'cancelled'         => 'Avslag til kunde',
		'operator_new'      => 'Varsel til Kebabish – ny bestilling',
		'operator_paid'     => 'Varsel til Kebabish – betalt',
		'operator_mismatch' => 'Varsel til Kebabish – betaling krever kontroll',
	);

	/**
	 * Record the transport result for one mail type. "sent" = accepted by the mail transport
	 * (wp_mail returned true), not proven inbox delivery.
	 */
	private static function record( WC_Order $order, $type, $sent, $to ) {
		$log          = $order->get_meta( '_kc_mail_log' );
		$log          = is_array( $log ) ? $log : array();
		$prev         = is_array( $log[ $type ] ?? null ) ? $log[ $type ] : array();
		$log[ $type ] = array(
			'status'   => $sent ? 'sent' : 'failed',
			'at'       => time(),
			'attempts' => (int) ( $prev['attempts'] ?? 0 ) + 1,
			'revision' => (int) $order->get_meta( '_kc_revision' ),
			'to'       => (string) $to,
		);
		$order->update_meta_data( '_kc_mail_log', $log );
		$order->add_order_note( sprintf( 'Catering: %s – %s%s.', self::LABELS[ $type ] ?? $type, $sent ? 'akseptert av e-postsystemet' : 'IKKE sendt (feil i e-postsystem eller mottaker)', $to ? ' (' . $to . ')' : '' ) );
		$order->save();
		return (bool) $sent;
	}

	public static function status( WC_Order $order, $type ) {
		$log = $order->get_meta( '_kc_mail_log' );
		return ( is_array( $log ) && is_array( $log[ $type ] ?? null ) ) ? $log[ $type ] : array();
	}

	/* ------------------------------------------------------------------ */
	/* Building blocks                                                     */
	/* ------------------------------------------------------------------ */

	private static function p( $html ) {
		return '<p>' . wp_kses_post( $html ) . '</p>';
	}

	private static function facts( WC_Order $order, $with_notice ) {
		$persons = (int) $order->get_meta( '_kc_persons' );
		$rows    = array(
			'Ordrenummer' => esc_html( $order->get_order_number() ),
			'Personer'    => $persons ? esc_html( KC_Core::persons_label( $persons ) . ' totalt' ) : '',
			'Hentetid'    => esc_html( KC_Core::event_datetime( $order ) ),
			'Hentested'   => esc_html( (string) $order->get_meta( '_kc_pickup_address' ) ),
		);
		$occasion = (string) $order->get_meta( '_kc_occasion' );
		$allowed = KC_Intake::occasions();
		if ( isset( $allowed[ $occasion ] ) ) {
			$rows['Anledning'] = esc_html( $allowed[ $occasion ] );
		}
		$note = $order->get_customer_note();
		if ( $note ) {
			$rows['Allergier / kommentar'] = nl2br( esc_html( $note ) );
		}
		// V2 (D9): an allergy request is visible to the customer too, with the confirmation caveat.
		$adv = class_exists( 'KC_Advisor' ) ? $order->get_meta( KC_Advisor::META ) : null;
		if ( is_array( $adv ) && ! empty( $adv['needs']['allergy_text'] ) ) {
			$rows['Allergitilpasning'] = esc_html( KC_Advisor::texts()['allergy'] );
		}
		return self::table( $rows );
	}

	private static function contact( WC_Order $order ) {
		$rows = array(
			'Navn'    => esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
			'Telefon' => esc_html( $order->get_billing_phone() ),
			'E-post'  => esc_html( $order->get_billing_email() ),
		);
		if ( $order->get_billing_company() ) {
			$rows['Firma'] = esc_html( $order->get_billing_company() );
		}
		return '<h3 style="margin:24px 0 8px">Kontaktinformasjon</h3>' . self::table( $rows );
	}

	private static function lines( WC_Order $order, $total_label ) {
		$cell = 'padding:8px 10px;border-bottom:1px solid #e5e0d6;text-align:left;vertical-align:top';
		$num  = $cell . ';text-align:right;white-space:nowrap';
		$html = '<h3 style="margin:24px 0 8px">Bestilling</h3><table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 8px">';
		$html .= '<tr><th style="' . $cell . '">Vare</th><th style="' . $num . '">Antall</th><th style="' . $num . '">Sum</th></tr>';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$unit  = (string) $item->get_meta( '_kc_unit' );
			$qty   = (int) $item->get_quantity();
			$label = $unit ? $qty . ' ' . KC_Catalog::unit_label( $unit, $qty ) : (string) $qty;
			$html .= '<tr><td style="' . $cell . '">' . esc_html( $item->get_name() ) . '</td><td style="' . $num . '">' . esc_html( $label ) . '</td><td style="' . $num . '">' . wp_kses_post( wc_price( (float) $item->get_total() + (float) $item->get_total_tax() ) ) . '</td></tr>';
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$reason = (string) $fee->get_meta( '_kc_reason' );
			$name   = $fee->get_name() . ( $reason ? ' – ' . $reason : '' );
			$html  .= '<tr><td style="' . $cell . '" colspan="2">' . esc_html( $name ) . '</td><td style="' . $num . '">' . wp_kses_post( wc_price( (float) $fee->get_total() + (float) $fee->get_total_tax() ) ) . '</td></tr>';
		}
		$html .= '<tr><td style="' . $cell . ';font-weight:700" colspan="2">' . esc_html( $total_label ) . '</td><td style="' . $num . ';font-weight:700">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td></tr>';
		$html .= '</table>';
		return $html;
	}

	private static function table( array $rows ) {
		$html = '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( wp_strip_all_tags( (string) $value ) ) ) {
				continue;
			}
			$html .= '<tr><th style="padding:6px 10px 6px 0;text-align:left;vertical-align:top;width:38%">' . esc_html( $label ) . '</th><td style="padding:6px 0;vertical-align:top">' . $value . '</td></tr>';
		}
		return $html . '</table>';
	}

	private static function button( $url, $label ) {
		return '<p style="margin:26px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 24px;background:#B83A2E;color:#ffffff;text-decoration:none;font-weight:700;border-radius:4px">' . esc_html( $label ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ */
	/* Transport                                                           */
	/* ------------------------------------------------------------------ */

	private static function send( $to, $subject, $heading, $body ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$from_email = KC_Core::setting( 'from_email' );
		$from_name  = KC_Core::setting( 'from_name' );
		$set_email  = function ( $value ) use ( $from_email ) {
			return is_email( $from_email ) ? $from_email : $value;
		};
		$set_name   = function ( $value ) use ( $from_name ) {
			return '' !== $from_name ? $from_name : $value;
		};
		add_filter( 'woocommerce_email_from_address', $set_email, 99 );
		add_filter( 'woocommerce_email_from_name', $set_name, 99 );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$sent    = $mailer->send( $to, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_filter( 'woocommerce_email_from_address', $set_email, 99 );
		remove_filter( 'woocommerce_email_from_name', $set_name, 99 );
		return $sent;
	}
}
