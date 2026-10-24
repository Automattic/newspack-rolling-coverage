<?php
/**
 * Turns a local WordPress site into the demo site the docs screenshots show.
 *
 * Run it with WP-CLI against a single site set aside for screenshots:
 *
 *     wp eval-file <plugin>/site/screenshots/seed.php           Seed, or reset to the known state.
 *     wp eval-file <plugin>/site/screenshots/seed.php remove    Delete everything it created.
 *
 * Add `force` to seed a site whose environment type isn't local or
 * development. See README.md next to this file.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Layout;
use Newspack_Rolling_Coverage\Layout_Templates;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Slack_Config;
use Newspack_Rolling_Coverage\Taxonomy;

defined( 'ABSPATH' ) || exit;

const NEWSPACK_RC_SCREENSHOTS_MARK  = '_newspack_rc_screenshots';
const NEWSPACK_RC_SCREENSHOTS_STATE = 'newspack_rc_screenshots_state';
const NEWSPACK_RC_SCREENSHOTS_FAKES = 'newspack-rc-screenshots.php';
const NEWSPACK_RC_SCREENSHOTS_THEME = 'newspack-block-theme';
const NEWSPACK_RC_SCREENSHOTS_TOKEN = 'xoxb-0000000000-screenshots';

/**
 * Entry point.
 *
 * @param array $args Positional arguments passed to `wp eval-file`.
 */
function newspack_rc_screenshots_main( $args ) {
	$args = (array) $args;

	if ( is_multisite() ) {
		WP_CLI::error( 'Nothing was changed. The seed works on a single site only, not a multisite network.' );
	}
	if ( ! defined( 'NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE' ) ) {
		WP_CLI::error( 'Nothing was changed. Rolling Coverage is not active. Activate your git checkout of the plugin, with its assets built.' );
	}

	newspack_rc_screenshots_require_admin_files();
	newspack_rc_screenshots_block_requests();

	if ( in_array( 'remove', $args, true ) ) {
		$state = get_option( NEWSPACK_RC_SCREENSHOTS_STATE );
		if ( ! $state && ! in_array( 'force', $args, true ) ) {
			WP_CLI::error( "Nothing was changed. This site hasn't been seeded, or its seed state is gone. To delete marked content anyway, add force: wp eval-file <path>/seed.php remove force" );
		}
		$admin_id = newspack_rc_screenshots_site_admin();
		wp_set_current_user( $admin_id ? $admin_id : newspack_rc_screenshots_any_admin() );
		if ( ! empty( $state['styles'] ) && ! current_user_can( 'unfiltered_html' ) ) {
			WP_CLI::error( "Nothing was changed. The site's administrators can't save unfiltered HTML (DISALLOW_UNFILTERED_HTML), so the seed can't put the theme's styles back." );
		}
		newspack_rc_screenshots_remove( true );
		WP_CLI::success( 'Removed the screenshot content and fakes plugin, and put back the settings the seed changed.' );
		return;
	}

	WP_CLI::log( 'This seed is meant for a local site set aside for screenshots, not an everyday dev site.' );
	newspack_rc_screenshots_check_environment( in_array( 'force', $args, true ) );
	newspack_rc_screenshots_check_prerequisites();

	$admin_id = newspack_rc_screenshots_site_admin();
	if ( ! $admin_id ) {
		WP_CLI::error( 'Nothing was changed. The site needs an administrator account of its own.' );
	}
	wp_set_current_user( $admin_id );

	$content = newspack_rc_screenshots_read_content();
	newspack_rc_screenshots_preflight( $content );

	newspack_rc_screenshots_seed( $content );
	WP_CLI::success( 'The screenshot site is ready. Rerun this command any time to reset it.' );
}

/**
 * Stops a run that failed partway, saying how to undo it.
 *
 * @param string $what    What the seed was doing.
 * @param string $message Why it failed.
 */
function newspack_rc_screenshots_fail( $what, $message ) {
	WP_CLI::error( sprintf( '%s: %s. Run the seed with remove to undo this run.', $what, rtrim( $message, '. ' ) ) );
}

/**
 * Loads the wp-admin functions the seed uses.
 */
function newspack_rc_screenshots_require_admin_files() {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

/**
 * Keeps this run from contacting anything but the site itself: no pings,
 * no email, and no remote requests from hooks that fire as content is
 * created.
 */
function newspack_rc_screenshots_block_requests() {
	remove_action( 'publish_post', '_publish_post_hook', 5 );
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'pre_option_ping_sites', '__return_empty_string' );
	add_filter(
		'pre_http_request',
		function ( $response, $args, $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== $host ) {
				return new WP_Error( 'newspack_rc_screenshots_blocked', 'Blocked while seeding the screenshot site.' );
			}
			return $response;
		},
		10,
		3
	);
}

/**
 * Refuses sites that don't say they are local, unless told otherwise.
 *
 * @param bool $force Whether `force` was passed.
 */
function newspack_rc_screenshots_check_environment( $force ) {
	$type = wp_get_environment_type();
	if ( $force || in_array( $type, [ 'local', 'development' ], true ) ) {
		return;
	}
	WP_CLI::error(
		"Nothing was changed. This site's environment type is \"{$type}\", and the seed only runs on local or development sites.\n"
		. "Set it in wp-config.php with define( 'WP_ENVIRONMENT_TYPE', 'local' ); or the WP_ENVIRONMENT_TYPE environment variable. WordPress assumes production when neither is set, which is the case on many Docker images and Studio sites.\n"
		. 'To seed this site anyway, add force: wp eval-file <path>/seed.php force'
	);
}

