<?php
/**
 * Kebabish operator surface (V1 = WooCommerce order screen + "Catering" box, and an overview
 * under WooCommerce → Catering). R10: this is the delivered surface; a separate simplified
 * operator page is a scope option for Saulo, not part of 1.1.0.
 *
 * MOTTATT  -> (endre antall / juster / endre hentetid) -> Godkjenn = ny revisjon + ny lenke -> GODKJENT
 * GODKJENT -> Trekk tilbake (lenke ugyldig) -> MOTTATT
 * MOTTATT / GODKJENT / betaling feilet -> Avslå med begrunnelse -> Kansellert (lenke ugyldig)
 * Verifisert betalt (processing) -> Marker som hentet -> FULLFØRT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Operator {

	const NONCE = 'kc_operator_action';

	/** Re-entrancy guard for our own status changes. */
	private static $busy = false;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'handle_save' ), 60, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'woocommerce_before_save_order_items', array( __CLASS__, 'lock_before_items_save' ), 5, 1 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'on_items_saved' ), 20, 1 );
		add_action( 'shutdown', array( __CLASS__, 'release_items_lock' ) );
		// 1.1.3: «Legg til vare» and «Fjern vare» do not go through wc_save_order_items for the
		// changed line, so they get their own lock points (before the change, inside WC's try/catch).
		add_filter( 'woocommerce_ajax_add_order_item_validation', array( __CLASS__, 'lock_before_item_add' ), 10, 4 );
		add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'tag_added_item' ), 10, 3 );
		add_action( 'woocommerce_ajax_order_items_added', array( __CLASS__, 'after_items_added' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'lock_before_item_delete' ), 5, 1 );
		add_action( 'woocommerce_ajax_order_items_removed', array( __CLASS__, 'after_item_removed' ), 10, 4 );
	}

	/** Date lock held across WooCommerce's item save + our reservation update (1.1.2). */
	private static $items_lock = null;

	public static function lock_before_items_save( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! KC_Core::is_v1( $order ) || ! $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
			return;
		}
		if ( ! self::take_order_lock( $order ) ) {
			// 1.1.3: a normal return would let WooCommerce save without the lock. Stop the request
			// here – BEFORE any line item is saved – so lines, total and reservation stay unchanged.
			self::abort_items_save( $order, self::BUSY_MESSAGE );
		}
	}

	/**
	 * Abort every WooCommerce path that saves order items, in the response format that path expects.
	 * - AJAX «Beregn på nytt» (woocommerce_calc_line_taxes): HTML of the UNCHANGED items + notice.
	 * - Other AJAX paths (lagre, legg til/fjern vare): JSON error, shown by WooCommerce as an alert.
	 * - Order form «Oppdater»: wp_die() with back link. Items are the first thing WooCommerce saves
	 *   on this path, so nothing else is saved either.
	 */
	private static function abort_items_save( WC_Order $order, $message ) {
		$order->add_order_note( 'Catering: endring av varelinjer avbrutt (datolås opptatt). Ingenting lagret.' );
		$order->save();
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the nonce for this action.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( 'woocommerce_calc_line_taxes' === $action ) {
				$order = wc_get_order( $order->get_id() );
				echo '<div class="notice notice-error inline" style="margin:12px"><p>' . esc_html( $message ) . '</p></div>';
				include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-items.php';
				echo '<script>window.alert(' . wp_json_encode( $message ) . ');</script>';
				wp_die();
			}
			wp_send_json_error( array( 'error' => $message ) );
		}
		wp_die( esc_html( $message ), 'Prøv igjen', array( 'response' => 409, 'back_link' => true ) );
	}

	/** Take the date lock for an order (idempotent within the request). False on timeout. */
	private static function take_order_lock( WC_Order $order ) {
		$name = KC_Core::capacity_lock_name( (string) $order->get_meta( '_kc_event_date' ) );
		if ( is_array( self::$items_lock ) && self::$items_lock[0] === $name ) {
			return true;
		}
		$token = KC_Core::acquire_lock( $name );
		if ( ! $token ) {
			return false;
		}
		self::$items_lock = array( $name, $token, $order->get_id() );
		return true;
	}

	const BUSY_MESSAGE = 'Bestillingen kunne ikke endres akkurat nå fordi en annen endring for samme hentedato pågår. Ingenting er lagret. Prøv igjen om et øyeblikk.';

	/** «Legg til vare»: runs BEFORE WooCommerce adds the product. A WP_Error stops it (JSON error). */
	public static function lock_before_item_add( $error, $product, $order, $qty ) {
		if ( ! $order instanceof WC_Order || ! KC_Core::is_v1( $order ) || ! $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
			return $error;
		}
		if ( ! self::take_order_lock( $order ) ) {
			$error->add( 'kc_busy', self::BUSY_MESSAGE );
		}
		return $error;
	}

	/** Lines added in admin get the same role/unit meta as lines from the order form. */
	public static function tag_added_item( $item_id, $item, $order ) {
		if ( ! KC_Core::is_v1( $order ) || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$product = $item->get_product();
		if ( $product && KC_Core::is_catering_product( $product ) ) {
			$item->update_meta_data( '_kc_role', KC_Catalog::role( $product ) );
			$item->update_meta_data( '_kc_unit', KC_Catalog::unit( $product ) );
			$item->save();
		}
	}

	public static function after_items_added( $added_items, $order ) {
		self::release_items_lock(); // Reconciles reservation, then releases.
	}

	/** «Fjern vare»: runs BEFORE deletion, inside WooCommerce's try/catch → JSON error on timeout. */
	public static function lock_before_item_delete( $item_id ) {
		if ( ! wp_doing_ajax() ) {
			return;
		}
		$order = wc_get_order( wc_get_order_id_by_order_item_id( $item_id ) );
		if ( ! $order || ! KC_Core::is_v1( $order ) || ! $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
			return;
		}
		if ( ! self::take_order_lock( $order ) ) {
			throw new Exception( self::BUSY_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function after_item_removed( $item_id, $item, $changed_stock, $order ) {
		self::release_items_lock(); // Reconciles reservation, then releases.
	}

	/**
	 * Single exit point for the items lock (normal end, WooCommerce error mid-loop, or shutdown).
	 * 1.1.4: BEFORE releasing, the reservation is reconciled with the lines actually saved in the
	 * database, so a partial failure (e.g. first package added, next item rejected) never leaves
	 * _kc_persons on the old number.
	 */
	public static function release_items_lock() {
		if ( ! is_array( self::$items_lock ) ) {
			return;
		}
		$lock             = self::$items_lock;
		self::$items_lock = null;
		try {
			if ( ! empty( $lock[2] ) ) {
				self::update_reservation_after_items( (int) $lock[2] );
			}
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'Catering: reservation reconcile failed: ' . $e->getMessage(), array( 'source' => 'kebabish-catering' ) );
		} finally {
			KC_Core::release_lock( $lock[0], $lock[1] );
		}
	}

	/**
	 * Quantity edits in the WC item editor: keep the reservation (_kc_persons) current, and say
	 * clearly if the date is now over capacity. Approval re-checks capacity under the date lock.
	 */
	public static function on_items_saved( $order_id ) {
		if ( is_array( self::$items_lock ) ) {
			self::release_items_lock(); // Reconciles reservation, then releases.
		} else {
			self::update_reservation_after_items( $order_id );
		}
	}

	private static function update_reservation_after_items( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! KC_Core::is_v1( $order ) || ! $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
			return;
		}
		$old = (int) $order->get_meta( '_kc_persons' );
		$new = KC_Catalog::persons_in_order( $order );
		if ( $old === $new ) {
			return;
		}
		$order->update_meta_data( '_kc_persons', $new );
		$date     = (string) $order->get_meta( '_kc_event_date' );
		$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
		$others   = KC_Intake::persons_on( $date, $order->get_id() );
		$note     = sprintf( 'Catering: personer endret fra %d til %d.', $old, $new );
		if ( $others + $new > $capacity ) {
			$note .= sprintf( ' OVER KAPASITET: %d av %d personer denne dagen. Kan ikke godkjennes før antall, dato eller kapasitet er endret.', $others + $new, $capacity );
		}
		$order->add_order_note( $note );
		$order->save();
	}

	/* ================================================================== */
	/* Meta box                                                            */
	/* ================================================================== */

	public static function add_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'kc-catering', 'Catering', array( __CLASS__, 'render_box' ), $screen, 'normal', 'high' );
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! KC_Core::is_catering_order( $order ) ) {
			echo '<p>Ikke en cateringordre.</p>';
			return;
		}
		if ( ! KC_Core::is_v1( $order ) ) {
			self::render_legacy( $order );
			return;
		}

		wp_nonce_field( self::NONCE, 'kc_operator_nonce' );

		$date     = (string) $order->get_meta( '_kc_event_date' );
		$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
		$booked   = $date ? KC_Intake::persons_on( $date ) : 0;
		$persons  = (int) $order->get_meta( '_kc_persons' );
		$paid     = KC_Core::is_verified_paid( $order );

		$mismatch = (string) $order->get_meta( '_kc_payment_mismatch' );
		if ( $mismatch && ! $paid ) {
			echo '<div class="notice notice-error inline"><p><strong>Betaling krever kontroll:</strong> ' . esc_html( $mismatch ) . '. Ikke registrert som betalt.</p></div>';
		}
		if ( $order->has_status( array( 'processing', 'completed' ) ) && ! $paid ) {
			echo '<div class="notice notice-error inline"><p><strong>IKKE BETALT.</strong> Statusen er satt manuelt. Ingen verifisert betaling er registrert.</p></div>';
		}

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px 26px;margin:6px 0 18px">';
		self::cell( 'Status', esc_html( self::status_text( $order ) ) );
		self::cell( 'Hentetid', esc_html( KC_Core::event_datetime( $order ) ) );
		self::cell( 'Personer', esc_html( KC_Core::persons_label( $persons ) ) );
		$occasion = (string) $order->get_meta( '_kc_occasion' );
		$occasion_labels = KC_Intake::occasions();
		if ( isset( $occasion_labels[ $occasion ] ) ) {
			self::cell( 'Anledning', esc_html( $occasion_labels[ $occasion ] ) );
		}
		self::cell( 'Kapasitet denne dagen', esc_html( $booked . ' av ' . $capacity . ' personer reservert' ) );
		self::cell( 'Foreløpig pris (ved innsending)', wp_kses_post( wc_price( (float) $order->get_meta( '_kc_prelim_total' ) ) ) );
		self::cell( 'Nåværende total', wp_kses_post( $order->get_formatted_order_total() ) );
		self::cell( 'Godkjent revisjon', esc_html( (string) (int) $order->get_meta( '_kc_revision' ) . ( KC_Core::approval_active( $order ) ? ' (aktiv)' : '' ) ) );
		if ( $order->get_billing_company() ) {
			self::cell( 'Firma', esc_html( $order->get_billing_company() ) );
		}
		echo '</div>';

		$note = $order->get_customer_note();
		echo '<p><strong>Allergier / kommentar</strong><br>' . ( $note ? nl2br( esc_html( $note ) ) : '—' ) . '</p>';
		if ( class_exists( 'KC_Advisor' ) ) {
			echo KC_Advisor::operator_box( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}

		if ( $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
			self::render_received_actions( $order );
		} elseif ( $order->has_status( array( KC_Core::STATUS_APPROVED, 'failed' ) ) && KC_Core::approval_active( $order ) && ! $paid ) {
			echo '<hr><p><strong>' . ( $order->has_status( 'failed' ) ? 'Betalingsforsøk feilet. Kunden kan prøve igjen med samme lenke.' : 'Godkjent – venter på betaling.' ) . '</strong> Endelig total: ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';
			self::render_live_reschedule( $order );
			echo '<p><button type="submit" class="button" name="kc_action" value="revoke" onclick="return confirm(\'Trekke tilbake godkjenningen? Betalingslenken slutter å virke.\')">Trekk tilbake godkjenning</button></p>';
			echo '<p class="description">Varelinjer er låst etter godkjenning. For å endre innhold eller pris: trekk tilbake godkjenning → endre → godkjenn på nytt.</p>';
		} elseif ( $paid && $order->has_status( 'processing' ) ) {
			echo '<hr><p><strong>Betalt og bekreftet.</strong></p>';
			echo '<p><button type="submit" class="button button-primary" name="kc_action" value="complete">Marker som hentet</button></p>';
		}

		if ( ! $paid && $order->has_status( array( KC_Core::STATUS_RECEIVED, KC_Core::STATUS_APPROVED, 'failed' ) ) ) {
			echo '<hr><details><summary><strong>Avslå / avbryt bestillingen</strong></summary>';
			echo '<p><label>Begrunnelse (påkrevd)<br><textarea name="kc_cancel_reason" rows="2" style="width:100%" maxlength="500"></textarea></label></p>';
			echo '<p><label><input type="checkbox" name="kc_cancel_notify" value="1" checked> Send beskjed til kunden med begrunnelsen</label></p>';
			echo '<p><button type="submit" class="button" name="kc_action" value="cancel" onclick="return confirm(\'Avslå bestillingen? Betaling stenges.\')">Avslå bestillingen</button></p></details>';
		}

		self::render_mail_status( $order );
	}

	private static function render_received_actions( WC_Order $order ) {
		echo '<hr><h4 style="margin:12px 0 6px">Kontroller og godkjenn</h4>';
		echo '<p class="description">Endre antall i varelistene over og trykk «Beregn på nytt». Bruk justering for prisendring utover varelinjene.</p>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:10px 0">';
		echo '<label>Justering, kr (+/−)<br><input type="number" step="0.01" name="kc_adjust_amount" style="width:140px"></label>';
		echo '<label style="flex:1;min-width:220px">Begrunnelse (vises for kunden)<br><input type="text" name="kc_adjust_reason" maxlength="120" style="width:100%"></label>';
		echo '<button type="submit" class="button" name="kc_action" value="adjust">Lagre justering</button>';
		echo '</div>';

		$slots = KC_Core::slots();
		echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:10px 0">';
		echo '<label>Ny dato for henting<br><input type="date" name="kc_new_date" value="' . esc_attr( (string) $order->get_meta( '_kc_event_date' ) ) . '"></label>';
		echo '<label>Nytt tidspunkt for henting<br><select name="kc_new_time">';
		foreach ( $slots as $slot ) {
			echo '<option value="' . esc_attr( $slot ) . '"' . selected( (string) $order->get_meta( '_kc_event_time' ), $slot, false ) . '>' . esc_html( $slot ) . '</option>';
		}
		echo '</select></label>';
		echo '<button type="submit" class="button" name="kc_action" value="reschedule">Endre hentetid</button>';
		echo '</div>';

		if ( count( $order->get_items( 'line_item' ) ) < 1 ) {
			echo '<p><em>Ordren har ingen varelinjer og kan ikke godkjennes.</em></p>';
			return;
		}
		echo '<p><button type="submit" class="button button-primary button-large" name="kc_action" value="approve" onclick="return confirm(\'Godkjenne og sende betalingslenke til kunden?\')">Godkjenn og send betalingslenke</button></p>';
		echo '<p class="description">Låser totalen som ny prisrevisjon, lager ny betalingslenke, setter «Catering – godkjent» og sender e-post med endelig sammendrag og BETAL HER.</p>';
	}

	/** 1.1.7: change pickup time on an approved, unpaid order in one click. */
	private static function render_live_reschedule( WC_Order $order ) {
		echo '<div style="margin:12px 0;padding:12px;border:1px solid #c3c4c7;background:#f6f7f7">';
		echo '<strong>Endre hentetid</strong><br><span class="description">Gammel betalingslenke stenges, ordren godkjennes på nytt med samme varer og pris, og kunden får én e-post med ny hentetid og ny lenke.</span>';
		echo '<input type="hidden" name="kc_seen_revision" value="' . esc_attr( (string) (int) $order->get_meta( '_kc_revision' ) ) . '">';
		echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:8px">';
		echo '<label>Ny dato for henting<br><input type="date" name="kc_live_date" value="' . esc_attr( (string) $order->get_meta( '_kc_event_date' ) ) . '"></label>';
		echo '<label>Nytt tidspunkt for henting<br><select name="kc_live_time">';
		foreach ( KC_Core::slots() as $slot ) {
			echo '<option value="' . esc_attr( $slot ) . '"' . selected( (string) $order->get_meta( '_kc_event_time' ), $slot, false ) . '>' . esc_html( $slot ) . '</option>';
		}
		echo '</select></label>';
		echo '<button type="submit" class="button button-primary" name="kc_action" value="reschedule_live" onclick="if(this.dataset.sent){return false;} if(!confirm(\'Endre hentetid og sende ny betalingslenke til kunden?\')){return false;} this.dataset.sent=1;">Endre hentetid og send ny lenke</button>';
		echo '</div></div>';
	}

	private static function render_mail_status( WC_Order $order ) {
		$paid    = KC_Core::is_verified_paid( $order );
		$rows    = array(
			'received'      => true,
			'approved'      => KC_Core::approval_active( $order ) && ! $paid && $order->has_status( array( KC_Core::STATUS_APPROVED, 'failed' ) ),
			'confirmed'     => $paid,
			'cancelled'     => $order->has_status( 'cancelled' ),
			'operator_new'  => true,
			'operator_paid' => $paid,
		);
		echo '<hr><h4 style="margin:12px 0 6px">E-post</h4><table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $type => $resendable ) {
			$st = KC_Emails::status( $order, $type );
			if ( ! $st && ! $resendable ) {
				continue;
			}
			$text = $st ? ( 'sent' === $st['status'] ? 'Akseptert av e-postsystemet' : '<strong style="color:#b32d2e">FEILET</strong>' ) . ' · ' . esc_html( wp_date( 'j.n. H:i', (int) $st['at'] ) ) . ' · forsøk ' . (int) $st['attempts'] : 'Ikke sendt';
			echo '<tr><td>' . esc_html( KC_Emails::LABELS[ $type ] ) . '</td><td>' . wp_kses_post( $text ) . '</td><td>';
			if ( $resendable ) {
				echo '<button type="submit" class="button button-small" name="kc_action" value="resend_' . esc_attr( $type ) . '">Send på nytt</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_legacy( WC_Order $order ) {
		echo '<p><strong>Eldre testordre (før versjon 1.0.0).</strong> Fritekstbestilling uten varelinjemodell. Kan ikke behandles i ny flyt.</p>';
		$text = (string) $order->get_meta( '_kebabish_request_text' );
		$when = (string) $order->get_meta( '_kebabish_event_datetime' );
		echo '<p>Dato/tid: ' . esc_html( $when ? $when : '—' ) . '<br>Bestilling: ' . esc_html( $text ? $text : '—' ) . '</p>';
	}

	private static function cell( $label, $html ) {
		echo '<div><strong>' . esc_html( $label ) . '</strong><br>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape.
	}

	public static function status_text( WC_Order $order ) {
		if ( KC_Core::is_verified_paid( $order ) ) {
			return $order->has_status( 'completed' ) ? 'FULLFØRT (hentet)' : 'BETALT/BEKREFTET';
		}
		if ( $order->has_status( 'failed' ) ) {
			return 'GODKJENT – betaling feilet';
		}
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return 'IKKE BETALT (manuell status)';
		}
		return wc_get_order_status_name( $order->get_status() );
	}

	/* ================================================================== */
	/* Actions                                                             */
	/* ================================================================== */

	public static function handle_save( $order_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( empty( $_POST['kc_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['kc_operator_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kc_operator_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['kc_action'] ) );
		$in     = function ( $key ) {
			return isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
		};
		$reason_long = isset( $_POST['kc_cancel_reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['kc_cancel_reason'] ) ) ) : '';
		$notify      = ! empty( $_POST['kc_cancel_notify'] );
		// phpcs:enable

		$order = wc_get_order( $order_id );
		if ( ! KC_Core::is_v1( $order ) ) {
			return;
		}
		$paid = KC_Core::is_verified_paid( $order );

		switch ( $action ) {
			case 'adjust':
				if ( $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
					$r = self::apply_adjustment( $order, $in( 'kc_adjust_amount' ), $in( 'kc_adjust_reason' ) );
					self::notice( true === $r ? 'Justering lagret.' : $r, true === $r );
				}
				break;

			case 'reschedule':
				if ( $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
					$r = self::reschedule( $order, $in( 'kc_new_date' ), $in( 'kc_new_time' ) );
					self::notice( true === $r ? 'Hentetid endret.' : $r, true === $r );
				}
				break;

			case 'approve':
				if ( $order->has_status( KC_Core::STATUS_RECEIVED ) ) {
					if ( '' !== $in( 'kc_adjust_amount' ) ) {
						$r = self::apply_adjustment( $order, $in( 'kc_adjust_amount' ), $in( 'kc_adjust_reason' ) );
						if ( true !== $r ) {
							self::notice( $r, false );
							break;
						}
					}
					$r = self::approve( $order, 'knapp' );
					if ( true !== $r ) {
						self::notice( $r, false );
						break;
					}
					$st = KC_Emails::status( $order, 'approved' );
					self::notice(
						'sent' === ( $st['status'] ?? '' ) ? 'Godkjent. Ny betalingslenke er sendt til kunden.' : 'Godkjent, men e-posten med betalingslenke FEILET. Bruk «Send på nytt».',
						'sent' === ( $st['status'] ?? '' )
					);
				}
				break;

			case 'reschedule_live':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified at top of handle_save().
				$seen = isset( $_POST['kc_seen_revision'] ) ? (int) $_POST['kc_seen_revision'] : -1;
				$r    = self::reschedule_approved( $order->get_id(), $in( 'kc_live_date' ), $in( 'kc_live_time' ), $seen );
				self::notice( $r['message'], $r['ok'] );
				break;

			case 'revoke':
				if ( $order->has_status( array( KC_Core::STATUS_APPROVED, 'failed' ) ) && ! $paid ) {
					self::close_approval( $order );
					self::$busy = true;
					$order->set_status( KC_Core::STATUS_RECEIVED, 'Catering: godkjenning trukket tilbake. Tidligere betalingslenke er ugyldig.' );
					$order->save();
					self::$busy = false;
					self::notice( 'Godkjenningen er trukket tilbake. Tidligere betalingslenke virker ikke lenger.', true );
				}
				break;

			case 'cancel':
				if ( $paid || ! $order->has_status( array( KC_Core::STATUS_RECEIVED, KC_Core::STATUS_APPROVED, 'failed' ) ) ) {
					self::notice( 'Betalte ordrer kan ikke avslås her (refusjon er utenfor V1).', false );
					break;
				}
				if ( '' === $reason_long ) {
					self::notice( 'Avslag krever en begrunnelse.', false );
					break;
				}
				self::close_approval( $order );
				$order->update_meta_data( '_kc_cancel_reason', $reason_long );
				self::$busy = true;
				$order->set_status( 'cancelled', 'Catering: avslått – ' . $reason_long );
				$order->save();
				self::$busy = false;
				$sent = $notify ? KC_Emails::customer_cancelled( $order ) : null;
				self::notice( 'Bestillingen er avslått. Betaling er stengt.' . ( false === $sent ? ' E-posten til kunden FEILET.' : '' ), false !== $sent );
				break;

			case 'complete':
				if ( $paid && $order->has_status( 'processing' ) ) {
					$order->set_status( 'completed', 'Catering: hentet av kunden.' );
					$order->save();
					self::notice( 'Markert som hentet.', true );
				} else {
					self::notice( 'Kan ikke markeres som hentet: ingen verifisert betaling.', false );
				}
				break;

			default:
				if ( 0 === strpos( $action, 'resend_' ) ) {
					self::resend( $order, substr( $action, 7 ) );
				}
		}
	}

	/** Each resend is allowed only in the state where the original mail is still valid. */
	private static function resend( WC_Order $order, $type ) {
		$paid = KC_Core::is_verified_paid( $order );
		$ok   = null;
		switch ( $type ) {
			case 'received':
				$ok = KC_Emails::customer_received( $order );
				break;
			case 'approved':
				if ( KC_Core::approval_active( $order ) && ! $paid && $order->has_status( array( KC_Core::STATUS_APPROVED, 'failed' ) ) ) {
					$ok = KC_Emails::customer_approved( $order );
				}
				break;
			case 'confirmed':
				if ( $paid ) {
					$ok = KC_Emails::customer_confirmed( $order );
				}
				break;
			case 'cancelled':
				if ( $order->has_status( 'cancelled' ) ) {
					$ok = KC_Emails::customer_cancelled( $order );
				}
				break;
			case 'operator_new':
				$ok = KC_Emails::operator_new( $order );
				break;
			case 'operator_paid':
				if ( $paid ) {
					$ok = KC_Emails::operator_paid( $order );
				}
				break;
		}
		if ( null === $ok ) {
			self::notice( 'Denne e-posten kan ikke sendes i ordrens nåværende tilstand.', false );
			return;
		}
		self::notice( $ok ? 'E-post sendt på nytt.' : 'E-posten feilet igjen. Kontroller e-postoppsettet.', $ok );
	}

	/**
	 * R3: validate the candidate total BEFORE touching the order. A rejected adjustment leaves
	 * lines, previous adjustment and total unchanged.
	 *
	 * @return true|string
	 */
	private static function apply_adjustment( WC_Order $order, $amount_raw, $reason ) {
		if ( '' === $amount_raw || ! is_numeric( str_replace( ',', '.', $amount_raw ) ) ) {
			return 'Skriv inn et gyldig justeringsbeløp.';
		}
		$amount = round( (float) str_replace( ',', '.', $amount_raw ), wc_get_price_decimals() );
		if ( abs( $amount ) >= 0.01 && '' === $reason ) {
			return 'Justering krever en begrunnelse.';
		}

		// Candidate total = line items + other fees (except our adjustment) + shipping + new adjustment.
		$base = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$base += (float) $item->get_total() + (float) $item->get_total_tax();
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( 'yes' !== $fee->get_meta( '_kc_adjustment' ) ) {
				$base += (float) $fee->get_total() + (float) $fee->get_total_tax();
			}
		}
		$base += (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		if ( $base + $amount < 0.01 ) {
			return sprintf( 'Justeringen ble avvist: totalen ville blitt %s. Ingenting er endret.', KC_Core::format_money( $base + $amount ) );
		}

		foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
			if ( 'yes' === $fee->get_meta( '_kc_adjustment' ) ) {
				$order->remove_item( $item_id );
			}
		}
		if ( abs( $amount ) >= 0.01 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Justering' );
			$fee->set_tax_status( 'none' ); // V1: WC tax is off (see KC_Core::intake_status).
			$fee->set_amount( $amount );
			$fee->set_total( $amount );
			$fee->add_meta_data( '_kc_adjustment', 'yes', true );
			$fee->add_meta_data( '_kc_reason', $reason, true );
			$order->add_item( $fee );
		}
		$order->calculate_totals( wc_tax_enabled() ); // Saves.
		$order->add_order_note( sprintf( 'Catering: justering %s (%s). Ny total %s.', KC_Core::format_money( $amount ), $reason ? $reason : 'fjernet', KC_Core::format_money( $order->get_total() ) ) );
		$order->save();
		return true;
	}

	/**
	 * Server rules for an operator-chosen pickup time (shared by both reschedule paths).
	 *
	 * @return true|string
	 */
	private static function validate_pickup( $date, $time ) {
		$tz = wp_timezone();
		$d  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, $tz );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			return 'Ikke endret: ugyldig dato for henting.';
		}
		if ( ! in_array( $time, KC_Core::slots(), true ) ) {
			return 'Ikke endret: tidspunktet er utenfor hentevinduet.';
		}
		$when = new DateTimeImmutable( $date . ' ' . $time, $tz );
		if ( $when <= new DateTimeImmutable( 'now', $tz ) ) {
			return 'Ikke endret: hentetiden er passert.';
		}
		$closed = array_map( 'strval', (array) get_option( 'kc_closed_weekdays', array() ) );
		if ( in_array( $when->format( 'N' ), $closed, true ) ) {
			return 'Ikke endret: catering er stengt denne ukedagen.';
		}
		return true;
	}

	private static function fresh_order( $order_id ) {
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		return wc_get_order( $order_id );
	}

	/**
	 * 1.1.7 «Endre hentetid og send ny lenke» for an APPROVED (or failed, approval active), UNPAID order.
	 *
	 * Order: validate → order lock → re-read + re-check (status, revision, payment proof, same time)
	 * → date locks (old + new, fixed order) → capacity → ONE DB transaction (time, reservation, new
	 * revision, new order key, note) → commit → release locks → e-mail (retryable via «Send på nytt»
	 * with the same link/revision). Any failure before commit leaves everything unchanged.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function reschedule_approved( $order_id, $date, $time, $seen_revision ) {
		$valid = self::validate_pickup( $date, $time );
		if ( true !== $valid ) {
			return array( 'ok' => false, 'message' => $valid );
		}

		$order_lock = 'kc_order_lock_' . (int) $order_id;
		$order_tok  = KC_Core::acquire_lock( $order_lock );
		if ( ! $order_tok ) {
			return array( 'ok' => false, 'message' => 'Ikke endret: ordren behandles akkurat nå. Prøv igjen om et øyeblikk.' );
		}
		$date_locks = array();
		try {
			$order = self::fresh_order( $order_id );
			if ( ! KC_Core::is_v1( $order ) || KC_Core::is_verified_paid( $order ) || ! KC_Core::approval_active( $order ) || ! $order->has_status( array( KC_Core::STATUS_APPROVED, 'failed' ) ) ) {
				return array( 'ok' => false, 'message' => 'Ikke endret: ordren er ikke lenger godkjent og ubetalt. Last siden på nytt.' );
			}
			$old_date = (string) $order->get_meta( '_kc_event_date' );
			$old_time = (string) $order->get_meta( '_kc_event_time' );
			if ( $old_date === $date && $old_time === $time ) {
				// Unchanged (also the second click of a double-click): no new link, no e-mail.
				return array( 'ok' => true, 'message' => 'Hentetiden er allerede ' . KC_Core::event_datetime( $order ) . '. Ingen endring, ingen ny e-post.' );
			}
			$revision = (int) $order->get_meta( '_kc_revision' );
			if ( $seen_revision !== $revision ) {
				return array( 'ok' => false, 'message' => 'Ikke endret: ordren er endret siden siden ble åpnet. Last siden på nytt.' );
			}

			$dates = array_unique( array( $old_date, $date ) );
			sort( $dates ); // Fixed order: no two requests wait on each other.
			foreach ( $dates as $d ) {
				$name = KC_Core::capacity_lock_name( $d );
				$tok  = KC_Core::acquire_lock( $name );
				if ( ! $tok ) {
					return array( 'ok' => false, 'message' => 'Ikke endret: en annen endring for samme hentedato pågår. Prøv igjen om et øyeblikk.' );
				}
				$date_locks[] = array( $name, $tok );
			}

			$persons  = KC_Catalog::persons_in_order( $order );
			$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
			$booked   = KC_Intake::persons_on( $date, $order->get_id() );
			if ( $booked + $persons > $capacity ) {
				return array( 'ok' => false, 'message' => sprintf( 'Ikke endret: %s har bare plass til %s til (%d av %d reservert).', $date, KC_Core::persons_label( max( 0, $capacity - $booked ) ), $booked, $capacity ) );
			}

			global $wpdb;
			$old_text  = KC_Core::event_datetime( $order );
			$old_state = array(
				'key'  => $order->get_order_key(),
				'rev'  => $revision,
				'date' => $old_date,
				'time' => $old_time,
			);
			// 1.1.8: wpdb->query() returns false on error without throwing – check it.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wc_get_logger()->error( 'Catering: reschedule_approved START TRANSACTION failed: ' . $wpdb->last_error, array( 'source' => 'kebabish-catering' ) );
				return array( 'ok' => false, 'message' => 'Ikke endret: databasen kunne ikke starte endringen. Ingenting er lagret; gammel hentetid og betalingslenke gjelder. Prøv igjen.' );
			}
			$committed = false;
			try {
				self::$busy = true;
				$order->update_meta_data( '_kc_event_date', $date );
				$order->update_meta_data( '_kc_event_time', $time );
				$order->update_meta_data( '_kc_persons', $persons );
				$order->set_order_key( wc_generate_order_key() );
				$order->update_meta_data( '_kc_revision', $revision + 1 );
				$order->update_meta_data( '_kc_approval_active', 'yes' );
				$order->update_meta_data( '_kc_final_total', wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) );
				$order->update_meta_data( '_kc_final_currency', $order->get_currency() );
				$order->update_meta_data( '_kc_approved_at', time() );
				$order->update_meta_data( '_kc_approved_by', get_current_user_id() );
				$order->update_meta_data( '_kc_approval_reason', 'reschedule' );
				$order->delete_meta_data( '_kc_payment_mismatch' );
				if ( ! $order->has_status( KC_Core::STATUS_APPROVED ) ) {
					$order->set_status( KC_Core::STATUS_APPROVED );
				}
				$order->add_order_note( sprintf( 'Catering: hentetid endret fra %s til %s etter godkjenning. Revisjon %d → %d, samme total %s. Gammel betalingslenke ugyldig, ny lenke laget.', $old_text, KC_Core::event_datetime( $order ), $revision, $revision + 1, KC_Core::format_money( $order->get_total() ) ) );
				$expect_key = $order->get_order_key();
				$order->save();
				// WC_Order::save() catches its own exceptions and still returns the id, so a failed
				// save is invisible here. Verify against the database INSIDE the transaction.
				$check = self::fresh_order( $order_id );
				if ( ! $check
					|| $check->get_order_key() !== $expect_key
					|| (int) $check->get_meta( '_kc_revision' ) !== $revision + 1
					|| (string) $check->get_meta( '_kc_event_date' ) !== $date
					|| (string) $check->get_meta( '_kc_event_time' ) !== $time
					|| 'yes' !== $check->get_meta( '_kc_approval_active' ) ) {
					throw new Exception( 'save not persisted' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					throw new Exception( 'COMMIT failed: ' . $wpdb->last_error );
				}
				$committed = true;
			} catch ( Throwable $e ) {
				$rolled   = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rb_error = $rolled ? '' : $wpdb->last_error;
				if ( ! $rolled ) {
					// The transaction may still be open on this connection. Closing the connection makes
					// MySQL discard it; everything after (lock release, read-back) runs on a fresh one.
					// Otherwise the lock release would itself be rolled back at the end of the request.
					$wpdb->close();
					$wpdb->db_connect( false );
					$rolled = (bool) $wpdb->check_connection( false );
				}
				wc_get_logger()->error( 'Catering: reschedule_approved failed: ' . $e->getMessage() . ( '' === $rb_error ? ' (ROLLBACK ok)' : ' (ROLLBACK FAILED: ' . $rb_error . '; connection reset to discard transaction: ' . ( $rolled ? 'ok' : 'FAILED' ) . ')' ), array( 'source' => 'kebabish-catering' ) );
				// Never claim a rollback without reading the database back.
				$now = self::fresh_order( $order_id );
				$back_to_old = $rolled && $now
					&& $now->get_order_key() === $old_state['key']
					&& (int) $now->get_meta( '_kc_revision' ) === $old_state['rev']
					&& (string) $now->get_meta( '_kc_event_date' ) === $old_state['date']
					&& (string) $now->get_meta( '_kc_event_time' ) === $old_state['time'];
				if ( $back_to_old ) {
					return array( 'ok' => false, 'message' => 'Ikke endret: lagringen feilet og er rullet tilbake (kontrollert). Gammel hentetid og betalingslenke gjelder fortsatt.' );
				}
				return array( 'ok' => false, 'message' => 'FEIL: endringen av hentetid kunne ikke bekreftes i databasen. Ingen e-post er sendt. Last ordren på nytt og kontroller hentetid og betalingslenke før du prøver igjen. Detaljer er logget (WooCommerce → Status → Logger, kebabish-catering).' );
			} finally {
				self::$busy = false;
			}
		} finally {
			foreach ( array_reverse( $date_locks ) as $l ) {
				KC_Core::release_lock( $l[0], $l[1] );
			}
			KC_Core::release_lock( $order_lock, $order_tok );
		}

		// Only after a CONFIRMED commit (and released locks): send the e-mail. Failure is retryable
		// with the SAME link and revision via «Send på nytt» (type «approved»).
		if ( empty( $committed ) ) {
			return array( 'ok' => false, 'message' => 'FEIL: endringen ble ikke bekreftet. Ingen e-post er sendt.' );
		}
		$order = self::fresh_order( $order_id );
		$sent  = KC_Emails::customer_approved( $order );
		return array(
			'ok'      => $sent,
			'message' => $sent ? 'Hentetid endret. Kunden har fått ny e-post med ny hentetid og ny betalingslenke.' : 'Hentetid endret, men e-posten til kunden FEILET. Bruk «Send på nytt» (samme lenke).',
		);
	}

	/** @return true|string */
	private static function reschedule( WC_Order $order, $date, $time ) {
		// Same server rules as the order form, except customer-only lead time/horizon
		// (the operator may deliberately agree a short-notice or far-ahead pickup).
		$valid = self::validate_pickup( $date, $time );
		if ( true !== $valid ) {
			return $valid;
		}
		$persons = KC_Catalog::persons_in_order( $order );
		$lock    = KC_Core::capacity_lock_name( $date );
		$token   = KC_Core::acquire_lock( $lock );
		if ( ! $token ) {
			return 'Kunne ikke kontrollere kapasitet akkurat nå. Prøv igjen.';
		}
		$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
		$booked   = KC_Intake::persons_on( $date, $order->get_id() );
		if ( $booked + $persons > $capacity ) {
			KC_Core::release_lock( $lock, $token );
			return sprintf( 'Ikke flyttet: %s har bare plass til %s til (%d av %d reservert).', $date, KC_Core::persons_label( max( 0, $capacity - $booked ) ), $booked, $capacity );
		}
		$old = KC_Core::event_datetime( $order );
		$order->update_meta_data( '_kc_event_date', $date );
		$order->update_meta_data( '_kc_event_time', $time );
		$order->update_meta_data( '_kc_persons', $persons );
		$order->add_order_note( 'Catering: hentetid endret fra ' . $old . ' til ' . KC_Core::event_datetime( $order ) . '. Kunden får oppdatert hentetid i e-posten ved godkjenning.' );
		$order->save();
		KC_Core::release_lock( $lock, $token );
		return true;
	}

	/**
	 * R2: every approval is a new price revision with a NEW order key, so payment links from
	 * earlier revisions become invalid.
	 *
	 * @return true|string
	 */
	private static function approve( WC_Order $order, $via ) {
		if ( count( $order->get_items( 'line_item' ) ) < 1 ) {
			return 'Ordren har ingen varelinjer.';
		}
		$order->calculate_totals( wc_tax_enabled() );
		if ( (float) $order->get_total() <= 0 ) {
			return 'Totalen må være større enn 0.';
		}

		// Capacity gate (same lock as intake): approval never confirms an overbooked date.
		$date    = (string) $order->get_meta( '_kc_event_date' );
		$persons = KC_Catalog::persons_in_order( $order );
		$lock    = KC_Core::capacity_lock_name( $date );
		$token   = KC_Core::acquire_lock( $lock );
		if ( ! $token ) {
			return 'Kunne ikke kontrollere kapasitet akkurat nå. Prøv igjen.';
		}
		$others   = KC_Intake::persons_on( $date, $order->get_id() );
		$capacity = (int) KC_Core::int_setting( 'daily_capacity' );
		if ( $others + $persons > $capacity ) {
			KC_Core::release_lock( $lock, $token );
			return sprintf( 'Ikke godkjent: kapasiteten blir %d av %d personer denne dagen. Endre antall eller hentedato, eller øk kapasiteten i innstillingene.', $others + $persons, $capacity );
		}

		$revision = (int) $order->get_meta( '_kc_revision' ) + 1;
		self::$busy = true;
		$order->set_order_key( wc_generate_order_key() );
		$order->update_meta_data( '_kc_revision', $revision );
		$order->update_meta_data( '_kc_approval_active', 'yes' );
		$order->update_meta_data( '_kc_persons', $persons );
		$order->update_meta_data( '_kc_final_total', wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) );
		$order->update_meta_data( '_kc_final_currency', $order->get_currency() );
		$order->update_meta_data( '_kc_approved_at', time() );
		$order->update_meta_data( '_kc_approved_by', get_current_user_id() );
		$order->update_meta_data( '_kc_approval_reason', 'approve' );
		$order->delete_meta_data( '_kc_payment_mismatch' );
		if ( ! $order->has_status( KC_Core::STATUS_APPROVED ) ) {
			$order->set_status( KC_Core::STATUS_APPROVED );
		}
		$order->add_order_note( sprintf( 'Catering: godkjent (%s), revisjon %d, endelig total %s. Ny betalingslenke laget.', $via, $revision, KC_Core::format_money( $order->get_total() ) ) );
		$order->save();
		self::$busy = false;
		KC_Core::release_lock( $lock, $token );

		KC_Emails::customer_approved( $order );
		return true;
	}

	/** Invalidate the active approval and its payment link (revoke / cancel). */
	private static function close_approval( WC_Order $order ) {
		$order->update_meta_data( '_kc_approval_active', 'no' );
		$order->set_order_key( wc_generate_order_key() );
	}

	/**
	 * Status changes outside our buttons (status dropdown, bulk actions, gateways).
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( self::$busy || ! KC_Core::is_v1( $order ) || (int) $order_id === KC_Core::$paying_order_id ) {
			return;
		}
		// Dropdown approval = same as the button.
		if ( KC_Core::STATUS_APPROVED === $to && KC_Core::STATUS_RECEIVED === $from ) {
			$r = self::approve( $order, 'statusvalg' );
			if ( true !== $r ) {
				self::$busy = true;
				$order->set_status( KC_Core::STATUS_RECEIVED, 'Catering: godkjenning avvist – ' . $r );
				$order->save();
				self::$busy = false;
			}
			return;
		}
		// Leaving the payable states by hand closes the approval and its link.
		if ( in_array( $from, array( KC_Core::STATUS_APPROVED, 'failed' ), true ) && in_array( $to, array( KC_Core::STATUS_RECEIVED, 'cancelled', 'pending' ), true ) ) {
			self::$busy = true;
			self::close_approval( $order );
			$order->add_order_note( 'Catering: status endret manuelt. Godkjenning og betalingslenke er stengt.' );
			$order->save();
			self::$busy = false;
			return;
		}
		if ( in_array( $to, array( 'processing', 'completed' ), true ) && ! KC_Core::is_verified_paid( $order ) ) {
			$order->add_order_note( 'Catering: status endret manuelt til ' . $to . ' uten verifisert betaling. Ordren er IKKE betalt. Ingen bekreftelse er sendt.' );
		}
		if ( KC_Core::clear_unverified_date_paid( $order ) ) {
			self::$busy = true;
			$order->save();
			self::$busy = false;
		}
	}

	/* ================================================================== */
	/* Admin notices (flash)                                               */
	/* ================================================================== */

	/** 1.1.7: notices are appended (a double-click shows both results, none is lost). */
	private static function notice( $message, $ok ) {
		$key  = 'kc_notice_' . get_current_user_id();
		$list = get_transient( $key );
		$list = ( is_array( $list ) && isset( $list[0] ) ) ? $list : array();
		$list[] = array(
			'm'  => $message,
			'ok' => $ok,
		);
		set_transient( $key, array_slice( $list, -5 ), 60 );
	}

	public static function notices() {
		$key  = 'kc_notice_' . get_current_user_id();
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			return;
		}
		delete_transient( $key );
		$list = isset( $list['m'] ) ? array( $list ) : $list; // Older single-notice format.
		foreach ( $list as $data ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', ! empty( $data['ok'] ) ? 'success' : 'error', esc_html( (string) $data['m'] ) );
		}
	}

	/* ================================================================== */
	/* Overview: WooCommerce → Catering                                    */
	/* ================================================================== */

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Catering', 'Catering', 'edit_shop_orders', 'kc-overview', array( __CLASS__, 'render_overview' ) );
	}

	public static function render_overview() {
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 300,
				'status'     => array( KC_Core::STATUS_RECEIVED, KC_Core::STATUS_APPROVED, 'pending', 'on-hold', 'failed', 'processing', 'completed', 'cancelled' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_kc_schema',
						'value' => KC_Core::SCHEMA,
					),
				),
			)
		);
		usort(
			$orders,
			function ( $a, $b ) {
				return strcmp( $a->get_meta( '_kc_event_date' ) . $a->get_meta( '_kc_event_time' ), $b->get_meta( '_kc_event_date' ) . $b->get_meta( '_kc_event_time' ) );
			}
		);
		$today = wp_date( 'Y-m-d' );

		echo '<div class="wrap"><h1>Catering</h1>';
		echo '<p>Bestillinger sortert etter hentedato. <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=kebabish_catering' ) ) . '">Innstillinger</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Hentetid</th><th>Ordre</th><th>Kunde</th><th>Personer</th><th>Status</th><th>Total</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $orders as $order ) {
			if ( (string) $order->get_meta( '_kc_event_date' ) < $today && $order->has_status( array( 'completed', 'cancelled' ) ) ) {
				continue;
			}
			++$shown;
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			echo '<tr>';
			echo '<td>' . esc_html( KC_Core::event_datetime( $order ) ) . '</td>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $name ) . ( $order->get_billing_company() ? '<br><small>' . esc_html( $order->get_billing_company() ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $order->get_meta( '_kc_persons' ) ) . '</td>';
			echo '<td>' . esc_html( self::status_text( $order ) ) . '</td>';
			echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '</tr>';
		}
		if ( ! $shown ) {
			echo '<tr><td colspan="6">Ingen kommende cateringbestillinger.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
