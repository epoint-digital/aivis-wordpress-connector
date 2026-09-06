<?php
/**
 * Test bootstrap: a small, honest stand-in for the WordPress functions the
 * classes under test touch. There is no WordPress install in CI; what these
 * tests prove is the plugin's own logic, not WordPress.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'AIVIS_OS_VERSION', '1.0.0' );
define( 'AIVIS_OS_BASENAME', 'aivis-os/aivis-os.php' );
define( 'AIVIS_OS_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'AIVIS_OS_URL', 'https://example.com/wp-content/plugins/aivis-os/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

require dirname( __DIR__, 2 ) . '/src/autoload.php';

/** Minimal $wpdb: enough for Repository to run its SQL without a database. */
final class WPDBStub {
	public string $prefix = 'wp_';
	public array $rows = [];
	public function prepare( string $sql, mixed ...$args ): string { return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $sql ), array_map( fn( $a ) => is_array( $a ) ? $a[0] : $a, $args ) ); }
	public function get_row( string $sql, mixed $out = null ): ?array { return $this->rows[0] ?? null; }
	public function get_results( string $sql, mixed $out = null ): array { return $this->rows; }
	public function get_var( string $sql ): ?string { return null; }
	public function query( string $sql ): int|bool { return 0; }
	public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|false { return 1; }
	public function insert( string $t, array $d, mixed $f = null ): int|false { return 1; }
	public function get_charset_collate(): string { return ''; }
}
$GLOBALS['wpdb'] = new WPDBStub();

final class WPStub {
	public static array $options = [];
	public static array $transients = [];
	public static array $site_transients = [];
	public static array $http_queue = [];
	public static array $http_log = [];
	public static array $scheduled = [];
	public static array $flags = [];
	public static array $filters = [];
	public static array $mail = [];
	public static string $home = 'https://example.com';

