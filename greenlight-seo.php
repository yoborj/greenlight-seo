<?php
/**
 * Plugin Name:       Greenlight SEO
 * Description:       The local-business SEO that Yoast and Rank Math leave to Premium or don't do: LocalBusiness and Service schema added to their graph, indexing and on-page checks on save (noindex pages in the sitemap, conflicting canonicals, missing or duplicate titles, H1s), internal link suggestions and orphan pages, one place for NAP, and an optional llms.txt. Companion to Greenlight (speed and security).
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       greenlight-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'GREENLIGHT_SEO_VERSION', '0.1.0' );
define( 'GREENLIGHT_SEO_FILE', __FILE__ );
define( 'GREENLIGHT_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GREENLIGHT_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once GREENLIGHT_SEO_DIR . 'includes/class-settings.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-detect.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-business.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-schema.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-checks.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-links.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-llms.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-editor.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-report.php';
require_once GREENLIGHT_SEO_DIR . 'includes/class-admin.php';

Greenlight_SEO\Business::init();
Greenlight_SEO\Schema::init();
Greenlight_SEO\Checks::init();
Greenlight_SEO\Links::init();
Greenlight_SEO\Llms::init();
Greenlight_SEO\Editor::init();
Greenlight_SEO\Admin::init();

register_activation_hook( __FILE__, function () {
	// Build the internal link index in the background (small batches via WP-Cron).
	Greenlight_SEO\Links::start_index();
} );
register_deactivation_hook( __FILE__, function () {
	wp_unschedule_hook( Greenlight_SEO\Links::BATCH );
	wp_unschedule_hook( Greenlight_SEO\Checks::EVENT );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once GREENLIGHT_SEO_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'greenlight-seo', 'Greenlight_SEO\\CLI' );
}
