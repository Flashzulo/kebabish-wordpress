<?php
/**
 * Tilleggsvurdering: a link page where Shammi marks each catering add-on keep / cut / unsure and may
 * propose a smaller portion and a new price. Shows which add-ons the occasion advisor needs and checks,
 * live, that every suggestion type still has a dish (Saulo 13.10).
 *
 * Access: a secret link (admin-post.php?action=kc_menu_review&t=TOKEN), no login, per Saulo's choice.
 * The token is created, rotated and switched off in WooCommerce → Catering-tillegg (manage_woocommerce).
 * The link can ONLY write the review meta _kc_review on published catering add-ons. Nothing else:
 * no prices, names, statuses or orders are changed. Admin exports the answers as CSV.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KC_Menu_Review {

	const OPT_TOKEN   = 'kc_review_token';
	const OPT_EXPIRES = 'kc_review_expires';
	const META        = '_kc_review';
	const TTL_DAYS    = 30;
	const SAVE_LIMIT  = 60; // Saves per IP per hour.

	/** Suggestion types the advisor uses (mirrors kc-advisor.js intents). */
	const INTENTS = array(
		array( 'label' => 'Vegetar', 'who' => 'Alle anledninger: «Vegetar»', 'tags' => array( 'vegetarian' ) ),
		array( 'label' => 'Mild mat', 'who' => 'Alle anledninger: «Mild mat»', 'tags' => array( 'mild' ) ),
		array( 'label' => 'Mild rett uten nøtter', 'who' => 'Mild + nøtteallergi (saus teller ikke)', 'tags' => array( 'mild' ), 'nutfree' => true ),
		array( 'label' => 'Til barna', 'who' => 'Fest & familie: barn i selskapet', 'tags' => array( 'kids', 'dessert' ) ),
		array( 'label' => 'Feiring', 'who' => 'Fest & familie: bursdag, Eid, familie', 'tags' => array( 'dessert' ) ),
		array( 'label' => 'Variasjon', 'who' => 'Fredag og Fest: «Variasjon»', 'tags' => array( 'sauce' ) ),
		array( 'label' => 'Mettende: protein', 'who' => 'Fredag: «Mettende mat»', 'tags' => array( 'protein' ) ),
		array( 'label' => 'Mettende: brød eller ris', 'who' => 'Fredag: «Mettende mat»', 'tags' => array( 'bread', 'rice' ) ),
		array( 'label' => 'Drikke', 'who' => 'Fredag: «Enkel organisering», og generelt', 'tags' => array( 'drink' ) ),
		array( 'label' => 'Fingermat', 'who' => 'Møte: «Enkel servering», «Variasjon»', 'tags' => array( 'finger' ) ),
	);

	/** The 12 add-ons the advisor needs (SKU from the import, name as fallback) and why. */
	const MUST = array(
		'KB-CAT-034' => array( 'Chana Masala', 'Vegetarforslaget. Uten nøtter.' ),
		'KB-CAT-032' => array( 'Butter Paneer', 'Vegetar, og trolig eneste milde rett uten nøtter (Shammi bekrefter).' ),
		'KB-CAT-017' => array( 'Chicken Korma', 'Forslaget når kunden vil ha mild mat.' ),
		'KB-CAT-053' => array( 'Gulab Jamun', 'Dessert til barn, bursdag og Eid.' ),
		'KB-CAT-045' => array( 'Kheera Raita', 'Saus når kunden vil ha variasjon. Også mild.' ),
		'KB-CAT-046' => array( 'Mint chutney', 'Billig saus som passer til alt.' ),
		'KB-CAT-007' => array( 'Seekh Kebab', 'Protein når kunden vil ha mettende mat.' ),
		'KB-CAT-038' => array( 'Tandoori Naan', 'Brød til mettende mat og som tilbehør.' ),
		'KB-CAT-044' => array( 'Plain / Pilau Rice', 'Ris når kunden vil ha mettende mat.' ),
		'KB-CAT-055' => array( 'Mango Lassi', 'Drikke. Barn liker den.' ),
		'KB-CAT-057' => array( 'Mineralvann 0,5 l', 'Drikke til møter og kontor.' ),
		'KB-CAT-001' => array( 'Vegetable Samosa', 'Fingermat til møter.' ),
	);

	/** Which must-keep dish covers each suggestion role (shown on reserve dishes). */
	const ROLE_OWNER = array(
		'vegetarian' => array( 'Chana Masala', 'Butter Paneer' ),
		'mild'       => array( 'Chicken Korma' ),
		'dessert'    => array( 'Gulab Jamun' ),
		'sauce'      => array( 'Kheera Raita', 'Mint chutney' ),
		'protein'    => array( 'Seekh Kebab' ),
		'bread'      => array( 'Tandoori Naan' ),
		'rice'       => array( 'Plain / Pilau Rice' ),
		'drink'      => array( 'Mango Lassi', 'Mineralvann 0,5 l' ),
		'finger'     => array( 'Vegetable Samosa' ),
	);

	const KEY_TAGS = array(
		'vegetarian' => 'vegetar',
		'mild'       => 'mild',
		'dessert'    => 'dessert',
		'sauce'      => 'saus',
		'protein'    => 'protein',
		'bread'      => 'brød',
		'rice'       => 'ris',
		'drink'      => 'drikke',
		'finger'     => 'fingermat',
	);

	public static function init() {
		add_action( 'admin_post_nopriv_kc_menu_review', array( __CLASS__, 'render_page' ) );
		add_action( 'admin_post_kc_menu_review', array( __CLASS__, 'render_page' ) );
		add_action( 'admin_post_nopriv_kc_menu_review_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_kc_menu_review_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_kc_menu_review_admin', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 60 );
	}

	/* ================================================================== */
	/* Token                                                               */
	/* ================================================================== */

	private static function valid_token( $given ) {
		$token   = (string) get_option( self::OPT_TOKEN, '' );
		$expires = (int) get_option( self::OPT_EXPIRES, 0 );
		if ( strlen( $token ) < 32 || ! is_string( $given ) || '' === $given ) {
			return false;
		}
		if ( $expires && time() > $expires ) {
			return false;
		}
		return hash_equals( $token, $given );
	}

	private static function token_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification -- the token itself is the credential.
		$t = isset( $_REQUEST['t'] ) ? wp_unslash( $_REQUEST['t'] ) : '';
		return is_string( $t ) ? preg_replace( '/[^A-Za-z0-9]/', '', $t ) : '';
	}

	public static function link() {
		$token = (string) get_option( self::OPT_TOKEN, '' );
		return $token ? add_query_arg( array( 'action' => 'kc_menu_review', 't' => $token ), admin_url( 'admin-post.php' ) ) : '';
	}

	private static function deny() {
		self::headers();
		status_header( 404 );
		echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Ikke funnet</title><p style="font-family:system-ui;padding:24px">Lenken er ikke gyldig lenger. Be Saulo om en ny.</p>';
		exit;
	}

	private static function headers() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	/* ================================================================== */
	/* Data                                                                */
	/* ================================================================== */

	private static function dishes() {
		$names = array();
		foreach ( self::MUST as $sku => $m ) {
			$names[ $m[0] ] = $sku;
		}
		$out = array();
		foreach ( KC_Catalog::addons() as $p ) {
			$sku  = (string) $p->get_sku();
			$tags = KC_Advisor::product_tags( $p );
			$rule = KC_Advisor::product_rule( $p );
			$desc = trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
			$key  = isset( self::MUST[ $sku ] ) ? $sku : ( $names[ $p->get_name() ] ?? '' );
			$hit  = array_values( array_intersect( array_keys( self::KEY_TAGS ), $tags ) );
			if ( $key ) {
				$status = 'must';
				$why    = self::MUST[ $key ][1];
			} elseif ( 'none' !== $rule && $hit ) {
				$status = 'reserve';
				$alts   = array();
				foreach ( $hit as $h ) {
					foreach ( self::ROLE_OWNER[ $h ] ?? array() as $n ) {
						$alts[ $n ] = true;
					}
				}
				unset( $alts[ $p->get_name() ] );
				$why = 'Samme rolle som ' . implode( ' / ', array_keys( $alts ) ) . '. Kan kuttes – men behold hvis den selger godt.';
			} else {
				$status = 'free';
				$why    = 'Påvirker ikke forslagene. Behold hvis den selger godt.';
			}
			$rev   = $p->get_meta( self::META );
			$group = trim( (string) $p->get_meta( '_kc_group' ) );
			$out[] = array(
				'id'     => (int) $p->get_id(),
				'sku'    => $sku,
				'name'   => $p->get_name(),
				'price'  => (float) $p->get_price(),
				'unit'   => KC_Catalog::unit( $p ),
				'group'  => '' !== $group ? $group : 'Annet',
				'tags'   => $tags,
				'rule'   => $rule,
				'nut'    => (bool) preg_match( '/nøtt|cashew|mandel|pistasj/iu', $desc ),
				'desc'   => $desc,
				'status' => $status,
				'why'    => $why,
				'rev'    => is_array( $rev ) ? $rev : array(),
				'sug'    => self::suggestion( $p->get_name(), '' !== $group ? $group : 'Annet', KC_Catalog::unit( $p ), $tags, (float) $p->get_price() ),
			);
		}
		return $out;
	}

	/** Price rounded up to a menu price ending in 9 (min 19). */
	private static function menu_price( $x ) {
		return max( 19, (int) ( ceil( $x / 10 ) * 10 - 1 ) );
	}

	/**
	 * Our proposal for a catering add-on portion (per person where the advisor counts per guest) and a
	 * matching price. null price = keep today's price. Shammi can change both on the page.
	 */
	private static function suggestion( $name, $group, $unit, array $tags, $price ) {
		$n = mb_strtolower( $name );
		$f = null;
		if ( false !== strpos( $n, 'ship for' ) ) {
			$s = 'Som i dag – hele fatet til 4 personer';
		} elseif ( false !== strpos( $n, 'seekh' ) ) {
			$s = '1 spyd per person (i dag 4 spyd per enhet)';
			$f = 0.25;
		} elseif ( false !== strpos( $n, 'chops' ) && false === strpos( $n, 'mixed' ) ) {
			$s = '1 kotelett per person (i dag 5 per porsjon)';
			$f = 0.2;
		} elseif ( false !== strpos( $n, 'wings' ) ) {
			$s = '3 vinger per person';
			$f = 0.45;
		} elseif ( 'Grillretter' === $group ) {
			$s = 'Liten porsjon per person (ca. 3–4 biter)';
			$f = 0.45;
		} elseif ( in_array( $group, array( 'Curry & Karahi', 'Kjøttretter', 'Vegetar' ), true ) ) {
			$s = 'Liten porsjon per person (ca. 150 g)';
			$f = 0.45;
		} elseif ( 'Biryani' === $group ) {
			$s = 'Liten porsjon per person (ca. 200 g)';
			$f = 0.45;
		} elseif ( 'Dessert' === $group ) {
			$s = 'Liten porsjon per person (1–2 stk)';
			$f = 0.5;
		} elseif ( in_array( 'drink', $tags, true ) ) {
			$s = '1 per person – som i dag';
		} elseif ( in_array( 'bread', $tags, true ) ) {
			$s = '1 per person – som i dag';
		} elseif ( in_array( 'rice', $tags, true ) ) {
			$s = '1 porsjon per 4 personer – som i dag';
		} elseif ( in_array( 'sauce', $tags, true ) ) {
			$s = '1 boks per bestilling (til ca. 10 personer)';
		} elseif ( false !== strpos( $n, 'salat' ) ) {
			$s = '1 bolle per 5 personer';
		} elseif ( 'piece' === $unit && in_array( 'finger', $tags, true ) && false === strpos( $n, 'roll' ) ) {
			$s = '1 stk per person – pris per stk';
		} elseif ( false !== strpos( $n, 'roll' ) ) {
			$s = 'Halv rull per person';
			$f = 0.5;
		} elseif ( 'portion' === $unit && in_array( $group, array( 'Forretter', 'Snacks' ), true ) ) {
			$s = 'Liten porsjon per person';
			$f = 0.5;
		} else {
			$s = 'Som i dag';
		}
		return array(
			'portion' => $s,
			'price'   => null === $f ? null : self::menu_price( $price * $f ),
		);
	}

	private static function covering( array $intent, array $dishes ) {
		$out = array();
		foreach ( $dishes as $d ) {
			if ( 'none' === $d['rule'] || 'cut' === ( $d['rev']['decision'] ?? '' ) ) {
				continue;
			}
			if ( ! array_intersect( $intent['tags'], $d['tags'] ) ) {
				continue;
			}
			if ( ! empty( $intent['nutfree'] ) && ( $d['nut'] || in_array( 'sauce', $d['tags'], true ) ) ) {
				continue;
			}
			$out[] = $d['name'];
		}
		return $out;
	}

	/* ================================================================== */
	/* Public page                                                         */
	/* ================================================================== */

	public static function render_page() {
		$token = self::token_from_request();
		if ( ! self::valid_token( $token ) ) {
			self::deny();
		}
		$dishes = self::dishes();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		$saved = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : '';
		$ver   = KC_VERSION . '-rev2';
		self::headers();

		$sections = array(
			'must'    => array( 'Behold', 'Disse driver forslagene til kundene. Kuttes de, forsvinner forslaget.', array() ),
			'reserve' => array( 'Valgfri', 'Har samme rolle som en rett over. Kan kuttes – men behold det som selger godt.', array() ),
			'free'    => array( 'Din vurdering', 'Påvirker ikke forslagene. Vurder bare ut fra salg og kjøkken.', array() ),
		);
		foreach ( $dishes as $d ) {
			$sections[ $d['status'] ][2][] = $d;
		}
		$done = 0;
		foreach ( $dishes as $d ) {
			$done += '' !== (string) ( $d['rev']['decision'] ?? '' ) ? 1 : 0;
		}
		$broken = array();
		foreach ( self::INTENTS as $it ) {
			if ( empty( $it['nutfree'] ) && ! self::covering( $it, $dishes ) ) {
				$broken[] = $it['label'];
			}
		}
		?>
<!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title>Kebabish tilleggsmeny</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Source+Sans+3:wght@400;600;700&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( KC_URL . 'assets/kc-menu-review.css?ver=' . rawurlencode( $ver ) ); ?>">
</head>
<body>
<form class="wrap" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="kcr">
	<input type="hidden" name="action" value="kc_menu_review_save">
	<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
	<?php wp_nonce_field( 'kc_menu_review_' . substr( $token, 0, 12 ), 'kcr_nonce', false ); ?>
	<p class="kicker">Kebabish catering</p>
	<h1>Tilleggsmenyen: hva beholder vi?</h1>
	<div class="why-box">
		<p><b>Slik selger vi catering:</b> Kunden velger først en av de <b>3 pakkene</b> (Grill, Curry eller Mix) som grunnmat. Alt annet bestilles som <b>tillegg</b>.</p>
		<p><b>Tilleggene er mersalget.</b> Kunden svarer på noen korte spørsmål (vegetar, mild mat, barn, feiring osv.). Ut fra svarene foreslår nettsiden 3 tillegg som passer akkurat dem. Hvert ja øker bestillingen.</p>
		<p>Derfor må noen retter stå: uten dem har nettsiden ingenting å foreslå, og vi mister salget. Resten kan du vurdere ut fra hva som selger og hva kjøkkenet rekker.</p>
	</div>
	<ul class="howto">
		<li><span class="tag must">Behold</span> <span>12 retter kundene får foreslått. Bør stå.</span></li>
		<li><span class="tag reserve">Valgfri</span> <span>Kan kuttes, så lenge «Behold»-retten med samme rolle står.</span></li>
		<li><span class="tag free">Din vurdering</span> <span>Påvirker ikke forslagene. Behold det som selger.</span></li>
	</ul>
	<p class="lede">Trykk <b>Behold</b> eller <b>Kutt</b> på hver rett, og <b>Lagre</b> nederst. Ingenting endres på nettsiden før Saulo har gått gjennom svarene.</p>
	<p class="lede"><b>Porsjon og pris er ferdig foreslått</b> på hver rett: små porsjoner per person, fordi tilleggene skal passe til antall gjester. Er forslaget greit, trenger du ikke gjøre noe. Er det feil, trykk «Endre» og skriv riktig.</p>
	<?php if ( '1' === $saved ) : ?>
		<p class="notice" role="status">Lagret. Takk!</p>
	<?php elseif ( 'limit' === $saved ) : ?>
		<p class="notice err" role="alert">For mange lagringer på kort tid. Vent litt og prøv igjen.</p>
	<?php endif; ?>

	<div class="check<?php echo $broken ? ' bad' : ''; ?>" id="check" aria-live="polite">
		<?php if ( $broken ) : ?>
			<b>Disse forslagene forsvinner med valgene dine:</b> <?php echo esc_html( implode( ', ', $broken ) ); ?>. Behold minst én rett i hver.
		<?php else : ?>
			<b>Alle forslagene virker</b> med valgene dine.
		<?php endif; ?>
	</div>
	<p class="progress" id="progress"><?php echo esc_html( $done . ' av ' . count( $dishes ) . ' retter merket' ); ?></p>

	<?php foreach ( $sections as $key => $sec ) : ?>
		<?php
		if ( ! $sec[2] ) {
			continue;
		}
		?>
		<section class="sec sec-<?php echo esc_attr( $key ); ?>">
			<h2><span class="tag <?php echo esc_attr( $key ); ?>"><?php echo esc_html( $sec[0] ); ?></span> <?php echo esc_html( count( $sec[2] ) . ' retter' ); ?></h2>
			<p class="sechelp"><?php echo esc_html( $sec[1] ); ?></p>
			<?php foreach ( $sec[2] as $d ) : ?>
				<?php
				$r   = $d['rev'];
				$dec = (string) ( $r['decision'] ?? '' );
				$id  = (int) $d['id'];
				$pv  = array_key_exists( 'portion', $r ) && '' !== (string) $r['portion'] ? (string) $r['portion'] : $d['sug']['portion'];
				$sp  = null !== $d['sug']['price'] ? $d['sug']['price'] : (int) round( $d['price'] );
				$rv  = array_key_exists( 'price', $r ) && '' !== (string) $r['price'] ? (string) $r['price'] : (string) $sp;
				?>
				<div class="dish" data-status="<?php echo esc_attr( $d['status'] ); ?>" data-tags="<?php echo esc_attr( implode( ',', $d['tags'] ) ); ?>" data-rule="<?php echo esc_attr( $d['rule'] ); ?>" data-nut="<?php echo $d['nut'] ? '1' : '0'; ?>" data-name="<?php echo esc_attr( $d['name'] ); ?>">
					<div class="info">
						<p class="top"><span class="name"><?php echo esc_html( $d['name'] ); ?></span> <span class="price"><?php echo esc_html( 'kr ' . number_format( $d['price'], 0, ',', ' ' ) ); ?></span><?php if ( $d['nut'] ) : ?> <span class="nut">nøtter</span><?php endif; ?></p>
						<p class="why"><?php echo esc_html( $d['why'] ); ?></p>
						<p class="sug"><b>Forslag:</b> <?php echo esc_html( $d['sug']['portion'] ); ?><?php if ( null !== $d['sug']['price'] ) : ?> · <b><?php echo esc_html( 'kr ' . $d['sug']['price'] ); ?></b> <span class="was"><?php echo esc_html( '(i dag kr ' . number_format( $d['price'], 0, ',', ' ' ) . ')' ); ?></span><?php endif; ?></p>
					</div>
					<div class="seg" role="radiogroup" aria-label="<?php echo esc_attr( 'Beholde ' . $d['name'] . '?' ); ?>">
						<?php foreach ( array( 'keep' => 'Behold', 'cut' => 'Kutt', 'unsure' => '?' ) as $v => $l ) : ?>
							<label><input type="radio" name="rev[<?php echo esc_attr( $id ); ?>][decision]" value="<?php echo esc_attr( $v ); ?>"<?php checked( $dec, $v ); ?><?php echo 'unsure' === $v ? ' aria-label="Usikker"' : ''; ?>><span><?php echo esc_html( $l ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<p class="cutwarn" data-cutwarn hidden></p>
					<details class="more">
						<summary>Endre porsjon, pris eller skriv kommentar</summary>
						<div class="fields">
							<label for="p-<?php echo esc_attr( $id ); ?>">Ny porsjon<input id="p-<?php echo esc_attr( $id ); ?>" type="text" maxlength="80" name="rev[<?php echo esc_attr( $id ); ?>][portion]" value="<?php echo esc_attr( $pv ); ?>"></label>
							<label for="r-<?php echo esc_attr( $id ); ?>">Ny pris (kr)<input id="r-<?php echo esc_attr( $id ); ?>" type="text" inputmode="decimal" maxlength="10" name="rev[<?php echo esc_attr( $id ); ?>][price]" value="<?php echo esc_attr( $rv ); ?>"></label>
							<label for="n-<?php echo esc_attr( $id ); ?>" class="wide">Kommentar<textarea id="n-<?php echo esc_attr( $id ); ?>" rows="2" maxlength="300" name="rev[<?php echo esc_attr( $id ); ?>][note]"><?php echo esc_textarea( (string) ( $r['note'] ?? '' ) ); ?></textarea></label>
						</div>
					</details>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>

	<details class="explain">
		<summary>Hvorfor akkurat disse? Slik virker forslagene</summary>
		<p>Kunden svarer på noen spørsmål. Ut fra svarene foreslår nettsiden høyst tre tillegg, hvert med en kort grunn. Kunden velger selv – ingenting legges til automatisk.</p>
		<ul>
			<li><b>Alle anledninger:</b> vegetar → vegetarrett · mild mat → mild rett</li>
			<li><b>Fredag på kontoret:</b> variasjon → saus · mettende → kebab, brød eller ris · enkel → drikke</li>
			<li><b>Møte &amp; team:</b> enkel servering → samosa · variasjon → saus eller fingermat</li>
			<li><b>Fest &amp; familie:</b> barn → dessert til barna · bursdag/Eid → dessert · variasjon → saus</li>
		</ul>
		<p>Hver rolle trenger bare én rett. Derfor holder det å beholde én vegetarrett, én dessert, én saus osv. Resten kan kuttes uten at forslagene slutter å virke.</p>
		<p><b>Porsjoner:</b> Mange tillegg er i dag hele restaurantretter. Små porsjoner per person gjør at antallet nettsiden foreslår stemmer, og at prisen blir lettere å si ja til.</p>
	</details>

	<div class="savebar">
		<span id="dirty">Husk å lagre.</span>
		<button type="submit">Lagre</button>
	</div>
</form>
<script type="application/json" id="kcr-intents"><?php echo wp_json_encode( self::INTENTS ); ?></script>
<script src="<?php echo esc_url( KC_URL . 'assets/kc-menu-review.js?ver=' . rawurlencode( $ver ) ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	/* ================================================================== */
	/* Save (link)                                                         */
	/* ================================================================== */

	public static function save() {
		$token = self::token_from_request();
		if ( ! self::valid_token( $token ) ) {
			self::deny();
		}
		$back = add_query_arg( array( 'action' => 'kc_menu_review', 't' => $token ), admin_url( 'admin-post.php' ) );
		$nonce = isset( $_POST['kcr_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['kcr_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'kc_menu_review_' . substr( $token, 0, 12 ) ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$ipkey = 'kc_rev_rl_' . md5( (string) WC_Geolocation::get_ip_address() . wp_salt( 'nonce' ) );
		$n     = (int) get_transient( $ipkey );
		if ( $n >= self::SAVE_LIMIT ) {
			wp_safe_redirect( add_query_arg( 'saved', 'limit', $back ) );
			exit;
		}
		set_transient( $ipkey, $n + 1, HOUR_IN_SECONDS );

		$allowed = array();
		foreach ( KC_Catalog::addons() as $p ) {
			$allowed[ (int) $p->get_id() ] = $p;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized below.
		$raw = isset( $_POST['rev'] ) && is_array( $_POST['rev'] ) ? wp_unslash( $_POST['rev'] ) : array();
		foreach ( $raw as $id => $row ) {
			$id = absint( $id );
			if ( ! isset( $allowed[ $id ] ) || ! is_array( $row ) ) {
				continue;
			}
			$row = array_filter( $row, 'is_scalar' );
			$dec = sanitize_key( (string) ( $row['decision'] ?? '' ) );
			$new = array(
				'decision' => in_array( $dec, array( 'keep', 'cut', 'unsure' ), true ) ? $dec : '',
				'portion'  => mb_substr( sanitize_text_field( (string) ( $row['portion'] ?? '' ) ), 0, 80 ),
				'price'    => mb_substr( preg_replace( '/[^0-9.,]/', '', (string) ( $row['price'] ?? '' ) ), 0, 10 ),
				'note'     => mb_substr( sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ), 0, 300 ),
			);
			$old = get_post_meta( $id, self::META, true );
			$old = is_array( $old ) ? $old : array();
			unset( $old['at'] );
			if ( $old === $new || ( ! $old && '' === implode( '', $new ) ) ) {
				continue;
			}
			$new['at'] = time();
			update_post_meta( $id, self::META, $new ); // Review meta only; the product itself is not saved or changed.
		}
		wp_safe_redirect( add_query_arg( 'saved', '1', $back ) . '#check' );
		exit;
	}

	/* ================================================================== */
	/* Admin: link management + CSV export                                 */
	/* ================================================================== */

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'Catering-tillegg', 'Catering-tillegg', 'manage_woocommerce', 'kc-menu-review', array( __CLASS__, 'render_admin' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$link    = self::link();
		$expires = (int) get_option( self::OPT_EXPIRES, 0 );
		$active  = $link && ( ! $expires || time() <= $expires );
		$post    = admin_url( 'admin-post.php' );
		echo '<div class="wrap"><h1>Catering-tillegg: vurdering</h1>';
		echo '<p>Lenke der Shammi merker tilleggene som behold, kutt eller usikker, og foreslår porsjon og pris. Lenken krever ikke innlogging og kan bare lagre vurderinger, ikke endre produkter. Del den bare med Shammi.</p>';
		if ( $active ) {
			echo '<p><strong>Lenke (gyldig til ' . esc_html( wp_date( 'd.m.Y H:i', $expires ) ) . '):</strong></p>';
			echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr( $link ) . '" onclick="this.select()"></p>';
		} else {
			echo '<p><strong>Ingen aktiv lenke.</strong></p>';
		}
		echo '<form method="post" action="' . esc_url( $post ) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( 'kc_menu_review_admin', 'kcr_admin_nonce' );
		echo '<input type="hidden" name="action" value="kc_menu_review_admin"><input type="hidden" name="do" value="new">';
		submit_button( $active ? 'Lag ny lenke (den gamle slutter å virke)' : 'Lag lenke', 'primary', 'submit', false );
		echo '</form>';
		if ( $active ) {
			echo '<form method="post" action="' . esc_url( $post ) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field( 'kc_menu_review_admin', 'kcr_admin_nonce' );
			echo '<input type="hidden" name="action" value="kc_menu_review_admin"><input type="hidden" name="do" value="off">';
			submit_button( 'Slå av lenken', 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '<form method="post" action="' . esc_url( $post ) . '" style="display:inline-block">';
		wp_nonce_field( 'kc_menu_review_admin', 'kcr_admin_nonce' );
		echo '<input type="hidden" name="action" value="kc_menu_review_admin"><input type="hidden" name="do" value="csv">';
		submit_button( 'Last ned svar (CSV)', 'secondary', 'submit', false );
		echo '</form>';

		$labels = array( 'keep' => 'Behold', 'cut' => 'Kutt', 'unsure' => 'Usikker', '' => '—' );
		echo '<h2>Svar så langt</h2><table class="widefat striped"><thead><tr><th>Rett</th><th>Rolle</th><th>Beslutning</th><th>Ny porsjon</th><th>Ny pris</th><th>Kommentar</th></tr></thead><tbody>';
		foreach ( self::dishes() as $d ) {
			$r = $d['rev'];
			echo '<tr><td>' . esc_html( $d['name'] ) . '</td><td>' . esc_html( array( 'must' => 'Må beholdes', 'reserve' => 'Reserve', 'free' => 'Fritt å kutte' )[ $d['status'] ] ) . '</td><td>' . esc_html( $labels[ $r['decision'] ?? '' ] ?? '—' ) . '</td><td>' . esc_html( (string) ( $r['portion'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $r['price'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $r['note'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function admin_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Ingen tilgang.', 403 );
		}
		check_admin_referer( 'kc_menu_review_admin', 'kcr_admin_nonce' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$back = admin_url( 'admin.php?page=kc-menu-review' );
		if ( 'new' === $do ) {
			update_option( self::OPT_TOKEN, wp_generate_password( 40, false, false ), false );
			update_option( self::OPT_EXPIRES, time() + self::TTL_DAYS * DAY_IN_SECONDS, false );
		} elseif ( 'off' === $do ) {
			delete_option( self::OPT_TOKEN );
			delete_option( self::OPT_EXPIRES );
		} elseif ( 'csv' === $do ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="catering-tillegg-vurdering-' . wp_date( 'Y-m-d' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array( 'ID', 'SKU', 'Navn', 'Gruppe', 'Pris nå', 'Rolle', 'Beslutning', 'Ny porsjon', 'Ny pris', 'Kommentar', 'Merker', 'Forslagsregel' ), ';' );
			foreach ( self::dishes() as $d ) {
				$r = $d['rev'];
				$row = array( $d['id'], $d['sku'], $d['name'], $d['group'], $d['price'], $d['status'], $r['decision'] ?? '', $r['portion'] ?? '', $r['price'] ?? '', $r['note'] ?? '', implode( ',', $d['tags'] ), $d['rule'] );
				// Neutralise spreadsheet formulas in free text.
				$row = array_map( function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; }, $row );
				fputcsv( $out, $row, ';' );
			}
			fclose( $out );
			exit;
		}
		wp_safe_redirect( $back );
		exit;
	}
}
