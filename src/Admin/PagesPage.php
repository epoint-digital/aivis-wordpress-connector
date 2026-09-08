<?php
/**
 * Pages screen (§11): find one page, or see the ones that need a human.
 *
 * Defaults to the "needs attention" view when it is non-empty, otherwise to
 * all pages; paginated, sortable, searchable, filterable by language and
 * chain; bulk refresh / disable / restore. Bulk actions and Screen Options
 * are processed on the screen's load hook (before headers), in Menu.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;

final class PagesPage {

	public const OPTION_PER_PAGE = 'aivis_os_pages_per_page';

	public function __construct( private readonly Plugin $plugin ) {}

	/** Filters for the current request, with the attention-first default. */
	public function filters(): array {
		$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing.
		$pp  = (int) get_user_option( self::OPTION_PER_PAGE );
		$f   = PagesQuery::from_request( $get, $pp );
		if ( '' === $f['state'] && ! isset( $get['state'] ) && '' === $f['search'] ) {
			$o     = $this->plugin->options();
			$moved = array_keys( (array) ( $o->sync_state()['moved'] ?? [] ) );
			$conf  = array_keys( (array) $o->conflicts()['items'] );
			$w     = PagesQuery::where( [ 'state' => 'attention', 'language' => '', 'chain' => '', 'search' => '' ], $moved, $conf );
			if ( $this->plugin->repository()->count_where( $w['sql'], $w['args'] ) > 0 ) {
				$f['state'] = 'attention';
			}
		}
		return $f;
	}

	public function render(): void {
		$filters = $this->filters();
		$table   = new PagesTable( $this->plugin, $filters );
		$table->prepare_items();
		?>
		<div class="wrap aivis-os">
			<h1><?php esc_html_e( 'AIVIS OS — Pages', 'aivis-os' ); ?></h1>
			<p class="description"><?php esc_html_e( 'You do not have to read this list. "Needs attention" shows the pages that want a decision; search finds one page. Everything else is AIVIS delivering as generated.', 'aivis-os' ); ?></p>
			<?php echo Menu::tabs( Menu::SLUG_PAGES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG_PAGES ); ?>">
				<?php if ( '' !== $filters['state'] ) : ?><input type="hidden" name="state" value="<?php echo esc_attr( $filters['state'] ); ?>"><?php endif; ?>
				<?php wp_nonce_field( 'aivis_os_bulk' ); ?>
				<?php $table->views(); ?>
				<?php $table->search_box( __( 'Search pages', 'aivis-os' ), 'aivis-pages' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
