<?php
/**
 * Admin bar entry points into Daymark.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds two admin bar shortcuts, both gated on the same `edit_posts`
 * capability the app shell itself requires:
 *
 * - "Open Daymark" as a sibling of "Visit Site" under the site-name node
 *   (top-left of the bar) — same one-click intent as the Plugins list
 *   "Open Daymark" action link (Daymark_Plugin::add_action_links()), just
 *   available from anywhere the admin bar renders, not only Plugins.
 * - "Daymark" under the "+New" menu, jumping straight into the composer
 *   pre-set to an image Mark — the wp-admin equivalent of tapping the
 *   in-app Home launcher's Image bubble, for the moment someone is already
 *   in wp-admin and wants to start one.
 *
 * Registered at a late admin_bar_menu priority so both parent nodes
 * (`site-name`, added by WordPress core's wp_admin_bar_site_menu() at
 * priority 30, and `new-content`, added by wp_admin_bar_new_content_menu()
 * at priority 70) already exist by the time this runs.
 */
class Daymark_Admin_Bar {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 100 );
	}

	/**
	 * Add the admin bar nodes.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Core admin bar instance.
	 * @return void
	 */
	public function add_nodes( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( $wp_admin_bar->get_node( 'site-name' ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'daymark-open',
					'parent' => 'site-name',
					'title'  => __( 'Open Daymark', 'daymark' ),
					'href'   => esc_url( Daymark_Routes::app_url() ),
				)
			);
		}

		if ( $wp_admin_bar->get_node( 'new-content' ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'new-daymark',
					'parent' => 'new-content',
					'title'  => __( 'Daymark', 'daymark' ),
					// image: the composer's picker opens straight to "Take
					// Photo"/"Choose from library" for an image Mark, matching
					// what tapping the in-app launcher's Image bubble does —
					// see templates/app-shell.php's pendingType config and
					// assets/app.js's boot sequence for how the query var
					// seeds state.pendingType before the composer ever renders.
					'href'   => esc_url( Daymark_Routes::app_url() . '?daymark_type=image#create' ),
				)
			);
		}
	}
}