/**
 * Stops before changing anything when the Newspack stack isn't in place.
 */
function newspack_rc_screenshots_check_prerequisites() {
	$missing = [];
	if ( ! defined( 'NEWSPACK_PLUGIN_FILE' ) ) {
		$missing[] = 'The Newspack plugin is not active. Install and activate it from https://github.com/Automattic/newspack-plugin/releases';
	}
	if ( ! wp_get_theme( NEWSPACK_RC_SCREENSHOTS_THEME )->exists() ) {
		$missing[] = 'The Newspack Block Theme is not installed. Download it from https://github.com/Automattic/newspack-block-theme/releases';
	}
	if ( $missing ) {
		WP_CLI::error( "Nothing was changed.\n- " . implode( "\n- ", $missing ) );
	}
}

/**
 * The site's first administrator the seed didn't create.
 *
 * @return int User ID, or 0.
 */
function newspack_rc_screenshots_site_admin() {
	foreach ( get_users(
		[
			'role'    => 'administrator',
			'orderby' => 'ID',
			'fields'  => 'ID',
		]
	) as $user_id ) {
		if ( ! get_user_meta( (int) $user_id, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
			return (int) $user_id;
		}
	}
	return 0;
}

/**
 * Any administrator, for removal on a site whose only administrators the
 * seed created.
 *
 * @return int User ID, or 0.
 */
function newspack_rc_screenshots_any_admin() {
	$admins = get_users(
		[
			'role'    => 'administrator',
			'orderby' => 'ID',
			'number'  => 1,
			'fields'  => 'ID',
		]
	);
	return $admins ? (int) $admins[0] : 0;
}

/**
 * Reads a file shipped next to this one.
 *
 * @param string $path Path relative to this folder.
 * @return string|false
 */
function newspack_rc_screenshots_read_file( $path ) {
	$file = __DIR__ . '/' . $path;
	return is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
}

/**
 * The demo content exported from the site the screenshots were taken on.
 *
 * @return array
 */
function newspack_rc_screenshots_read_content() {
	$content = json_decode( (string) newspack_rc_screenshots_read_file( 'content.json' ), true );
	if ( ! is_array( $content ) ) {
		WP_CLI::error( 'Nothing was changed. Could not read content.json next to this file.' );
	}
	return $content;
}

/**
 * Checks everything that could stop the seed halfway, so it either runs
 * through or changes nothing.
 *
 * @param array $content Demo content.
 */
function newspack_rc_screenshots_preflight( $content ) {
	$problems = [];

	foreach ( $content['coverages'] as $coverage ) {
		$term = get_term_by( 'slug', $coverage['slug'], Taxonomy::TAXONOMY_SLUG );
		if ( $term && ! get_term_meta( $term->term_id, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
			$problems[] = sprintf( 'The site already has a coverage with the slug "%s".', $coverage['slug'] );
		}
	}

	foreach ( $content['posts'] as $post ) {
		$existing = get_page_by_path( $post['slug'], OBJECT, $post['type'] );
		if ( $existing && ! get_post_meta( $existing->ID, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
			$problems[] = sprintf( 'The site already has a %s at /%s/.', $post['type'], $post['slug'] );
		}
	}

	foreach ( $content['parts'] as $part ) {
		$existing = get_block_template( NEWSPACK_RC_SCREENSHOTS_THEME . '//' . $part['slug'], 'wp_template_part' );
		if ( $existing && $existing->wp_id && ! get_post_meta( $existing->wp_id, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
			$problems[] = sprintf( 'The site already has a customized "%s" template part.', $part['slug'] );
		}
	}

	foreach ( array_merge( $content['users'], [ $content['admin'] ] ) as $user ) {
		foreach ( [ 'login', 'email' ] as $field ) {
			$existing = get_user_by( $field, $user[ $field ] );
			if ( $existing && ! get_user_meta( $existing->ID, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
				$problems[] = sprintf( 'The site already has a user with the %s "%s".', $field, $user[ $field ] );
			}
		}
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || ! wp_is_writable( $uploads['basedir'] ) ) {
		$problems[] = 'The uploads folder is not writable: ' . ( $uploads['error'] ? $uploads['error'] : $uploads['basedir'] );
	}
	$mu_plugins = is_dir( WPMU_PLUGIN_DIR ) ? WPMU_PLUGIN_DIR : dirname( WPMU_PLUGIN_DIR );
	if ( ! wp_is_writable( $mu_plugins ) ) {
		$problems[] = 'The mu-plugins folder is not writable: ' . WPMU_PLUGIN_DIR;
	}

	$files = [ 'fakes.php', 'assets/media/' . $content['site']['icon'] ];
	foreach ( $content['media'] as $item ) {
		$files[] = 'assets/media/' . $item['file'];
	}
	foreach ( $files as $file ) {
		if ( ! is_readable( __DIR__ . '/' . $file ) ) {
			$problems[] = "Can't read {$file} next to this file.";
		}
	}

	$requirements = validate_theme_requirements( NEWSPACK_RC_SCREENSHOTS_THEME );
	if ( is_wp_error( $requirements ) ) {
		$problems[] = 'The Newspack Block Theme cannot run on this site: ' . $requirements->get_error_message();
	}

	$plugin_dir = realpath( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR );
	if ( realpath( dirname( __DIR__, 2 ) ) !== $plugin_dir ) {
		$problems[] = sprintf( 'This seed.php is not in the copy of Rolling Coverage the site runs (%s). Run the seed.php inside that plugin folder, or activate this checkout.', $plugin_dir );
	}

	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$problems[] = "The site's administrators can't save unfiltered HTML (DISALLOW_UNFILTERED_HTML), which the theme's styles and templates need.";
	}

	if ( ! Layout::can_create_layout() ) {
		$problems[] = 'The first administrator account cannot create patterns, which the built-in layouts need.';
	}

	if ( $problems ) {
		WP_CLI::error( "Nothing was changed.\n- " . implode( "\n- ", $problems ) . "\nUse a site set aside for screenshots." );
	}
}

/**
 * Saves the seed's state.
 *
 * @param array $state Seed state.
 */
function newspack_rc_screenshots_save_state( $state ) {
	update_option( NEWSPACK_RC_SCREENSHOTS_STATE, $state, false );
}

/**
 * Seeds the demo site.
 *
 * @param array $content Demo content.
 */
function newspack_rc_screenshots_seed( $content ) {
	$state = get_option( NEWSPACK_RC_SCREENSHOTS_STATE, [] );
	$state = is_array( $state ) ? $state : [];
	if ( empty( $state['previous'] ) ) {
		$state['previous'] = [
			'blogname'      => get_option( 'blogname' ),
			'show_on_front' => get_option( 'show_on_front' ),
			'page_on_front' => get_option( 'page_on_front' ),
			'site_icon'     => get_option( 'site_icon' ),
			'stylesheet'    => get_stylesheet(),
			'widgets'       => get_option( 'sidebars_widgets' ),
			'menus'         => get_theme_mod( 'nav_menu_locations' ),
			'pinned'        => false !== get_option( Post_Type::PINNED_OPTION_KEY ),
		];
		newspack_rc_screenshots_save_state( $state );
	}

	newspack_rc_screenshots_install_fakes();
	newspack_rc_screenshots_remove( false );
	$state = get_option( NEWSPACK_RC_SCREENSHOTS_STATE, $state );
	wp_set_current_user( newspack_rc_screenshots_seed_admin( $content['admin'] ) );

	if ( get_stylesheet() !== NEWSPACK_RC_SCREENSHOTS_THEME ) {
		switch_theme( NEWSPACK_RC_SCREENSHOTS_THEME );
	}
	$state = newspack_rc_screenshots_seed_styles( $content['styles'], $state );
	update_option( 'blogname', $content['site']['title'] );

	$users      = newspack_rc_screenshots_seed_users( $content['users'] );
	$users['']  = $users[ $content['site']['default_author'] ];
	$media      = newspack_rc_screenshots_seed_media( $content['media'] );
	$categories = newspack_rc_screenshots_seed_categories( $content['site']['categories'] );
	if ( ! Layout::get_pattern_category_id() && empty( $state['pattern_category'] ) ) {
		$state['pattern_category'] = true;
		newspack_rc_screenshots_save_state( $state );
	}
	$layouts    = newspack_rc_screenshots_seed_layouts();
	$coverages  = newspack_rc_screenshots_seed_coverages( $content['coverages'] );
	$tokens     = [ '{{home}}' => untrailingslashit( home_url() ) ];
	foreach ( $coverages as $slug => $term_id ) {
		$tokens[ '"{{coverage:' . $slug . '}}"' ] = (string) $term_id;
	}
	foreach ( $layouts as $slug => $layout_id ) {
		$tokens[ '"{{layout:' . $slug . '}}"' ] = (string) $layout_id;
	}

	$posts = newspack_rc_screenshots_seed_posts( $content['posts'], $users, $tokens );
	foreach ( $content['coverages'] as $coverage ) {
		if ( ! empty( $coverage['canonical'] ) && isset( $posts[ $coverage['canonical'] ] ) ) {
			update_term_meta( $coverages[ $coverage['slug'] ], Taxonomy::CANONICAL_URL_META_KEY, get_permalink( $posts[ $coverage['canonical'] ] ) );
		}
	}
	newspack_rc_screenshots_seed_entries( $content['entries'], $coverages, $users, $media, $tokens );
	newspack_rc_screenshots_seed_parts( $content['parts'], newspack_rc_screenshots_seed_navigation( $categories ) );

	$icon = [
		'file'    => $content['site']['icon'],
		'title'   => 'Site icon',
		'alt'     => '',
		'caption' => '',
	];
	update_option( 'site_icon', newspack_rc_screenshots_seed_media( [ $icon ] )[ $icon['file'] ] );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $posts['front-page'] );

	newspack_rc_screenshots_seed_slack( $content['slack'], $coverages, $state );
}

/**
 * Applies the demo site's styles: the theme's Bulletin alt variation with a
 * serif for headings and a sans serif for text. A site's own styles are kept
 * in the seed's state before they are replaced, and put back on removal.
 *
 * @param array $styles Styles from content.json.
 * @param array $state  Seed state.
 * @return array Seed state.
 */
function newspack_rc_screenshots_seed_styles( $styles, $state ) {
	$styles['version']                     = 3;
	$styles['isGlobalStylesUserThemeJSON'] = true;

	$content  = wp_json_encode( $styles, JSON_UNESCAPED_SLASHES );
	$existing = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme( NEWSPACK_RC_SCREENSHOTS_THEME ), false );

	if ( ! empty( $existing['ID'] ) && ! get_post_meta( $existing['ID'], NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
		if ( empty( $state['styles'] ) ) {
			$state['styles'] = [
				'id'      => (int) $existing['ID'],
				'content' => $existing['post_content'],
			];
			newspack_rc_screenshots_save_state( $state );
		}
		newspack_rc_screenshots_update_styles( $existing['ID'], $content );
	} else {
		$styles_id = newspack_rc_screenshots_insert_post(
			[
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_name'    => 'wp-global-styles-' . rawurlencode( NEWSPACK_RC_SCREENSHOTS_THEME ),
				'post_title'   => 'Custom Styles',
				'post_content' => $content,
			],
			0
		);
		wp_set_object_terms( $styles_id, NEWSPACK_RC_SCREENSHOTS_THEME, 'wp_theme' );
	}
	wp_clean_theme_json_cache();
	return $state;
}

/**
 * Replaces the content of the site's own styles post without adding a
 * revision, so its revision history stays as it was.
 *
 * @param int    $post_id Styles post ID.
 * @param string $content Styles JSON.
 */
function newspack_rc_screenshots_update_styles( $post_id, $content ) {
	$revisions = has_action( 'post_updated', 'wp_save_post_revision' );
	remove_action( 'post_updated', 'wp_save_post_revision', 10 );
	wp_update_post(
		wp_slash(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			]
		)
	);
	if ( false !== $revisions ) {
		add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
	}
}

/**
 * Creates the administrator the screenshots show, unless the seed created it
 * on an earlier run, and prints its password once.
 *
 * @param array $admin Administrator from content.json.
 * @return int User ID.
 */
function newspack_rc_screenshots_seed_admin( $admin ) {
	$existing = get_user_by( 'login', $admin['login'] );
	if ( $existing ) {
		return $existing->ID;
	}
	$password = wp_generate_password( 24, false );
	$user_id  = newspack_rc_screenshots_insert_user( array_merge( $admin, [ 'role' => 'administrator' ] ), $password );
	WP_CLI::log( "Created the administrator {$admin['login']} with the password {$password}" );
	WP_CLI::log( "It's shown only this once. To set a new one: wp user update {$admin['login']} --user_pass=<new password>" );
	return $user_id;
}

/**
 * Creates the entry authors, or reuses the ones an earlier run created.
 *
 * @param array $users Users from content.json.
 * @return array<string, int> User IDs by login.
 */
function newspack_rc_screenshots_seed_users( $users ) {
	$ids = [];
	foreach ( $users as $user ) {
		$existing              = get_user_by( 'login', $user['login'] );
		$ids[ $user['login'] ] = $existing ? $existing->ID : newspack_rc_screenshots_insert_user( $user, wp_generate_password( 32 ) );
	}
	return $ids;
}

/**
 * Inserts a marked user.
 *
 * @param array  $user     User from content.json.
 * @param string $password Password.
 * @return int User ID.
 */
function newspack_rc_screenshots_insert_user( $user, $password ) {
	$user_id = wp_insert_user(
		[
			'user_login'   => $user['login'],
			'user_email'   => $user['email'],
			'user_pass'    => $password,
			'first_name'   => $user['first_name'],
			'last_name'    => $user['last_name'],
			'display_name' => trim( $user['first_name'] . ' ' . $user['last_name'] ),
			'role'         => $user['role'],
		]
	);
	if ( is_wp_error( $user_id ) ) {
		newspack_rc_screenshots_fail( sprintf( 'Could not create the user %s', $user['login'] ), $user_id->get_error_message() );
	}
	update_user_meta( $user_id, NEWSPACK_RC_SCREENSHOTS_MARK, 1 );
	return $user_id;
}

/**
 * Adds the photos entries use to the media library.
 *
 * @param array $media Media from content.json.
 * @return array<string, int> Attachment IDs by file name.
 */
function newspack_rc_screenshots_seed_media( $media ) {
	$ids = [];
	foreach ( $media as $item ) {
		$bits = newspack_rc_screenshots_read_file( 'assets/media/' . $item['file'] );
		$file = false === $bits ? [ 'error' => 'the file is not readable' ] : wp_upload_bits( $item['file'], null, $bits );
		if ( ! empty( $file['error'] ) ) {
			newspack_rc_screenshots_fail( sprintf( 'Could not upload %s', $item['file'] ), $file['error'] );
		}
		$attachment_id = wp_insert_attachment(
			wp_slash(
				[
					'post_title'     => $item['title'],
					'post_excerpt'   => $item['caption'],
					'post_mime_type' => wp_check_filetype( $item['file'] )['type'],
					'post_status'    => 'inherit',
					'meta_input'     => [
						NEWSPACK_RC_SCREENSHOTS_MARK => 1,
						'_wp_attachment_image_alt'   => $item['alt'],
					],
				]
			),
			$file['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			newspack_rc_screenshots_fail( sprintf( 'Could not add %s to the media library', $item['file'] ), $attachment_id->get_error_message() );
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file['file'] ) );
		$ids[ $item['file'] ] = $attachment_id;
	}
	return $ids;
}

/**
 * Finds or creates the categories in the header menu.
 *
 * @param string[] $names Category names, in menu order.
 * @return array<string, int> Category IDs by name.
 */
function newspack_rc_screenshots_seed_categories( $names ) {
	$ids = [];
	foreach ( $names as $name ) {
		$term = get_term_by( 'name', $name, 'category' );
		if ( $term ) {
			$ids[ $name ] = $term->term_id;
			continue;
		}
		$term = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $term ) ) {
			newspack_rc_screenshots_fail( sprintf( 'Could not create the category %s', $name ), $term->get_error_message() );
		}
		update_term_meta( $term['term_id'], NEWSPACK_RC_SCREENSHOTS_MARK, 1 );
		$ids[ $name ] = $term['term_id'];
	}
	return $ids;
}

