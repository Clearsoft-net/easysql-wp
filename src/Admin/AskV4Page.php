<?php

declare(strict_types=1);

namespace EasySQL\Admin;

/**
 * Native WordPress "Ask your database" admin page (v4).
 *
 * Registered as the top-level "EasySQL" menu item, with History as a
 * sub-item. Uses core WP components (postboxes, tablenav, widefat tables,
 * notices, buttons) and shares the same REST endpoints and i18n patterns
 * as the other Ask pages.
 */
class AskV4Page {

	/**
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Register the admin page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add the top-level menu page.
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_menu_page(
			__( 'EasySQL Ask', 'easysql' ),
			__( 'EasySQL', 'easysql' ),
			'manage_options',
			'easysql-ask',
			array( $this, 'render' ),
			'dashicons-editor-table',
			30
		);

		// Rename the auto-generated first submenu item (which otherwise
		// inherits the "EasySQL" title) to "Ask your database".
		remove_submenu_page( 'easysql-ask', 'easysql-ask' );
		add_submenu_page(
			'easysql-ask',
			__( 'Ask your database', 'easysql' ),
			__( 'Ask your database', 'easysql' ),
			'manage_options',
			'easysql-ask',
			array( $this, 'render' )
		);
	}

	/**
	 * Return the hook suffix for asset enqueueing.
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Render the Ask v4 page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'easysql' ) );
		}

		$initial_question = isset( $_GET['question'] )
			? sanitize_text_field( wp_unslash( $_GET['question'] ) )
			: '';

		$suggestions = array(
			__( 'How many users registered this month?', 'easysql' ),
			__( 'List the 5 most recent posts.', 'easysql' ),
			__( 'What are the most popular categories?', 'easysql' ),
			__( 'Show total comments per post type.', 'easysql' ),
			__( 'Top 10 products by revenue this quarter', 'easysql' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Ask your database', 'easysql' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Type a question in plain language about your WordPress data.', 'easysql' ); ?></p>

			<hr class="wp-header-end">

			<!-- Error notice (dismissible, rendered by JS) -->
			<div id="easysql-ask4-error" class="notice notice-error inline easysql-ask4-notice" hidden>
				<p>
					<span id="easysql-ask4-error-text"></span>
					<button type="button" class="button button-small" id="easysql-ask4-retry-btn" hidden>
						<?php esc_html_e( 'Retry', 'easysql' ); ?>
					</button>
				</p>
			</div>

			<div id="poststuff" class="easysql-ask4">

				<div id="post-body" class="metabox-holder columns-2">

					<!-- Main column: composer + results -->
					<div id="post-body-content">

						<!-- Composer postbox -->
						<div class="postbox easysql-ask4-composer">
							<div class="postbox-header">
								<h2 class="hndle ui-sortable-handle">
									<span><?php esc_html_e( 'Ask a question', 'easysql' ); ?></span>
								</h2>
							</div>
							<div class="inside">
								<label class="screen-reader-text" for="easysql-ask4-question">
									<?php esc_html_e( 'Your question', 'easysql' ); ?>
								</label>
								<textarea
									id="easysql-ask4-question"
									class="large-text easysql-ask4-question-input"
									rows="4"
									placeholder="<?php esc_attr_e( 'e.g. How many users registered this month?', 'easysql' ); ?>"
								><?php echo esc_textarea( $initial_question ); ?></textarea>
								<p class="description easysql-ask4-hint">
									<?php esc_html_e( 'Press Enter to submit, Shift+Enter for a new line.', 'easysql' ); ?>
								</p>
								<p class="submit easysql-ask4-actions">
									<button type="button" id="easysql-ask4-submit" class="button button-primary button-large">
										<?php esc_html_e( 'Ask', 'easysql' ); ?>
									</button>
									<span class="spinner easysql-ask4-spinner"></span>
								</p>
							</div>
						</div>

						<!-- Results: placeholder + loading + answer -->
						<div id="easysql-ask4-results">

							<!-- Empty state -->
							<div id="easysql-ask4-empty" class="easysql-ask4-empty">
								<p class="easysql-ask4-empty-title"><?php esc_html_e( 'No results yet', 'easysql' ); ?></p>
								<p class="description easysql-ask4-empty-desc">
									<?php esc_html_e( 'Type a question above and click Ask — the AI will write the SQL, run it, and show the results here.', 'easysql' ); ?>
								</p>
								<p class="description easysql-ask4-empty-label"><?php esc_html_e( 'Or try one of these:', 'easysql' ); ?></p>
								<p class="easysql-ask4-suggestions" id="easysql-ask4-suggestions">
									<?php foreach ( $suggestions as $suggestion ) : ?>
										<button
											type="button"
											class="button button-secondary easysql-ask4-suggestion"
											data-question="<?php echo esc_attr( $suggestion ); ?>"
										><?php echo esc_html( $suggestion ); ?></button>
									<?php endforeach; ?>
								</p>
							</div>

							<!-- Loading -->
							<div id="easysql-ask4-loading" class="easysql-ask4-loading" hidden>
								<p class="easysql-ask4-loading-text">
									<span class="spinner is-active"></span>
									<?php esc_html_e( 'Analyzing your question…', 'easysql' ); ?>
								</p>
								<p id="easysql-ask4-loading-elapsed" class="description easysql-ask4-loading-elapsed" aria-live="polite"></p>
							</div>

							<!-- Answer postbox -->
							<div id="easysql-ask4-answer" class="postbox easysql-ask4-answer" hidden>
								<div class="postbox-header">
									<h2 class="hndle">
										<span id="easysql-ask4-answer-question"></span>
									</h2>
								</div>
								<div class="inside">
									<p class="description easysql-ask4-answer-meta" id="easysql-ask4-answer-meta"></p>
									<div class="easysql-ask4-answer-body" id="easysql-ask4-answer-body"></div>
								</div>
							</div>

							<!-- Data postbox -->
							<div id="easysql-ask4-data" class="postbox easysql-ask4-data" hidden>
								<div class="postbox-header">
									<h2 class="hndle"><span><?php esc_html_e( 'Data', 'easysql' ); ?></span></h2>
								</div>
								<div class="inside">
									<div class="tablenav top easysql-ask4-tablenav">
										<div class="alignleft actions">
											<label class="screen-reader-text" for="easysql-ask4-page-size">
												<?php esc_html_e( 'Rows per page:', 'easysql' ); ?>
											</label>
											<select id="easysql-ask4-page-size" class="easysql-ask4-page-size">
												<option value="10">10</option>
												<option value="25">25</option>
												<option value="50" selected>50</option>
												<option value="100">100</option>
												<option value="250">250</option>
											</select>
											<button type="button" id="easysql-ask4-export" class="button action">
												<?php esc_html_e( 'Export to CSV', 'easysql' ); ?>
											</button>
										</div>
										<div class="tablenav-pages">
											<span class="displaying-num easysql-ask4-row-count" id="easysql-ask4-row-count"></span>
											<span class="pagination-links" id="easysql-ask4-pagination">
												<button type="button" class="button first-page button-small" id="easysql-ask4-first" disabled>&laquo;</button>
												<button type="button" class="button prev-page button-small" id="easysql-ask4-prev" disabled>&lsaquo;</button>
												<span class="paging-input">
													<label for="easysql-ask4-current-page" class="screen-reader-text"><?php esc_html_e( 'Current page', 'easysql' ); ?></label>
													<input class="current-page" id="easysql-ask4-current-page" type="number" min="1" value="1" size="2">
													<span class="tablenav-paging-text">
														<?php esc_html_e( 'of', 'easysql' ); ?>
														<span class="total-pages" id="easysql-ask4-total-pages">1</span>
													</span>
												</span>
												<button type="button" class="button next-page button-small" id="easysql-ask4-next" disabled>&rsaquo;</button>
												<button type="button" class="button last-page button-small" id="easysql-ask4-last" disabled>&raquo;</button>
											</span>
										</div>
										<br class="clear">
									</div>
									<table class="widefat striped" id="easysql-ask4-table">
										<thead></thead>
										<tbody></tbody>
									</table>
								</div>
							</div>

							<!-- Chart postbox -->
							<div id="easysql-ask4-chart" class="postbox easysql-ask4-chart" hidden>
								<div class="postbox-header">
									<h2 class="hndle"><span><?php esc_html_e( 'Chart', 'easysql' ); ?></span></h2>
								</div>
								<div class="inside">
									<div class="easysql-ask4-chart-grid">
										<div class="easysql-ask4-chart-main">
											<div class="easysql-ask4-chart-switcher">
												<button type="button" class="button button-small easysql-ask4-chart-tab is-active" data-chart-type="bar">
													<?php esc_html_e( 'Bar', 'easysql' ); ?>
												</button>
												<button type="button" class="button button-small easysql-ask4-chart-tab" data-chart-type="line">
													<?php esc_html_e( 'Line', 'easysql' ); ?>
												</button>
											</div>
											<div class="easysql-ask4-chart-canvas-wrap">
												<canvas id="easysql-ask4-chart-canvas"></canvas>
											</div>
										</div>
										<div class="easysql-ask4-chart-pie">
											<canvas id="easysql-ask4-chart-pie-canvas"></canvas>
										</div>
									</div>
								</div>
							</div>

							<!-- SQL postbox -->
							<div id="easysql-ask4-sql" class="postbox easysql-ask4-sql" hidden>
								<div class="postbox-header">
									<h2 class="hndle"><span><?php esc_html_e( 'Generated SQL', 'easysql' ); ?></span></h2>
									<div class="handle-actions">
										<button type="button" class="button button-small" id="easysql-ask4-sql-copy">
											<span id="easysql-ask4-sql-copy-label"><?php esc_html_e( 'Copy SQL', 'easysql' ); ?></span>
										</button>
									</div>
								</div>
								<div class="inside">
									<pre id="easysql-ask4-sql-pre" class="easysql-ask4-sql-pre"></pre>
								</div>
							</div>
						</div>
					</div>

					<!-- Side column: recent queries -->
					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox easysql-ask4-recent">
							<div class="postbox-header">
								<h2 class="hndle"><span><?php esc_html_e( 'Recent queries', 'easysql' ); ?></span></h2>
							</div>
							<div class="inside">
								<ul class="easysql-ask4-recent-list" id="easysql-ask4-recent-list">
									<li class="description easysql-ask4-recent-empty">
										<?php esc_html_e( 'No recent queries yet.', 'easysql' ); ?>
									</li>
								</ul>
								<p>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=easysql-history' ) ); ?>">
										<?php esc_html_e( 'View all history', 'easysql' ); ?>
									</a>
								</p>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>
		<?php
	}
}