	public static function reset(): void {
		self::$options = self::$transients = self::$site_transients = self::$http_queue = self::$http_log = self::$scheduled = self::$flags = self::$filters = self::$mail = [];
		self::$home = 'https://example.com';
	}
	public static function queue( int $status, mixed $body, array $headers = [] ): void {
		self::$http_queue[] = [
			'response' => [ 'code' => $status, 'message' => '' ],
			'headers'  => $headers + [ 'content-type' => 'application/json' ],
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		];
	}
	public static function queue_error( string $msg ): void {
		self::$http_queue[] = new WP_Error( 'http_request_failed', $msg );
	}
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

function get_option( string $k, mixed $d = false ): mixed { return WPStub::$options[ $k ] ?? $d; }
function update_option( string $k, mixed $v, mixed $autoload = null ): bool { WPStub::$options[ $k ] = $v; return true; }
function delete_option( string $k ): bool { unset( WPStub::$options[ $k ] ); return true; }
function get_transient( string $k ): mixed { return WPStub::$transients[ $k ] ?? false; }
function set_transient( string $k, mixed $v, int $ttl = 0 ): bool { WPStub::$transients[ $k ] = $v; return true; }
function delete_transient( string $k ): bool { unset( WPStub::$transients[ $k ] ); return true; }
function get_site_transient( string $k ): mixed { return WPStub::$site_transients[ $k ] ?? false; }
function set_site_transient( string $k, mixed $v, int $ttl = 0 ): bool { WPStub::$site_transients[ $k ] = $v; return true; }

function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function home_url( string $path = '' ): string { return WPStub::$home . $path; }
function site_url( string $path = '' ): string { return WPStub::$home . $path; }
function add_query_arg( array $args, string $url ): string {
	$p = parse_url( $url ); $q = [];
	if ( ! empty( $p['query'] ) ) { parse_str( $p['query'], $q ); }
	$q = array_merge( $q, $args );
	return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( $p['path'] ?? '' ) . ( $q ? '?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 ) : '' );
}
function url_to_postid( string $url ): int { return 0; }
function esc_url_raw( string $u ): string { return $u; }

function wp_json_encode( mixed $v, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $v, $flags, $depth ); }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
function wp_generate_uuid4(): string { return sprintf( '%08x-%04x-4%03x-%04x-%012x', mt_rand(), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffff ), mt_rand() ); }
function get_bloginfo( string $k ): string { return '7.1'; }
function apply_filters( string $hook, mixed $v, mixed ...$args ): mixed { return $v; }
function do_action( string $hook, mixed ...$args ): void {}
function add_action( string $h, mixed $cb, int $p = 10, int $a = 1 ): void {}
function add_filter( string $h, mixed $cb, int $p = 10, int $a = 1 ): void { WPStub::$filters[] = [ $h, $cb, $p ]; }
function __( string $s, string $d = '' ): string { return $s; }
function _n( string $s, string $p, int $n, string $d = '' ): string { return 1 === $n ? $s : $p; }
function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html__( string $s, string $d = '' ): string { return $s; }
function esc_attr( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_kses( string $s, array $a ): string { return $s; }
function wp_kses_post( string $s ): string { return $s; }
function sanitize_key( string $s ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }

function is_admin(): bool { return WPStub::$flags['is_admin'] ?? false; }
function wp_doing_ajax(): bool { return WPStub::$flags['ajax'] ?? false; }
function wp_doing_cron(): bool { return WPStub::$flags['cron'] ?? false; }
function is_feed(): bool { return WPStub::$flags['feed'] ?? false; }
function is_robots(): bool { return WPStub::$flags['robots'] ?? false; }
function is_trackback(): bool { return WPStub::$flags['trackback'] ?? false; }
function is_preview(): bool { return WPStub::$flags['preview'] ?? false; }
function is_404(): bool { return WPStub::$flags['404'] ?? false; }
function is_embed(): bool { return WPStub::$flags['embed'] ?? false; }
function is_customize_preview(): bool { return WPStub::$flags['customize'] ?? false; }
function is_favicon(): bool { return WPStub::$flags['favicon'] ?? false; }

function wp_next_scheduled( string $hook, array $args = [] ): int|false { return WPStub::$scheduled[ $hook ][0] ?? false; }
function wp_schedule_event( int $ts, string $rec, string $hook, array $args = [] ): bool { WPStub::$scheduled[ $hook ] = [ $ts, $rec ]; return true; }
function wp_schedule_single_event( int $ts, string $hook, array $args = [] ): bool { WPStub::$scheduled[ $hook . ':' . md5( serialize( $args ) ) ] = [ $ts, 'single' ]; return true; }
function wp_clear_scheduled_hook( string $hook, array $args = [] ): int { unset( WPStub::$scheduled[ $hook ] ); return 1; }

function wp_safe_remote_get( string $url, array $args = [] ): mixed {
	WPStub::$http_log[] = [ $url, $args ];
	if ( ! WPStub::$http_queue ) { return new WP_Error( 'no_queue', 'test queue empty' ); }
	return array_shift( WPStub::$http_queue );
}
function wp_safe_remote_post( string $url, array $args = [] ): mixed {
	WPStub::$http_log[] = [ $url, $args + [ '_method' => 'POST' ] ];
	if ( ! WPStub::$http_queue ) { return new WP_Error( 'no_queue', 'test queue empty' ); }
	return array_shift( WPStub::$http_queue );
}
function wp_mail( string $to, string $subject, string $message ): bool { WPStub::$mail[] = compact( 'to', 'subject', 'message' ); return true; }
function admin_url( string $path = '' ): string { return WPStub::$home . '/wp-admin/' . $path; }
function __return_false(): bool { return false; }
function __return_true(): bool { return true; }
function is_wp_error( mixed $x ): bool { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( mixed $r ): int { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_header( mixed $r, string $h ): string { return (string) ( $r['headers'][ strtolower( $h ) ] ?? '' ); }
function wp_remote_retrieve_body( mixed $r ): string { return (string) ( $r['body'] ?? '' ); }