/**
 * Creates the built-in layouts the editor would create on first use, so
 * pages can name them.
 *
 * @return array<string, int> Layout pattern IDs by slug.
 */
function newspack_rc_screenshots_seed_layouts() {
	$layouts = [];
	foreach ( Layout::BUILT_IN_SLUGS as $slug ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/layouts/' . $slug );
		$request->set_param( 'content', Layout_Templates::get_pattern_content( $slug ) );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			newspack_rc_screenshots_fail( sprintf( 'Could not create the %s layout', $slug ), $response->as_error()->get_error_message() );
		}
		$layouts[ $slug ] = (int) $response->get_data()['id'];
		if ( 201 === $response->get_status() ) {
			update_post_meta( $layouts[ $slug ], NEWSPACK_RC_SCREENSHOTS_MARK, 1 );
		}
	}
	return $layouts;
}

/**
 * Creates the coverages with their statuses.
 *
 * @param array $coverages Coverages from content.json.
 * @return array<string, int> Coverage term IDs by slug.
 */
function newspack_rc_screenshots_seed_coverages( $coverages ) {
	$ids = [];
	foreach ( $coverages as $coverage ) {
		$term = wp_insert_term( $coverage['name'], Taxonomy::TAXONOMY_SLUG, [ 'slug' => $coverage['slug'] ] );
		if ( is_wp_error( $term ) ) {
			newspack_rc_screenshots_fail( sprintf( 'Could not create the coverage %s', $coverage['name'] ), $term->get_error_message() );
		}
		$term_id = $term['term_id'];
		$created = gmdate( 'c', time() - $coverage['age'] );
		update_term_meta( $term_id, NEWSPACK_RC_SCREENSHOTS_MARK, 1 );
		update_term_meta( $term_id, Taxonomy::CREATED_AT_META_KEY, $created );
		update_term_meta( $term_id, Taxonomy::MODIFIED_AT_META_KEY, $created );
		update_term_meta( $term_id, Taxonomy::STATUS_META_KEY, $coverage['status'] );
		if ( isset( $coverage['ended_age'] ) ) {
			update_term_meta( $term_id, Taxonomy::END_TIME_META_KEY, gmdate( 'Y-m-d H:i:s', time() - $coverage['ended_age'] ) );
		}
		if ( ! empty( $coverage['ads_disabled'] ) ) {
			update_term_meta( $term_id, Taxonomy::ADS_DISABLED_META_KEY, 1 );
		}
		$ids[ $coverage['slug'] ] = $term_id;
	}
	return $ids;
}

