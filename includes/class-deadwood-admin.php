<?php
/**
 * The Tools screen.
 *
 * @package Deadwood
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the report and handles the actions on it.
 */
class Deadwood_Admin {

	const SLUG       = 'deadwood';
	const CAPABILITY = 'activate_plugins';

	/**
	 * Hook the admin screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_deadwood_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_deadwood_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_deadwood_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the page under Tools.
	 */
	public static function register_page() {
		add_management_page(
			__( 'Deadwood', 'deadwood' ),
			__( 'Deadwood', 'deadwood' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the stylesheet on our screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'deadwood-admin',
			DEADWOOD_URL . 'assets/admin.css',
			array(),
			DEADWOOD_VERSION
		);
	}

	/**
	 * Run a scan on request.
	 */
	public static function handle_scan() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'deadwood' ) );
		}

		check_admin_referer( 'deadwood_scan' );

		$scanner = new Deadwood_Scanner();
		$store   = new Deadwood_Store();

		$result = $scanner->scan( true );
		$news   = $store->changes_worth_reporting( $result['verdicts'] );
		$store->save( $result['verdicts'], $result['summary'] );

		if ( ! empty( $news ) ) {
			$notifier = new Deadwood_Notifier();
			$notifier->send( $news, $result['summary'] );
		}

		wp_safe_redirect( add_query_arg( 'deadwood_scanned', '1', admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'deadwood' ) );
		}

		check_admin_referer( 'deadwood_settings' );

		$recipient = isset( $_POST['deadwood_email'] ) ? sanitize_email( wp_unslash( $_POST['deadwood_email'] ) ) : '';

		$store = new Deadwood_Store();
		$store->save_settings(
			array(
				'email_enabled'   => ! empty( $_POST['deadwood_email_enabled'] ),
				'email_recipient' => is_email( $recipient ) ? $recipient : get_option( 'admin_email' ),
			)
		);

		wp_safe_redirect( add_query_arg( 'deadwood_saved', '1', admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Send the last scan as CSV.
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'deadwood' ) );
		}

		check_admin_referer( 'deadwood_export' );

		$store  = new Deadwood_Store();
		$stored = $store->load();

		if ( null === $stored ) {
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
			exit;
		}

		$filename = sprintf( 'deadwood-%s-%s.csv', wp_parse_url( home_url(), PHP_URL_HOST ), gmdate( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, array( 'name', 'slug', 'type', 'status', 'score', 'active', 'installed_version', 'findings' ) );

		foreach ( $stored['verdicts'] as $v ) {
			$labels = array();
			foreach ( $v->factors as $factor ) {
				$labels[] = $factor['label'];
			}

			fputcsv(
				$out,
				array(
					$v->name,
					$v->slug,
					$v->type,
					$v->status,
					$v->score,
					$v->active ? 'yes' : 'no',
					$v->installed_version,
					implode( ' ', $labels ),
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$store  = new Deadwood_Store();
		$stored = $store->load();

		echo '<div class="wrap deadwood">';
		echo '<h1>' . esc_html__( 'Deadwood', 'deadwood' ) . '</h1>';

		if ( isset( $_GET['deadwood_scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan finished.', 'deadwood' ) . '</p></div>';
		}
		if ( isset( $_GET['deadwood_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'deadwood' ) . '</p></div>';
		}

		echo '<p class="deadwood-lede">' . esc_html__(
			'WordPress tells you when a plugin has an update. It does not tell you when a plugin stopped having updates, and it never tells you when one is pulled from the directory. This page covers both.',
			'deadwood'
		) . '</p>';

		self::render_actions( null !== $stored );

		if ( null === $stored ) {
			echo '<div class="deadwood-empty"><p>' . esc_html__( 'No scan yet. Run one to see where this site stands.', 'deadwood' ) . '</p></div>';
			self::render_settings( $store->settings() );
			echo '</div>';
			return;
		}

		self::render_summary( $stored['summary'] );
		self::render_table( $stored['verdicts'] );
		self::render_settings( $store->settings() );

		echo '</div>';
	}

	/**
	 * Buttons.
	 *
	 * @param bool $has_results Whether a scan exists.
	 */
	private static function render_actions( $has_results ) {
		echo '<div class="deadwood-actions">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'deadwood_scan' );
		echo '<input type="hidden" name="action" value="deadwood_scan" />';
		submit_button( __( 'Scan now', 'deadwood' ), 'primary', 'submit', false );
		echo '</form>';

		if ( $has_results ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'deadwood_export' );
			echo '<input type="hidden" name="action" value="deadwood_export" />';
			submit_button( __( 'Export CSV', 'deadwood' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Summary cards.
	 *
	 * @param array $summary Scan summary.
	 */
	private static function render_summary( array $summary ) {
		$counts = isset( $summary['counts'] ) ? $summary['counts'] : array();

		$cards = array(
			array( Deadwood_Verdict::STATUS_CLOSED, __( 'Pulled from the directory', 'deadwood' ), 'critical' ),
			array( Deadwood_Verdict::STATUS_ABANDONED, __( 'No longer maintained', 'deadwood' ), 'high' ),
			array( Deadwood_Verdict::STATUS_AGING, __( 'Slowing down', 'deadwood' ), 'medium' ),
			array( Deadwood_Verdict::STATUS_HEALTHY, __( 'Healthy', 'deadwood' ), 'good' ),
			array( Deadwood_Verdict::STATUS_UNLISTED, __( 'Not from the directory', 'deadwood' ), 'neutral' ),
			array( Deadwood_Verdict::STATUS_UNKNOWN, __( 'Could not check', 'deadwood' ), 'unknown' ),
		);

		echo '<div class="deadwood-cards">';

		foreach ( $cards as $card ) {
			list( $status, $label, $tone ) = $card;
			$count                          = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;

			printf(
				'<div class="deadwood-card deadwood-tone-%1$s"><span class="deadwood-card-count">%2$d</span><span class="deadwood-card-label">%3$s</span></div>',
				esc_attr( $tone ),
				(int) $count,
				esc_html( $label )
			);
		}

		echo '</div>';

		if ( ! empty( $summary['scanned_at'] ) ) {
			printf(
				'<p class="deadwood-meta">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human readable time difference */
						__( 'Last scanned %s ago.', 'deadwood' ),
						human_time_diff( (int) $summary['scanned_at'], time() )
					)
				)
			);
		}
	}

	/**
	 * The findings table.
	 *
	 * @param array<int,Deadwood_Verdict> $verdicts Verdicts.
	 */
	private static function render_table( array $verdicts ) {
		echo '<table class="widefat striped deadwood-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Item', 'deadwood' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'deadwood' ) . '</th>';
		echo '<th>' . esc_html__( 'Risk', 'deadwood' ) . '</th>';
		echo '<th>' . esc_html__( 'What was found', 'deadwood' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $verdicts as $v ) {
			self::render_row( $v );
		}

		echo '</tbody></table>';
	}

	/**
	 * One table row.
	 *
	 * @param Deadwood_Verdict $v Verdict.
	 */
	private static function render_row( Deadwood_Verdict $v ) {
		$labels = array(
			Deadwood_Verdict::STATUS_CLOSED    => __( 'Pulled', 'deadwood' ),
			Deadwood_Verdict::STATUS_ABANDONED => __( 'Unmaintained', 'deadwood' ),
			Deadwood_Verdict::STATUS_AGING     => __( 'Slowing', 'deadwood' ),
			Deadwood_Verdict::STATUS_HEALTHY   => __( 'Healthy', 'deadwood' ),
			Deadwood_Verdict::STATUS_UNLISTED  => __( 'Not listed', 'deadwood' ),
			Deadwood_Verdict::STATUS_UNKNOWN   => __( 'Unchecked', 'deadwood' ),
		);

		$tones = array(
			Deadwood_Verdict::STATUS_CLOSED    => 'critical',
			Deadwood_Verdict::STATUS_ABANDONED => 'high',
			Deadwood_Verdict::STATUS_AGING     => 'medium',
			Deadwood_Verdict::STATUS_HEALTHY   => 'good',
			Deadwood_Verdict::STATUS_UNLISTED  => 'neutral',
			Deadwood_Verdict::STATUS_UNKNOWN   => 'unknown',
		);

		$label = isset( $labels[ $v->status ] ) ? $labels[ $v->status ] : $v->status;
		$tone  = isset( $tones[ $v->status ] ) ? $tones[ $v->status ] : 'neutral';

		echo '<tr>';

		echo '<td class="deadwood-name"><strong>' . esc_html( $v->name ) . '</strong>';
		echo '<span class="deadwood-sub">' . esc_html( $v->type );
		if ( $v->active ) {
			echo ' &middot; ' . esc_html__( 'active', 'deadwood' );
		}
		if ( '' !== $v->installed_version ) {
			echo ' &middot; ' . esc_html( $v->installed_version );
		}
		echo '</span></td>';

		printf(
			'<td><span class="deadwood-pill deadwood-tone-%1$s">%2$s</span></td>',
			esc_attr( $tone ),
			esc_html( $label )
		);

		echo '<td class="deadwood-score">';
		if ( $v->score > 0 ) {
			echo esc_html( (string) $v->score );
		} else {
			echo '<span class="deadwood-dash">&mdash;</span>';
		}
		echo '</td>';

		echo '<td class="deadwood-findings">';

		if ( $v->is_unknown() ) {
			echo '<p class="deadwood-unknown-note">' . esc_html( $v->unknown_reason ) . '</p>';
			echo '<p class="deadwood-unknown-note"><em>' . esc_html__( 'This is not a pass. Nothing was verified about this item.', 'deadwood' ) . '</em></p>';
		} elseif ( empty( $v->factors ) ) {
			echo '<span class="deadwood-dash">' . esc_html__( 'Nothing to report.', 'deadwood' ) . '</span>';
		} else {
			foreach ( $v->factors as $factor ) {
				echo '<details class="deadwood-factor"><summary>' . esc_html( $factor['label'] ) . '</summary>';
				echo '<p>' . esc_html( $factor['evidence'] ) . '</p></details>';
			}
		}

		if ( Deadwood_Verdict::STATUS_CLOSED === $v->status || Deadwood_Verdict::STATUS_ABANDONED === $v->status ) {
			printf(
				'<p class="deadwood-link"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( 'https://wordpress.org/plugins/' . rawurlencode( $v->slug ) . '/' ),
				esc_html__( 'See its directory page', 'deadwood' )
			);
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Settings form.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_settings( array $settings ) {
		echo '<h2>' . esc_html__( 'Alerts', 'deadwood' ) . '</h2>';
		echo '<p class="deadwood-meta">' . esc_html__(
			'Deadwood scans weekly and emails you only when something changes, never to repeat what you have already seen.',
			'deadwood'
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'deadwood_settings' );
		echo '<input type="hidden" name="action" value="deadwood_settings" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Email me', 'deadwood' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="deadwood_email_enabled" value="1" %s /> %s</label>',
			checked( ! empty( $settings['email_enabled'] ), true, false ),
			esc_html__( 'when a plugin is pulled from the directory or stops being maintained', 'deadwood' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="deadwood_email">' . esc_html__( 'Send to', 'deadwood' ) . '</label></th><td>';
		printf(
			'<input type="email" class="regular-text" id="deadwood_email" name="deadwood_email" value="%s" />',
			esc_attr( isset( $settings['email_recipient'] ) ? $settings['email_recipient'] : '' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save', 'deadwood' ) );
		echo '</form>';
	}
}
