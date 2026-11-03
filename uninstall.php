<?php
// Remove Greenlight SEO's settings, indexes and per-page data when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
foreach ( array( 'greenlight_seo_settings', 'greenlight_seo_titles', 'greenlight_seo_inbound', 'greenlight_seo_links_queue', 'greenlight_seo_check_queue' ) as $greenlight_seo_option ) {
	delete_option( $greenlight_seo_option );
}
foreach ( array( '_greenlight_seo_check', '_greenlight_seo_issues', '_greenlight_seo_links', '_greenlight_seo_service' ) as $greenlight_seo_key ) {
	delete_post_meta_by_key( $greenlight_seo_key );
}
delete_transient( 'greenlight_seo_llms' );
wp_unschedule_hook( 'greenlight_seo_check' );
wp_unschedule_hook( 'greenlight_seo_check_batch' );
wp_unschedule_hook( 'greenlight_seo_links_batch' );
