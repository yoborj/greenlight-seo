<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Which SEO plugins are active, and which Greenlight SEO features step aside for them.
 * Rule: never do what the site's SEO plugin already does (titles, meta, sitemaps, its own schema, its
 * FAQ/How-to blocks, its llms.txt); fill in what it leaves to a paid add-on, and step aside when that add-on is there.
 */
class Detect {

	public static function yoast() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function yoast_premium() {
		return defined( 'WPSEO_PREMIUM_VERSION' );
	}

	public static function yoast_local() {
		return defined( 'WPSEO_LOCAL_VERSION' );
	}

	public static function rank_math() {
		return defined( 'RANK_MATH_VERSION' );
	}

	private static function rank_math_module( $module ) {
		return self::rank_math() && class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'is_module_active' ) && \RankMath\Helper::is_module_active( $module );
	}

	public static function seo_plugin() {
		if ( self::yoast() ) {
			return 'Yoast SEO' . ( self::yoast_premium() ? ' Premium' : '' );
		}
		if ( self::rank_math() ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		return null;
	}

	/** Yoast's own llms.txt (free since Yoast SEO 25.3). */
	public static function yoast_llms() {
		return self::yoast() && class_exists( '\\WPSEO_Options' ) && \WPSEO_Options::get( 'enable_llms_txt', false );
	}

	/**
	 * Each feature: whether it runs (setting on AND nothing else does the job) and, when it doesn't, why.
	 * [ key => [ 'on' => bool, 'why' => string|null ] ]
	 */
	public static function features() {
		$s   = Settings::all();
		$out = array();

		$local = null;
		if ( self::yoast_local() ) {
			$local = 'Yoast Local SEO adds LocalBusiness schema';
		} elseif ( self::rank_math_module( 'local-seo' ) ) {
			$local = 'Rank Math\'s Local SEO module adds LocalBusiness schema';
		} elseif ( defined( 'AIOSEO_LOCAL_BUSINESS_VERSION' ) ) {
			$local = 'AIOSEO Local Business adds LocalBusiness schema';
		} elseif ( defined( 'SEOPRESS_PRO_VERSION' ) ) {
			$local = 'SEOPress Pro adds LocalBusiness schema';
		}
		$out['local_schema'] = self::feature( $s['local_schema'], $local );

		$out['service_schema'] = self::feature( $s['service_schema'], null );

		$faq = null;
		if ( self::yoast() ) {
			$faq = 'Yoast SEO has its own FAQ block: use that one';
		} elseif ( self::rank_math() ) {
			$faq = 'Rank Math has its own FAQ block: use that one';
		}
		$out['faq_block'] = self::feature( $s['faq_block'], $faq );

		$out['checks_on_save'] = self::feature( $s['checks_on_save'], null );

		$links = null;
		if ( self::yoast_premium() ) {
			$links = 'Yoast SEO Premium suggests internal links while you write';
		} elseif ( self::rank_math() ) {
			$links = 'Rank Math suggests internal links while you write';
		}
		$out['link_suggestions'] = self::feature( $s['link_suggestions'], $links );

		$llms = null;
		if ( self::yoast_llms() ) {
			$llms = 'Yoast SEO\'s llms.txt is on (Yoast SEO → Settings → Site features)';
		} elseif ( self::rank_math_module( 'llms-txt' ) ) {
			$llms = 'Rank Math\'s llms.txt module is on';
		} elseif ( file_exists( ABSPATH . 'llms.txt' ) ) {
			$llms = 'an llms.txt file is already on the server';
		}
		$out['llms_txt'] = self::feature( $s['llms_txt'], $llms );

		return $out;
	}

	private static function feature( $setting, $other ) {
		if ( $other ) {
			return array( 'on' => false, 'why' => $other );
		}
		return array( 'on' => ! empty( $setting ), 'why' => empty( $setting ) ? 'switched off' : null );
	}

	public static function on( $feature ) {
		$f = self::features();
		return ! empty( $f[ $feature ]['on'] );
	}

	/** Overlapping plugins to warn about on the settings page: [ name => advice ]. */
	public static function conflicts() {
		$found = array();
		if ( defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
			$found[ defined( 'AIOSEO_VERSION' ) ? 'All in One SEO' : 'SEOPress' ] = 'Greenlight SEO adds its schema as a separate block next to this plugin\'s: check Google\'s Rich Results Test for duplicates';
		}
		if ( defined( 'BSF_AIOSEO_NOTIF_VERSION' ) || defined( 'BSF_SCHEMA_PRO_VER' ) || class_exists( 'BSF_AIOSEO' ) ) {
			$found['Schema Pro'] = 'pick one plugin for LocalBusiness and Service schema';
		}
		if ( defined( 'WPSEO_VERSION' ) && defined( 'RANK_MATH_VERSION' ) ) {
			$found['Yoast SEO and Rank Math'] = 'both are active: keep one SEO plugin';
		}
		if ( defined( 'LINK_WHISPER_VERSION' ) || defined( 'WPIL_STORE_URL' ) ) {
			$found['Link Whisper'] = 'switch off one of the two link-suggestion tools';
		}
		return $found;
	}
}
