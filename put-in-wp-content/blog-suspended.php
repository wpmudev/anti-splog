<?php
/*
Plugin Name: Anti-Splog (Spammed Notice and Splog Review Form)
Plugin URI: https://premium.wpmudev.org/project/anti-splog/
Description: The ultimate plugin and service to stop and kill splogs in WordPress Multisite and BuddyPress
Author: WPMU DEV
Author URI: http://premium.wpmudev.org/
*/
if ( file_exists( WP_PLUGIN_DIR . '/anti-splog/includes/blog-suspended-template.php' ) ) {
	require_once( WP_PLUGIN_DIR . '/anti-splog/includes/blog-suspended-template.php' );
} else {
	wp_die( __( 'This site has been archived or suspended.' ), '', array( 'response' => 410 ) );
}