<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( 'assets/css/editor-style.css' );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues the theme stylesheet on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src    = 'style' . $suffix . '.css';

		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( $src ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
		wp_style_add_data(
			'twentytwentyfive-style',
			'path',
			get_parent_theme_file_path( $src )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

/**
 * Kebabish Original Oslo — draft visual layer.
 */
function kebabish_draft_enqueue_styles() {
	$stylesheet = get_theme_file_path( 'assets/css/kebabish.css' );
	wp_enqueue_style(
		'kebabish-draft',
		get_theme_file_uri( 'assets/css/kebabish.css' ),
		array( 'twentytwentyfive-style' ),
		file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'kebabish_draft_enqueue_styles', 20 );

if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_register_block_bindings' );

if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;

/**
 * Show a branded coming-soon page to logged-out visitors.
 * Logged-in users, wp-admin, login, AJAX, cron and REST remain available.
 */
function kebabish_guest_coming_soon() {
	if (
		is_user_logged_in()
		|| is_admin()
		|| wp_doing_ajax()
		|| wp_doing_cron()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';

	if (
		false !== strpos( $request_uri, 'wp-login.php' )
		|| false !== strpos( $request_uri, 'wp-admin' )
	) {
		return;
	}

	status_header( 200 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	?>
	<!doctype html>
	<html lang="nb">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Kebabish Original Oslo – Kommer snart</title>
		<style>
			:root{--ivory:#f4eedf;--charcoal:#151714;--gold:#bd9650;--red:#c51922}
			*{box-sizing:border-box}
			html,body{margin:0;min-height:100%;background:var(--charcoal);color:var(--ivory)}
			body{font-family:Arial,sans-serif}
			main{position:relative;min-height:100vh;display:grid;place-items:center;padding:36px 22px;overflow:hidden;background:radial-gradient(circle at 50% 42%,rgba(189,150,80,.12),transparent 32%),linear-gradient(145deg,#0b0d0b,#151714)}
			main:before{content:"";position:absolute;inset:22px;border:1px solid rgba(189,150,80,.38);pointer-events:none}
			.card{position:relative;z-index:1;max-width:720px;text-align:center}
			img{display:block;width:clamp(138px,22vw,205px);height:auto;margin:0 auto 34px;filter:drop-shadow(0 24px 40px rgba(0,0,0,.32))}
			.eyebrow{margin:0 0 18px;color:var(--gold);font-size:11px;font-weight:700;letter-spacing:.28em;text-transform:uppercase}
			h1{margin:0;font-family:Georgia,serif;font-size:clamp(54px,11vw,118px);font-weight:400;line-height:.88;letter-spacing:-.055em}
			.slogan{margin:25px 0 0;color:var(--ivory);font-family:Georgia,serif;font-size:clamp(20px,3vw,31px);font-style:italic}
			.rule{display:block;width:64px;height:2px;margin:31px auto;background:var(--red)}
			.address{margin:0;color:rgba(244,238,223,.62);font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase}
		</style>
	</head>
	<body>
		<main>
			<div class="card">
				<img src="https://kebabishoslo.no/wp-content/uploads/2026/09/Kebabish-Oslo-hovedlogo-–-Refined-Heritage-Seal.png" alt="Kebabish Original Oslo">
				<p class="eyebrow">Kebabish Original Oslo</p>
				<h1>Kommer snart</h1>
				<p class="slogan">Thrill of the Grill</p>
				<span class="rule" aria-hidden="true"></span>
				<p class="address">Breigata 12 · Grønland · Oslo</p>
			</div>
		</main>
	</body>
	</html>
	<?php
	exit;
}
add_action( 'template_redirect', 'kebabish_guest_coming_soon', -100 );
