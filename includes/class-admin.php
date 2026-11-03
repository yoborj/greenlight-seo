<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The Greenlight SEO admin page: Overview, Business, Schema, Checks, Links, llms.txt.
 */
class Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_greenlight_seo_scan', array( __CLASS__, 'handle_scan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GREENLIGHT_SEO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		// A magnifier on a traffic-light body: Greenlight's sibling. WordPress recolours it to match the admin theme.
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zm0 1.8a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4z"/></svg>' );
		add_menu_page( 'Greenlight SEO', 'Greenlight SEO', 'manage_options', 'greenlight-seo', array( __CLASS__, 'page' ), $icon, 82 );
	}

	public static function register() {
		register_setting( 'greenlight_seo', Settings::OPTION, array( 'sanitize_callback' => array( 'Greenlight_SEO\\Settings', 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_greenlight-seo' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'greenlight-seo-admin', GREENLIGHT_SEO_URL . 'assets/admin.css', array(), GREENLIGHT_SEO_VERSION );
		wp_enqueue_script( 'greenlight-seo-admin', GREENLIGHT_SEO_URL . 'assets/admin.js', array(), GREENLIGHT_SEO_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=greenlight-seo' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'greenlight_seo_scan' ) ) {
			wp_die( 'Not allowed.' );
		}
		$n = Checks::start_all();
		Links::start_index();
		set_transient( 'greenlight_seo_notice_' . get_current_user_id(), "Checking {$n} page(s) and rebuilding the link index in the background. Refresh this page in a minute.", 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=greenlight-seo&tab=' . sanitize_key( wp_unslash( $_GET['tab'] ?? 'checks' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		exit;
	}

	private static function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	private static function switch_row( $key, $label, $desc, array $features, array $s ) {
		$f       = $features[ $key ] ?? null;
		$blocked = $f && ! $f['on'] && 'switched off' !== $f['why'];
		?>
		<div class="gls-row<?php echo $blocked ? ' is-blocked' : ''; ?>">
			<div class="gls-row-text">
				<label class="gls-row-label" for="gls-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<p><?php echo wp_kses( $desc, array( 'code' => array(), 'strong' => array(), 'a' => array( 'href' => array() ) ) ); ?></p>
				<?php if ( $blocked ) : ?>
					<p class="gls-why">Not running: <?php echo esc_html( $f['why'] ); ?>.</p>
				<?php endif; ?>
			</div>
			<label class="gls-switch">
				<input type="checkbox" id="gls-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>>
				<span class="gls-slider" aria-hidden="true"></span>
			</label>
		</div>
		<?php
	}

	private static function field( $key, $label, array $s, $type = 'text', $help = '', $prefix = null, $value = null ) {
		$name  = $prefix ? $prefix . '[' . $key . ']' : self::name( $key );
		$value = null !== $value ? $value : ( $s[ $key ] ?? '' );
		$id    = 'gls-' . md5( $name );
		?>
		<label class="gls-field" for="<?php echo esc_attr( $id ); ?>">
			<span><?php echo esc_html( $label ); ?><?php echo $help ? ' <small>' . esc_html( $help ) . '</small>' : ''; ?></span>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input id="<?php echo esc_attr( $id ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endif; ?>
		</label>
		<?php
	}

	private static function place_fields( array $p, $prefix ) {
		echo '<div class="gls-grid">';
		self::field( 'name', 'Name', $p, 'text', Settings::OPTION === $prefix ? 'default: the site title' : 'default: the business name', $prefix );
		self::field( 'phone', 'Phone', $p, 'tel', '', $prefix );
		self::field( 'street', 'Street address', $p, 'text', '', $prefix );
		self::field( 'city', 'City', $p, 'text', '', $prefix );
		self::field( 'region', 'State / region', $p, 'text', 'e.g. CO', $prefix );
		self::field( 'postal', 'ZIP / postal code', $p, 'text', '', $prefix );
		self::field( 'country', 'Country', $p, 'text', 'two letters', $prefix );
		self::field( 'email', 'Email', $p, 'email', '', $prefix );
		self::field( 'lat', 'Latitude', $p, 'text', '5+ decimals', $prefix );
		self::field( 'lng', 'Longitude', $p, 'text', '5+ decimals', $prefix );
		echo '</div>';
		self::field( 'hours', 'Opening hours', $p, 'textarea', 'one line each: Mo-Fr 08:00-17:00 · Sa 09:00-12:00 · Su closed', $prefix );
	}

	private static function page_select( $name, $selected, $none ) {
		// phpcs:ignore WordPress.Security.EscapeOutput -- wp_dropdown_pages escapes.
		echo wp_dropdown_pages( array( 'name' => $name, 'selected' => (int) $selected, 'show_option_none' => $none, 'option_none_value' => 0, 'echo' => 0 ) );
	}

	private static function link_to( $id ) {
		return '<a href="' . esc_url( get_edit_post_link( $id ) ?: get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ?: '(no title)' ) . '</a> <a class="gls-url" href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( wp_make_link_relative( get_permalink( $id ) ) ) . '</a>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = Settings::all();
		$features  = Detect::features();
		$conflicts = Detect::conflicts();
		$problems  = Schema::problems();
		$flagged   = Checks::flagged();
		$dupes     = Checks::duplicate_groups();
		$links     = Links::report();
		$seo       = Detect::seo_plugin();
		$notice    = get_transient( 'greenlight_seo_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'greenlight_seo_notice_' . get_current_user_id() );
		}
		$tabs   = array( 'overview' => 'Overview', 'business' => 'Business', 'schema' => 'Schema', 'checks' => 'Checks', 'links' => 'Links', 'llms' => 'llms.txt' );
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$active = isset( $tabs[ $active ] ) ? $active : 'overview';
		$scan   = wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_seo_scan' ), 'greenlight_seo_scan' );
		$on     = count( array_filter( $features, function ( $f ) { return $f['on']; } ) );
		$queued = Checks::queued() + Links::queued();
		$labels = array(
			'local_schema'     => 'LocalBusiness schema',
			'service_schema'   => 'Service schema',
			'checks_on_save'   => 'Checks on save',
			'link_suggestions' => 'Link suggestions',
			'llms_txt'         => 'llms.txt',
		);
		?>
		<div class="wrap gls-wrap">
			<h1 class="screen-reader-text">Greenlight SEO</h1>
			<form method="post" action="options.php" id="gls-app" class="gls-app">
				<?php settings_fields( 'greenlight_seo' ); ?>
				<header class="gls-head">
					<div class="gls-brand">
						<span class="gls-logo" aria-hidden="true"><svg viewBox="0 0 20 20" width="26" height="26"><path fill="currentColor" fill-rule="evenodd" d="M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zm0 1.8a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4z"/></svg></span>
						<div>
							<div class="gls-brand-name">Greenlight SEO <span class="gls-version">v<?php echo esc_html( GREENLIGHT_SEO_VERSION ); ?></span></div>
							<div class="gls-brand-tag">Local SEO that works alongside <?php echo esc_html( $seo ?: 'your SEO plugin' ); ?>, never against it</div>
						</div>
					</div>
					<ul class="gls-chips" aria-label="Status">
						<li class="gls-chip<?php echo $seo ? ' is-good' : ''; ?>"><?php echo esc_html( $seo ? $seo . ' detected' : 'No SEO plugin detected' ); ?></li>
						<li class="gls-chip is-good"><?php echo (int) $on; ?> of <?php echo count( $features ); ?> features running</li>
						<li class="gls-chip <?php echo $conflicts ? 'is-warn' : 'is-good'; ?>"><?php echo $conflicts ? (int) count( $conflicts ) . ' overlapping plugin' . ( 1 === count( $conflicts ) ? '' : 's' ) : 'No conflicts'; ?></li>
					</ul>
				</header>
				<?php settings_errors(); ?>
				<?php if ( $notice ) : ?>
					<div class="gls-toast" role="status"><?php echo esc_html( $notice ); ?></div>
				<?php endif; ?>

				<nav class="gls-tabs" role="tablist" aria-label="Greenlight SEO">
					<?php foreach ( $tabs as $slug => $title ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=' . $slug ) ); ?>" role="tab" class="gls-tab" data-tab="<?php echo esc_attr( $slug ); ?>" aria-selected="<?php echo $slug === $active ? 'true' : 'false'; ?>"><?php echo esc_html( $title ); ?></a>
					<?php endforeach; ?>
				</nav>
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( admin_url( 'admin.php?page=greenlight-seo&tab=' . $active ) ); ?>" data-referer>

				<?php /* ---------------------------------------------------------------- Overview */ ?>
				<section class="gls-panel<?php echo 'overview' === $active ? ' is-active' : ''; ?>" data-panel="overview">
					<div class="gls-cols">
						<div class="gls-card">
							<h2>What's running</h2>
							<p class="gls-muted">Greenlight SEO only does what <?php echo esc_html( $seo ?: 'the SEO plugin' ); ?> doesn't, and steps aside when it does.</p>
							<ul class="gls-features">
								<?php foreach ( $labels as $k => $label ) : $f = $features[ $k ]; ?>
									<li class="<?php echo $f['on'] ? 'is-on' : 'is-off'; ?>"><strong><?php echo esc_html( $label ); ?></strong> <span><?php echo $f['on'] ? 'on' : esc_html( 'off: ' . $f['why'] ); ?></span></li>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="gls-card">
							<h2>Site health</h2>
							<div class="gls-stats">
								<a class="gls-stat<?php echo $flagged ? ' is-bad' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=checks' ) ); ?>" data-goto="checks"><b><?php echo count( $flagged ); ?></b><span>pages with indexing or on-page issues</span></a>
								<a class="gls-stat<?php echo $dupes ? ' is-bad' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=checks' ) ); ?>" data-goto="checks"><b><?php echo count( $dupes ); ?></b><span>titles used on more than one page</span></a>
								<a class="gls-stat<?php echo $links['orphans'] ? ' is-bad' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=links' ) ); ?>" data-goto="links"><b><?php echo count( $links['orphans'] ); ?></b><span>orphan pages (no links from other pages)</span></a>
								<a class="gls-stat<?php echo $links['broken'] ? ' is-bad' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=links' ) ); ?>" data-goto="links"><b><?php echo count( $links['broken'] ); ?></b><span>links to missing pages</span></a>
							</div>
							<p class="gls-muted"><?php echo $queued ? (int) $queued . ' page(s) still being checked in the background.' : 'Pages are checked when they are saved.'; ?> <a class="button" href="<?php echo esc_url( $scan ); ?>">Check all pages now</a></p>
						</div>
					</div>
					<?php if ( $problems ) : ?>
						<div class="gls-card gls-alert">
							<h2>Business details to fix</h2>
							<ul><?php foreach ( $problems as $p ) : ?><li class="gls-sev-<?php echo esc_attr( $p[0] ); ?>"><?php echo esc_html( $p[1] ); ?></li><?php endforeach; ?></ul>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=greenlight-seo&tab=business' ) ); ?>" data-goto="business">Open Business</a></p>
						</div>
					<?php endif; ?>
					<?php if ( $conflicts ) : ?>
						<div class="gls-card gls-alert">
							<h2>Overlapping plugins</h2>
							<ul><?php foreach ( $conflicts as $name => $advice ) : ?><li><strong><?php echo esc_html( $name ); ?>:</strong> <?php echo esc_html( $advice ); ?>.</li><?php endforeach; ?></ul>
						</div>
					<?php endif; ?>
				</section>

				<?php /* ---------------------------------------------------------------- Business */ ?>
				<section class="gls-panel<?php echo 'business' === $active ? ' is-active' : ''; ?>" data-panel="business">
					<div class="gls-card">
						<h2>The business</h2>
						<p class="gls-muted">One place for name, address and phone. Used by the schema, llms.txt and the <code>[greenlight_nap]</code> shortcode, so the footer, the pages and Google can't disagree. Match the Google Business Profile exactly.</p>
						<div class="gls-grid">
							<label class="gls-field"><span>Business type <small>schema.org type</small></span>
								<select name="<?php echo esc_attr( self::name( 'type' ) ); ?>">
									<?php foreach ( Settings::TYPES as $type => $label ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $s['type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
						<?php self::place_fields( $s, Settings::OPTION ); ?>
						<div class="gls-grid">
							<?php self::field( 'price_range', 'Price range', $s, 'text', 'e.g. $$' ); ?>
							<label class="gls-field"><span>Main page <small>where the main LocalBusiness lives</small></span><?php self::page_select( self::name( 'main_page' ), $s['main_page'], 'The homepage' ); ?></label>
						</div>
						<?php self::field( 'description', 'Short description', $s, 'textarea' ); ?>
						<?php self::field( 'area_served', 'Areas served', $s, 'textarea', 'one city per line (service-area businesses)' ); ?>
						<?php self::field( 'same_as', 'Profiles', $s, 'textarea', 'one URL per line: Google Business Profile, Facebook, Yelp, BBB…' ); ?>
					</div>
					<div class="gls-card">
						<h2>Other locations</h2>
						<p class="gls-muted">One entry per location with its own Google Business Profile. Each gets its own LocalBusiness on its location page, linked to the main business. Empty name, email or hours fall back to the main business's.</p>
						<div class="gls-locations" data-locations>
							<?php foreach ( (array) $s['locations'] as $i => $loc ) : $prefix = Settings::OPTION . '[locations][' . $i . ']'; ?>
								<fieldset class="gls-location">
									<legend><?php echo esc_html( $loc['name'] ?: 'Location ' . ( $i + 1 ) ); ?></legend>
									<?php self::place_fields( $loc, $prefix ); ?>
									<label class="gls-field"><span>Location page</span><?php self::page_select( $prefix . '[page_id]', $loc['page_id'], '— Pick its page —' ); ?></label>
									<label class="gls-remove"><input type="checkbox" name="<?php echo esc_attr( $prefix . '[_remove]' ); ?>" value="1"> Remove this location</label>
								</fieldset>
							<?php endforeach; ?>
						</div>
						<template id="gls-location-template">
							<fieldset class="gls-location">
								<legend>New location</legend>
								<?php self::place_fields( array_fill_keys( Settings::PLACE, '' ), Settings::OPTION . '[locations][__i__]' ); ?>
								<label class="gls-field"><span>Location page</span><?php self::page_select( Settings::OPTION . '[locations][__i__][page_id]', 0, '— Pick its page —' ); ?></label>
							</fieldset>
						</template>
						<button type="button" class="button" data-add-location>Add a location</button>
					</div>
					<div class="gls-card">
						<h2>Shortcode</h2>
						<p><code>[greenlight_nap]</code> the main business · <code>[greenlight_nap location="2"]</code> the second other location (or <code>location="Lakewood Ranch"</code> by name or city) · <code>[greenlight_nap location="all"]</code> one card per place · <code>show="name,address,phone,hours,email"</code> picks the parts.</p>
					</div>
					<?php submit_button( 'Save business details' ); ?>
				</section>

				<?php /* ---------------------------------------------------------------- Schema */ ?>
				<section class="gls-panel<?php echo 'schema' === $active ? ' is-active' : ''; ?>" data-panel="schema">
					<div class="gls-card">
						<h2>Schema</h2>
						<p class="gls-muted"><?php echo Detect::yoast() ? 'Added to Yoast SEO\'s own schema graph: the business becomes part of Yoast\'s Organization (as Yoast Local SEO does), so each page still has one graph.' : ( Detect::rank_math() ? 'Added to Rank Math\'s schema graph.' : 'Printed as one JSON-LD graph in the page head.' ); ?></p>
						<?php
						self::switch_row( 'local_schema', 'LocalBusiness schema', 'The business type, address, phone, opening hours, coordinates, areas served and profiles, on the main page (with Yoast: on every page, inside its Organization) and each location\'s page.', $features, $s );
						self::switch_row( 'service_schema', 'Service schema', 'On pages marked as service pages (tick “This is a service page” in the Greenlight SEO box when editing the page): the service, its description and areas, provided by the business.', $features, $s );
						?>
						<p class="gls-muted">No FAQ or HowTo schema: for FAQs, use <?php echo esc_html( Detect::yoast() ? 'Yoast SEO\'s' : ( Detect::rank_math() ? 'Rank Math\'s' : 'the SEO plugin\'s' ) ); ?> FAQ block; Google stopped showing HowTo rich results in 2023.</p>
					</div>
					<?php submit_button(); ?>
				</section>

				<?php /* ---------------------------------------------------------------- Checks */ ?>
				<section class="gls-panel<?php echo 'checks' === $active ? ' is-active' : ''; ?>" data-panel="checks">
					<div class="gls-card">
						<h2>Checks</h2>
						<?php
						self::switch_row( 'checks_on_save', 'Check pages when they are saved', 'Fetches the page as a search engine sees it: noindex pages still in the sitemap, more than one canonical or a canonical pointing elsewhere, the theme overriding ' . esc_html( $seo ?: 'the SEO plugin' ) . ', missing or duplicate titles, H1s, images without alt text. Read-only: titles, robots and canonicals stay ' . esc_html( $seo ?: 'the SEO plugin' ) . '\'s job.', $features, $s );
						self::switch_row( 'check_title_suffix', 'Titles end with “| Site name”', 'House style: in Yoast, end each SEO title with <code>%%sep%% %%sitename%%</code> and use the | separator.', $features, $s );
						?>
						<p><a class="button" href="<?php echo esc_url( $scan . '&tab=checks' ); ?>">Check all pages now</a> <?php echo Checks::queued() ? '<span class="gls-muted">' . (int) Checks::queued() . ' page(s) still in the queue.</span>' : ''; ?></p>
					</div>
					<div class="gls-card">
						<h2>Pages with issues <span class="gls-count"><?php echo count( $flagged ); ?></span></h2>
						<?php if ( ! $flagged ) : ?>
							<p class="gls-muted">None found. Low-priority notes (images without alt, H1 equal to the title…) show in each page's Greenlight SEO box.</p>
						<?php else : ?>
							<table class="gls-table"><tbody>
								<?php foreach ( $flagged as $row ) : $last = Checks::last( $row['id'] ); ?>
									<tr><td class="gls-page"><?php echo self::link_to( $row['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><ul class="gls-issues">
										<?php foreach ( (array) ( $last['issues'] ?? array() ) as $i ) : if ( ! in_array( $i['severity'], array( 'high', 'medium' ), true ) ) { continue; } ?>
											<li class="gls-sev-<?php echo esc_attr( $i['severity'] ); ?>"><?php echo esc_html( $i['text'] ); ?></li>
										<?php endforeach; ?>
									</ul></td></tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
					</div>
					<div class="gls-card">
						<h2>Duplicate titles <span class="gls-count"><?php echo count( $dupes ); ?></span></h2>
						<?php if ( ! $dupes ) : ?>
							<p class="gls-muted">No two indexable pages share a title.</p>
						<?php else : ?>
							<table class="gls-table"><tbody>
								<?php foreach ( $dupes as $title => $ids ) : ?>
									<tr><td class="gls-page"><strong><?php echo esc_html( $title ); ?></strong></td><td><?php echo implode( '<br>', array_map( array( __CLASS__, 'link_to' ), $ids ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
					</div>
					<?php submit_button(); ?>
				</section>

				<?php /* ---------------------------------------------------------------- Links */ ?>
				<section class="gls-panel<?php echo 'links' === $active ? ' is-active' : ''; ?>" data-panel="links">
					<div class="gls-card">
						<h2>Internal links</h2>
						<?php self::switch_row( 'link_suggestions', 'Suggest links while editing', 'In the Greenlight SEO box: pages whose title, focus keyphrase, service name or location city appear in the text and aren\'t linked yet. Service and location pages first.', $features, $s ); ?>
						<p class="gls-muted"><?php echo (int) $links['indexed']; ?> of <?php echo (int) $links['pages']; ?> pages indexed<?php echo Links::queued() ? ', ' . (int) Links::queued() . ' to go' : ''; ?>. <a class="button" href="<?php echo esc_url( $scan . '&tab=links' ); ?>">Rebuild the link index</a></p>
					</div>
					<div class="gls-card">
						<h2>Orphan pages <span class="gls-count"><?php echo count( $links['orphans'] ); ?></span></h2>
						<p class="gls-muted">No other page links to them from its content. Search engines find and value pages through links: add a link from a related page.</p>
						<?php if ( $links['orphans'] ) : ?>
							<table class="gls-table"><tbody>
								<?php foreach ( $links['orphans'] as $o ) : ?>
									<tr><td class="gls-page"><?php echo self::link_to( $o['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><?php echo $o['in_menu'] ? 'Only the menu links here' : '<strong>Not in a menu either</strong>'; ?></td></tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
					</div>
					<div class="gls-card">
						<h2>Links to missing or moved pages <span class="gls-count"><?php echo count( $links['broken'] ) + count( $links['redirected'] ); ?></span></h2>
						<?php if ( ! $links['broken'] && ! $links['redirected'] ) : ?>
							<p class="gls-muted">None found.</p>
						<?php else : ?>
							<table class="gls-table"><tbody>
								<?php foreach ( $links['broken'] as $b ) : ?>
									<tr><td class="gls-page"><?php echo self::link_to( $b['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="gls-sev-high">Links to <code><?php echo esc_html( $b['url'] ); ?></code>, which is missing (404).</td></tr>
								<?php endforeach; ?>
								<?php foreach ( $links['redirected'] as $r ) : ?>
									<tr><td class="gls-page"><?php echo self::link_to( $r['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="gls-sev-low">Links to <code><?php echo esc_html( $r['url'] ); ?></code>, which redirects to <code><?php echo esc_html( $r['to'] ); ?></code>: link straight to it.</td></tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
					</div>
					<div class="gls-cols">
						<div class="gls-card">
							<h2>Dead ends <span class="gls-count"><?php echo count( $links['dead_ends'] ); ?></span></h2>
							<p class="gls-muted">Pages whose content links nowhere else on the site.</p>
							<ul class="gls-list"><?php foreach ( array_slice( $links['dead_ends'], 0, 30 ) as $id ) : ?><li><?php echo self::link_to( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li><?php endforeach; ?></ul>
						</div>
						<div class="gls-card">
							<h2>Most linked</h2>
							<ul class="gls-list"><?php foreach ( $links['top'] as $t ) : ?><li><?php echo self::link_to( $t['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> · <?php echo (int) $t['inbound']; ?></li><?php endforeach; ?></ul>
						</div>
					</div>
					<?php submit_button(); ?>
				</section>

				<?php /* ---------------------------------------------------------------- llms.txt */ ?>
				<section class="gls-panel<?php echo 'llms' === $active ? ' is-active' : ''; ?>" data-panel="llms">
					<div class="gls-card">
						<h2>llms.txt</h2>
						<?php self::switch_row( 'llms_txt', 'Serve /llms.txt', 'A Markdown summary of the business, its services, locations, main pages and recent posts for AI assistants (<a href="https://llmstxt.org/">llmstxt.org</a>). Optional, and never while Yoast SEO\'s or Rank Math\'s own llms.txt is on.', $features, $s ); ?>
						<?php self::field( 'llms_intro', 'Summary line', $s, 'textarea', 'default: the business description, then the tagline' ); ?>
						<?php if ( $features['llms_txt']['on'] ) : ?>
							<p><a href="<?php echo esc_url( Llms::url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Llms::url() ); ?></a></p>
						<?php endif; ?>
						<h3>Preview</h3>
						<pre class="gls-pre"><?php echo esc_html( Llms::build() ); ?></pre>
					</div>
					<?php submit_button(); ?>
				</section>
			</form>
		</div>
		<?php
	}
}