/**
 * Creates the pages and posts that show the coverages.
 *
 * @param array $posts  Pages and posts from content.json.
 * @param array $users  User IDs by login.
 * @param array $tokens Placeholders in content.json and their values.
 * @return array<string, int> Post IDs by slug.
 */
function newspack_rc_screenshots_seed_posts( $posts, $users, $tokens ) {
	$ids = [];
	foreach ( $posts as $post ) {
		$ids[ $post['slug'] ] = newspack_rc_screenshots_insert_post(
			[
				'post_type'    => $post['type'],
				'post_status'  => $post['status'] ?? 'publish',
				'post_name'    => $post['slug'],
				'post_title'   => $post['title'],
				'post_content' => strtr( $post['content'], $tokens ),
				'post_author'  => $users[ (string) $post['author'] ],
			],
			$post['age']
		);
	}
	return $ids;
}

/**
 * Creates the entries, their full story posts and pins.
 *
 * @param array $entries   Entries from content.json, oldest first.
 * @param array $coverages Coverage term IDs by slug.
 * @param array $users     User IDs by login.
 * @param array $media     Attachment IDs by file name.
 * @param array $tokens    Placeholders in content.json and their values.
 */
function newspack_rc_screenshots_seed_entries( $entries, $coverages, $users, $media, $tokens ) {
	foreach ( $entries as $entry ) {
		$entry_id = newspack_rc_screenshots_insert_post(
			[
				'post_type'    => Post_Type::CPT_SLUG,
				'post_status'  => $entry['status'] ?? 'publish',
				'post_title'   => $entry['title'],
				'post_content' => strtr( $entry['content'], $tokens ),
				'post_author'  => $users[ (string) $entry['author'] ],
			],
			$entry['age']
		);
		wp_set_object_terms( $entry_id, [ $coverages[ $entry['coverage'] ] ], Taxonomy::TAXONOMY_SLUG );

		if ( ! empty( $entry['image'] ) ) {
			set_post_thumbnail( $entry_id, $media[ $entry['image'] ] );
		}
		if ( ! empty( $entry['archived'] ) ) {
			update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );
		}
		if ( ! empty( $entry['slack'] ) ) {
			update_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );
			update_post_meta( $entry_id, Post_Type::META_SLACK_AUTHOR_NAME, $entry['slack']['author_name'] );
			update_post_meta( $entry_id, Post_Type::META_SLACK_USER_ID, $entry['slack']['user_id'] );
			update_post_meta( $entry_id, Post_Type::META_SLACK_CHANNEL_ID, $entry['slack']['channel_id'] );
			update_post_meta( $entry_id, Post_Type::META_SLACK_TS, $entry['slack']['ts'] );
			update_post_meta( $entry_id, Post_Type::META_SOURCE_REF, $entry['slack']['ts'] );
			if ( '' !== $entry['title'] ) {
				update_post_meta( $entry_id, Post_Type::META_TITLE_FROM_SOURCE, 1 );
			}
		}
		if ( ! empty( $entry['breakout'] ) ) {
			$breakout    = $entry['breakout'];
			$breakout_id = newspack_rc_screenshots_insert_post(
				[
					'post_type'    => 'post',
					'post_status'  => $breakout['status'] ?? 'publish',
					'post_title'   => $breakout['title'],
					'post_content' => strtr( $breakout['content'], $tokens ),
					'post_excerpt' => $breakout['excerpt'],
					'post_author'  => $users[ (string) $breakout['author'] ],
				],
				$breakout['age']
			);
			if ( ! empty( $breakout['image'] ) ) {
				set_post_thumbnail( $breakout_id, $media[ $breakout['image'] ] );
			}
			update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
			update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
			update_post_meta( $entry_id, Breakout::BREAKOUT_STATUS_FIELD, get_post_status( $breakout_id ) );
		}
		if ( ! empty( $entry['pinned'] ) ) {
			Post_Type::pin_entry( $entry_id );
		}
	}
}

