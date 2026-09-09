<?php
/**
 * Admin menu, asset loading, and POST action dispatch.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Domain\ErrorCode;
use AivisOS\Plugin;
use AivisOS\Sync\Scheduler;

final class Menu {

	public const SLUG_STATUS   = 'aivis-os';
	public const SLUG_PAGES    = 'aivis-os-pages';
	public const SLUG_SETTINGS = 'aivis-os-settings';

	public function __construct( private readonly Plugin $plugin ) {}

	public static function capability(): string {
		/**
		 * Filter the capability required to manage the plugin.
		 *
		 * @param string $cap Default manage_options.
		 */
		return (string) apply_filters( 'aivis_connector_manage_capability', 'manage_options' );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_aivis_os_action', [ $this, 'handle' ] );
	}

	public function menu(): void {
		$cap = self::capability();
		add_menu_page( 'AIVIS OS', 'AIVIS OS', $cap, self::SLUG_STATUS, [ new StatusPage( $this->plugin ), 'render' ], 'dashicons-networking', 81 );
		add_submenu_page( self::SLUG_STATUS, __( 'Status', 'aivis-os' ), __( 'Status', 'aivis-os' ), $cap, self::SLUG_STATUS, [ new StatusPage( $this->plugin ), 'render' ] );
		$pages = add_submenu_page( self::SLUG_STATUS, __( 'Pages', 'aivis-os' ), __( 'Pages', 'aivis-os' ), $cap, self::SLUG_PAGES, [ new PagesPage( $this->plugin ), 'render' ] );
		add_submenu_page( self::SLUG_STATUS, __( 'Settings', 'aivis-os' ), __( 'Settings', 'aivis-os' ), $cap, self::SLUG_SETTINGS, [ new SettingsPage( $this->plugin ), 'render' ] );
		if ( is_string( $pages ) && '' !== $pages ) {
			// Runs before headers: Screen Options and bulk actions live here.
			add_action( 'load-' . $pages, [ $this, 'pages_load' ] );
		}
		add_filter( 'set-screen-option', [ $this, 'save_per_page' ], 10, 3 );
		add_filter( 'set_screen_option_' . PagesPage::OPTION_PER_PAGE, [ $this, 'save_per_page' ], 10, 3 );
	}

	/** The three plugin screens share one tab strip. */
	public static function tabs( string $current ): string {
		$h = '<nav class="nav-tab-wrapper">';
		foreach ( [ self::SLUG_STATUS => __( 'Status', 'aivis-os' ), self::SLUG_PAGES => __( 'Pages', 'aivis-os' ), self::SLUG_SETTINGS => __( 'Settings', 'aivis-os' ) ] as $slug => $label ) {
			$h .= '<a class="nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $h . '</nav>';
	}

	/** Screen Options (per page) and bulk actions for the Pages screen. */
	public function pages_load(): void {
		add_screen_option( 'per_page', [ 'label' => __( 'Pages per screen', 'aivis-os' ), 'default' => PagesQuery::PER_PAGE_DEFAULT, 'option' => PagesPage::OPTION_PER_PAGE ] );
		$bulk = sanitize_key( (string) ( $_GET['aivis_bulk'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just below.
		if ( '' === $bulk ) {
			return;
		}
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aivis-os' ) );
		}
		check_admin_referer( 'aivis_os_bulk' );
		$keys = PagesQuery::bulk_keys( (array) ( $_GET['url_key'] ?? [] ) );
		$msg  = $this->bulk( $bulk, $keys );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG_PAGES, 'aivis_msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param list<string> $keys
	 * @return string message key
	 */
	public function bulk( string $action, array $keys ): string {
		if ( ! $keys ) {
			return 'nothing_selected';
		}
		$repo = $this->plugin->repository();
		switch ( $action ) {
			case 'refresh':
				foreach ( $repo->urls_for_keys( $keys ) as $url ) {
					wp_schedule_single_event( time(), 'aivis_os_lookup', [ $url ] );
				}
				return 'bulk_refresh';
			case 'disable':
				$repo->retire_many( $keys, 'AIVIS_ADMIN_DISABLED' );
				$this->plugin->synchronizer()->purge( $repo->urls_for_keys( $keys ) );
				return 'bulk_disabled';
			case 'restore':
				$repo->restore_many( $keys );
				$this->plugin->synchronizer()->purge( $repo->urls_for_keys( $keys ) );
				return 'bulk_restored';
		}
		return 'nothing_selected';
	}

	/** @param mixed $status @param string $option @param mixed $value */
	public function save_per_page( $status, $option, $value ) {
		if ( PagesPage::OPTION_PER_PAGE === $option ) {
			return max( 1, min( PagesQuery::PER_PAGE_MAX, (int) $value ) );
		}
		return $status;
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'aivis-os' ) ) {
			return;
		}
		wp_enqueue_style( 'aivis-os-admin', AIVIS_OS_URL . 'assets/admin.css', [], AIVIS_OS_VERSION );
	}

	/** One nonce-checked entry point for every admin action. */
	public function handle(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aivis-os' ) );
		}
		check_admin_referer( 'aivis_os_action' );
		// Row actions on the Pages screen are nonce-signed links (GET); the forms elsewhere POST.
		$do   = sanitize_key( (string) ( $_REQUEST['do'] ?? '' ) );
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG_STATUS );
		$msg  = '';

		switch ( $do ) {
			case 'save_settings':
			case 'save_and_test':
				// One form, two buttons (#69): the test always runs against what was
				// just saved — token and environment included — never against a
				// stale copy.
				$msg = ( new SettingsPage( $this->plugin ) )->save( $_POST );
				if ( 'save_and_test' === $do && ! in_array( $msg, [ 'token_format', 'auth_failed' ], true ) ) {
					$msg = $this->test_connection();
				}
				break;
			case 'test_connection':
				$msg = $this->test_connection();
				break;
			case 'sync_now':
				Scheduler::request_sync_now();
				$msg = 'sync_requested';
				break;
			case 'sync_run':
				$this->plugin->synchronizer()->run();
				$msg = 'synced';
				break;
			case 'refresh_url':
				$url = esc_url_raw( (string) wp_unslash( $_REQUEST['url'] ?? '' ) );
				if ( '' !== $url ) {
					$this->plugin->synchronizer()->refresh_url( $url );
				}
				$msg = 'refreshed';
				break;
			case 'disable_url':
			case 'restore_url':
				$key = sanitize_text_field( (string) ( $_REQUEST['url_key'] ?? '' ) );
				if ( 'disable_url' === $do ) {
					$this->plugin->repository()->retire( $key, 'AIVIS_ADMIN_DISABLED' );
				} else {
					$this->plugin->repository()->restore( $key );
				}
				$msg = 'updated';
				break;
			case 'disconnect':
				$this->plugin->options()->set_token( '' );
				$this->plugin->options()->clear_business();
				$this->plugin->options()->set_token_status( null );
				$this->plugin->repository()->delete_all();
				$this->plugin->cache()->adapter()->purge_all();
				$msg = 'disconnected';
				break;
			case 'clear_diagnostics':
				$this->plugin->options()->clear_diagnostics();
				$msg = 'cleared';
				break;
			case 'acknowledge_conflicts':
				$this->plugin->options()->acknowledge_conflicts();
				$msg = 'overridden';
				break;
			case 'scan_conflicts':
				$r   = $this->plugin->verifier()->scan_conflicts();
				$msg = $r['conflicts'] > 0 ? 'scan_conflicts' : 'scan_clean';
				break;
			case 'status_key_regenerate':
				$this->plugin->options()->regenerate_status_key();
				$msg = 'status_key_regenerated';
				break;
			case 'status_key_disable':
				$this->plugin->options()->disable_status_key();
				$msg = 'status_key_disabled';
				break;
		}
		wp_safe_redirect( add_query_arg( 'aivis_msg', $msg, $back ) );
		exit;
	}

	/** A nonce-signed link for a single action (used where a form cannot nest). */
	public static function action_url( string $do, array $args = [] ): string {
		return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'aivis_os_action', 'do' => $do ], array_map( 'strval', $args ) ), admin_url( 'admin-post.php' ) ), 'aivis_os_action' );
	}

	/** Small helper used by both pages. */
	/**
	 * §11 — verify the token against the selected instance and remember the
	 * outcome *with its reason*: which host answered what. A blanket "token
	 * invalid" hid an unreachable production host and an unsaved token
	 * behind the same words (#69).
	 *
	 * @return string Notice key: connected | no_token | auth_failed | account_disabled
	 *                | client_too_old | throttled | unreachable | api_error
	 */
	public function test_connection(): string {
		$o    = $this->plugin->options();
		$host = (string) wp_parse_url( $o->api_base(), PHP_URL_HOST );
		if ( 'none' === $o->token_source() ) {
			$o->set_token_status( null );
			return 'no_token';
		}
		$r = $this->plugin->client()->me();
		if ( $r->ok() ) {
			$o->set_token_status_from_me( (array) $r->body );
			delete_transient( 'aivis_os_businesses' );
			$this->note_changelog();
			return 'connected';
		}
		$kind   = $r->kind();
		$detail = '' !== $r->transport_error
			? $r->transport_error
			: ( '' !== $r->code() ? $r->code() . ' — ' . $r->message() : ( $r->message() ?: 'HTTP ' . $r->status ) );
		$o->set_token_status( false, '', '', [ 'failure' => [ 'kind' => $kind, 'status' => $r->status, 'detail' => $detail, 'host' => $host ] ] );
		$code = match ( $kind ) {
			'auth'           => ErrorCode::AUTH_401,
			'account'        => ErrorCode::ACCOUNT_403,
			'client_too_old' => ErrorCode::CLIENT_TOO_OLD,
			'throttled'      => ErrorCode::RATE_LIMITED,
			'transport'      => ErrorCode::HTTP_TIMEOUT,
			default          => ErrorCode::HTTP_ERROR,
		};
		if ( 'client_too_old' !== $kind ) {
			$o->record( $code, "connection test against {$host}: {$detail}" );
		}
		return match ( $kind ) {
			'auth'           => 'auth_failed',
			'account'        => 'account_disabled',
			'client_too_old' => 'client_too_old',
			'throttled'      => 'throttled',
			// No HTTP status at all: DNS, TLS, timeout, host pin. A 5xx did reach AIVIS.
			'transport'      => 0 === $r->status ? 'unreachable' : 'api_error',
			default          => 'api_error',
		};
	}

	/**
	 * §03 — `/changelog` (contract ≥ 1.2.0) announces a raised minimum client
	 * ahead of enforcement and publishes the rate limits. One request per
	 * connection test; a 1.0.0 instance has no such route and that is fine.
	 */
	private function note_changelog(): void {
		$o = $this->plugin->options();
		$r = $this->plugin->client()->changelog();
		if ( ! $r->ok() ) {
			return;
		}
		$info = $o->api_info();
		$n    = $r->body['nextMinClient'] ?? null;
		$rl   = $r->body['rateLimits']['perToken'] ?? null;
		$o->set_api_info(
			[
				'version'         => '' !== (string) ( $r->body['apiVersion'] ?? '' ) ? (string) $r->body['apiVersion'] : $info['version'],
				'min_client'      => '' !== (string) ( $r->body['minClientVersion'] ?? '' ) ? (string) $r->body['minClientVersion'] : $info['min_client'],
				'next_min_client' => is_array( $n ) && '' !== (string) ( $n['version'] ?? '' ) ? [ 'version' => (string) $n['version'], 'effective_from' => (string) ( $n['effectiveFrom'] ?? '' ) ] : null,
				'rate_limit'      => is_array( $rl ) ? [ 'limit' => (int) ( $rl['limit'] ?? 0 ), 'window' => (int) ( $rl['windowSeconds'] ?? 0 ) ] : $info['rate_limit'],
				'seen_at'         => time(),
			]
		);
	}

	/**
	 * A submit button for a form that already carries the nonce and action
	 * (the settings form). Use this inside such a form — a nested
	 * action_form() is invalid HTML: browsers close the outer form at the
	 * inner one and every field after it stops being submitted (#69).
	 */
	public static function action_button( string $do, string $label, string $class = 'button', bool $confirm = false ): string {
		return '<button type="submit" class="' . esc_attr( $class ) . '" name="do" value="' . esc_attr( $do ) . '"'
			. ( $confirm ? ' onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'aivis-os' ) ) . '\')"' : '' )
			. '>' . esc_html( $label ) . '</button>';
	}

	/** A standalone one-button form. Never place it inside another form — see action_button(). */
	public static function action_form( string $do, string $label, array $hidden = [], string $class = 'button', bool $confirm = false ): string {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"'
			. ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( __( 'Are you sure?', 'aivis-os' ) ) . '\')"' : '' ) . '>';
		$h .= wp_nonce_field( 'aivis_os_action', '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="aivis_os_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		foreach ( $hidden as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$h .= '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		return $h;
	}
}