/**
 * Inserts a marked post dated a given number of seconds before now.
 *
 * @param array $postarr Post fields.
 * @param int   $age     Seconds before now.
 * @return int Post ID.
 */
function newspack_rc_screenshots_insert_post( $postarr, $age ) {
	$date_gmt = gmdate( 'Y-m-d H:i:s', time() - (int) $age );
	$post_id  = wp_insert_post(
		wp_slash(
			array_merge(
				$postarr,
				[
					'post_date'     => get_date_from_gmt( $date_gmt ),
					'post_date_gmt' => $date_gmt,
					'meta_input'    => [ NEWSPACK_RC_SCREENSHOTS_MARK => 1 ],
				]
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		newspack_rc_screenshots_fail( sprintf( 'Could not create "%s"', $postarr['post_title'] ), $post_id->get_error_message() );
	}
	return $post_id;
}

/**
 * Creates the header menu. The theme's navigation blocks show the newest
 * menu when they don't name one.
 *
 * @param array $categories Category IDs by name, in menu order.
 * @return int Navigation post ID.
 */
function newspack_rc_screenshots_seed_navigation( $categories ) {
	$links = [];
	foreach ( $categories as $name => $term_id ) {
		$links[] = sprintf(
			'<!-- wp:navigation-link %s /-->',
			wp_json_encode(
				[
					'label' => $name,
					'type'  => 'category',
					'id'    => $term_id,
					'url'   => get_category_link( $term_id ),
					'kind'  => 'taxonomy',
				],
				JSON_UNESCAPED_SLASHES
			)
		);
	}
	return newspack_rc_screenshots_insert_post(
		[
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => 'Sections',
			'post_content' => implode( "\n", $links ),
		],
		0
	);
}

/**
 * Replaces the theme's header with the demo site's: the menu button, site
 * title and section menu on one row.
 *
 * @param array $parts      Template parts from content.json.
 * @param int   $navigation Navigation post ID.
 */
function newspack_rc_screenshots_seed_parts( $parts, $navigation ) {
	foreach ( $parts as $part ) {
		$part_id = newspack_rc_screenshots_insert_post(
			[
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $part['slug'],
				'post_title'   => $part['title'],
				'post_content' => str_replace( '"{{navigation}}"', (string) $navigation, $part['content'] ),
			],
			0
		);
		wp_set_object_terms( $part_id, NEWSPACK_RC_SCREENSHOTS_THEME, 'wp_theme' );
		wp_set_object_terms( $part_id, $part['area'], 'wp_template_part_area' );
	}
}

/**
 * Stores a connected Slack workspace and its channel mappings as settings.
 * Nothing calls Slack: the token is a placeholder and outbound requests are
 * blocked.
 *
 * @param array $slack     Slack settings from content.json.
 * @param array $coverages Coverage term IDs by slug.
 * @param array $state     Seed state.
 */
function newspack_rc_screenshots_seed_slack( $slack, $coverages, $state ) {
	if ( ! empty( $state['slack'] ) && ! newspack_rc_screenshots_slack_is_ours() ) {
		newspack_rc_screenshots_release_slack( $state );
		WP_CLI::warning( 'Someone connected this site to a real Slack workspace since the last seed, so the seed left Slack alone.' );
		return;
	}
	if ( ! array_key_exists( 'slack', $state ) ) {
		foreach ( [ Slack_Config::OPTION_BOT_TOKEN, Slack_Config::OPTION_SIGNING_SECRET, Slack_Config::OPTION_SETTINGS, Slack_Config::OPTION_CHANNEL_MAP ] as $option ) {
			if ( false !== get_option( $option ) ) {
				$state['slack'] = null;
				newspack_rc_screenshots_save_state( $state );
				WP_CLI::warning( 'This site already has Slack settings, so the seed left Slack alone.' );
				return;
			}
		}
	} elseif ( null === $state['slack'] ) {
		return;
	}

	if ( empty( $state['slack'] ) ) {
		$state['slack'] = [ 'seeded' => true ];
		newspack_rc_screenshots_save_state( $state );
	}
	if ( ! get_user_by( 'login', Slack_Config::BOT_USER_LOGIN ) ) {
		update_user_meta( Slack_Config::get_or_create_bot_user_id(), NEWSPACK_RC_SCREENSHOTS_MARK, 1 );
	}

	update_option( Slack_Config::OPTION_BOT_TOKEN, NEWSPACK_RC_SCREENSHOTS_TOKEN, false );
	update_option( Slack_Config::OPTION_SIGNING_SECRET, bin2hex( random_bytes( 16 ) ), false );
	update_option(
		Slack_Config::OPTION_SETTINGS,
		[
			'connected'         => true,
			'workspace_name'    => $slack['workspace_name'],
			'workspace_id'      => $slack['workspace_id'],
			'ignore_prefix'     => Slack_Config::DEFAULT_IGNORE_PREFIX,
			'bot_user_id'       => Slack_Config::get_or_create_bot_user_id(),
			'slack_bot_user_id' => $slack['slack_bot_user_id'],
		],
		false
	);

	$map = [];
	foreach ( $slack['channels'] as $channel_id => $channel ) {
		$term_id            = $coverages[ $channel['coverage'] ];
		$map[ $channel_id ] = array_merge(
			[
				'channel_name' => $channel['name'],
				'term_id'      => $term_id,
			],
			Slack_Config::publish_mode_fields( $channel['autopublish'] ? Slack_Config::PUBLISH_MODE_PUBLISH : Slack_Config::PUBLISH_MODE_DRAFT )
		);
		update_term_meta( $term_id, Taxonomy::META_SLACK_CHANNEL_ID, $channel_id );
		update_term_meta( $term_id, Taxonomy::META_SLACK_CHANNEL_NAME, $channel['name'] );
		update_term_meta( $term_id, Taxonomy::META_SOURCE, 'slack' );
		update_term_meta( $term_id, Taxonomy::META_SOURCE_REF, $channel_id );
	}
	update_option( Slack_Config::OPTION_CHANNEL_MAP, $map, false );
}

/**
 * Whether the stored Slack token is still the seed's placeholder, or empty.
 *
 * @return bool
 */
function newspack_rc_screenshots_slack_is_ours() {
	$token = (string) get_option( Slack_Config::OPTION_BOT_TOKEN, '' );
	return '' === $token || NEWSPACK_RC_SCREENSHOTS_TOKEN === $token;
}

/**
 * Hands Slack over to the site's real connection: the bot user loses its
 * mark, so removal keeps the account that connection publishes as.
 *
 * @param array $state Seed state.
 */
function newspack_rc_screenshots_release_slack( $state ) {
	$bot = get_user_by( 'login', Slack_Config::BOT_USER_LOGIN );
	if ( $bot ) {
		delete_user_meta( $bot->ID, NEWSPACK_RC_SCREENSHOTS_MARK );
	}
	$state['slack'] = null;
	newspack_rc_screenshots_save_state( $state );
}

/**
 * Copies the fakes plugin into mu-plugins.
 */
function newspack_rc_screenshots_install_fakes() {
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	if ( ! copy( __DIR__ . '/fakes.php', WPMU_PLUGIN_DIR . '/' . NEWSPACK_RC_SCREENSHOTS_FAKES ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_copy
		newspack_rc_screenshots_fail( 'Could not copy fakes.php into ' . WPMU_PLUGIN_DIR, 'the folder is not writable' );
	}
}

/**
 * Deletes what the seed marked. A full removal also deletes the accounts it
 * created, puts back the settings it changed and removes the fakes plugin;
 * the reset before each seed keeps the accounts and settings.
 *
 * @param bool $everything Whether this is a full removal.
 */
function newspack_rc_screenshots_remove( $everything ) {
	$state  = get_option( NEWSPACK_RC_SCREENSHOTS_STATE, [] );
	$state  = is_array( $state ) ? $state : [];
	$marked = [
		'key'   => NEWSPACK_RC_SCREENSHOTS_MARK,
		'value' => '1',
	];

	$posts = get_posts(
		[
			'post_type'   => array_values( get_post_types() ),
			'post_status' => array_keys( get_post_stati() ),
			'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Every marked item, from a command line run.
			'fields'      => 'ids',
			'meta_query'  => [ $marked ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		]
	);
	$posts = array_values(
		array_filter(
			array_map( 'intval', $posts ),
			function ( $post_id ) {
				return '1' === get_post_meta( $post_id, NEWSPACK_RC_SCREENSHOTS_MARK, true );
			}
		)
	);
	foreach ( $posts as $post_id ) {
		if ( 'attachment' === get_post_type( $post_id ) ) {
			wp_delete_attachment( $post_id, true );
		} else {
			wp_delete_post( $post_id, true );
		}
	}

	$pinned = get_option( Post_Type::PINNED_OPTION_KEY );
	if ( is_array( $pinned ) ) {
		$pinned = array_values( array_diff( $pinned, $posts ) );
		if ( $everything && ! $pinned && empty( $state['previous']['pinned'] ) ) {
			delete_option( Post_Type::PINNED_OPTION_KEY );
		} else {
			update_option( Post_Type::PINNED_OPTION_KEY, $pinned );
		}
	}
	foreach ( Layout::BUILT_IN_SLUGS as $slug ) {
		if ( in_array( (int) get_option( Layout::option_name( $slug ) ), $posts, true ) ) {
			delete_option( Layout::option_name( $slug ) );
		}
	}

	foreach ( [ Taxonomy::TAXONOMY_SLUG, 'category' ] as $taxonomy ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => [ $marked ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		foreach ( $terms as $term_id ) {
			if ( '1' === get_term_meta( $term_id, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
				wp_delete_term( $term_id, $taxonomy );
			}
		}
	}

	if ( ! empty( $state['slack'] ) ) {
		if ( newspack_rc_screenshots_slack_is_ours() ) {
			Slack_Config::clear_all();
		} else {
			WP_CLI::warning( 'This site is connected to a real Slack workspace now, so the seed left its Slack settings alone.' );
			newspack_rc_screenshots_release_slack( $state );
			$state['slack'] = null;
		}
	}

	if ( ! $everything ) {
		return;
	}

	$reassign = newspack_rc_screenshots_site_admin();
	$users    = get_users(
		[
			'fields'     => 'ID',
			'meta_query' => [ $marked ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		]
	);
	$admins = get_users(
		[
			'role'   => 'administrator',
			'fields' => 'ID',
		]
	);
	$admins = array_map( 'intval', $admins );
	foreach ( array_unique( array_map( 'intval', $users ) ) as $user_id ) {
		if ( '1' !== get_user_meta( $user_id, NEWSPACK_RC_SCREENSHOTS_MARK, true ) ) {
			continue;
		}
		if ( in_array( $user_id, $admins, true ) && count( $admins ) <= 1 ) {
			WP_CLI::warning( "Kept user {$user_id}: it's the site's only administrator." );
			continue;
		}
		$admins = array_values( array_diff( $admins, [ $user_id ] ) );
		if ( $reassign ) {
			wp_delete_user( (int) $user_id, $reassign );
		} elseif ( ! newspack_rc_screenshots_has_posts( (int) $user_id ) ) {
			wp_delete_user( (int) $user_id );
		} else {
			WP_CLI::warning( "Kept user {$user_id}: it has posts and there's no other administrator to give them to." );
		}
	}

	if ( ! empty( $state['pattern_category'] ) ) {
		$term_id = Layout::get_pattern_category_id();
		if ( $term_id && ! get_objects_in_term( $term_id, Layout::PATTERN_TAXONOMY ) ) {
			wp_delete_term( $term_id, Layout::PATTERN_TAXONOMY );
		}
	}

	if ( ! empty( $state['styles'] ) && get_post( $state['styles']['id'] ) ) {
		newspack_rc_screenshots_update_styles( $state['styles']['id'], $state['styles']['content'] );
	}
	wp_clean_theme_json_cache();

	if ( ! empty( $state['previous'] ) ) {
		update_option( 'blogname', $state['previous']['blogname'] );
		update_option( 'show_on_front', $state['previous']['show_on_front'] );
		update_option( 'page_on_front', $state['previous']['page_on_front'] );
		update_option( 'site_icon', $state['previous']['site_icon'] );
		if ( get_stylesheet() !== $state['previous']['stylesheet'] ) {
			$requirements = wp_get_theme( $state['previous']['stylesheet'] )->exists() ? validate_theme_requirements( $state['previous']['stylesheet'] ) : false;
			if ( is_wp_error( $requirements ) ) {
				WP_CLI::warning( "The site's previous theme, {$state['previous']['stylesheet']}, can't run on this site ({$requirements->get_error_message()}), so the Newspack Block Theme stays active." );
			} elseif ( $requirements ) {
				switch_theme( $state['previous']['stylesheet'] );
				if ( isset( $state['previous']['widgets'] ) && false !== $state['previous']['widgets'] ) {
					update_option( 'sidebars_widgets', $state['previous']['widgets'] );
				}
				if ( is_array( $state['previous']['menus'] ?? null ) ) {
					set_theme_mod( 'nav_menu_locations', $state['previous']['menus'] );
				}
			} else {
				WP_CLI::warning( "The site's previous theme, {$state['previous']['stylesheet']}, is no longer installed, so the Newspack Block Theme stays active." );
			}
		}
	}
	delete_option( NEWSPACK_RC_SCREENSHOTS_STATE );
	delete_option( 'newspack_rc_screenshots_ai_error' );

	$fakes = WPMU_PLUGIN_DIR . '/' . NEWSPACK_RC_SCREENSHOTS_FAKES;
	if ( file_exists( $fakes ) ) {
		wp_delete_file( $fakes );
	}
}

/**
 * Whether a user wrote any post, of any type or status.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function newspack_rc_screenshots_has_posts( $user_id ) {
	return (bool) get_posts(
		[
			'author'      => $user_id,
			'post_type'   => array_values( get_post_types() ),
			'post_status' => array_keys( get_post_stati() ),
			'numberposts' => 1,
			'fields'      => 'ids',
		]
	);
}

newspack_rc_screenshots_main( $args ?? [] );
