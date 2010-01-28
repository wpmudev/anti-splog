<?php
/*
Plugin Name: Anti-Splog
Version: 1.0
Plugin URI: http://incsub.com
Description: The ultimate plugin to stop and kill splogs in WPMU
Author: Aaron Edwards at uglyrobot.com (for Incsub)
Author URI: http://uglyrobot.com
WDP ID: 120

Copyright 2010 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

//------------------------------------------------------------------------//

//---Config---------------------------------------------------------------//

//------------------------------------------------------------------------//

$ust_current_version = '1.0.0';
$ust_api_url = 'http://premium.wpmudev.org/ust-api.php';

//------------------------------------------------------------------------//

//---Hook-----------------------------------------------------------------//

//------------------------------------------------------------------------//

//check for activating
if ($_GET['key'] == '' || $_GET['key'] === '') {
	add_action('admin_head', 'ust_make_current');
}

add_action('plugins_loaded', 'ust_localization');
//wp-signup changes
add_action('plugins_loaded', 'ust_wpsignup_init');
add_filter('the_content', 'ust_wpsignup_shortcode');
//keep table updated
add_action('make_spam_blog', 'ust_blog_spammed');
add_action('make_ham_blog', 'ust_blog_unspammed');
add_action('wpmu_new_blog', 'ust_blog_created', 10, 2);
add_action('delete_blog', 'ust_blog_deleted', 10, 2);
add_action('wpmu_delete_user', 'ust_user_deleted');
add_action('wpmu_blog_updated', 'ust_blog_updated');
//replace new blog email function
remove_action('wpmu_new_blog', 'newblog_notify_siteadmin', 10, 2);
add_action('wpmu_new_blog', 'ust_newblog_notify_siteadmin', 10, 2);
//various
add_action('admin_init', 'ust_admin_scripts_init');
add_action('save_post', 'ust_check_post');
add_action('admin_menu', 'ust_plug_pages');
add_action('admin_notices', 'ust_api_warning');
add_action('signup_blogform', 'ust_signup_fields', 50);
add_action('bp_after_blog_details_fields', 'ust_signup_fields_bp', 50); //buddypress support
add_filter('wpmu_validate_blog_signup', 'ust_signup_errorcheck');
add_filter('bp_signup_validate', 'ust_signup_errorcheck_bp'); //buddypress support
add_filter('wpmu_validate_blog_signup', 'ust_signup_multicheck', 1);
add_filter('add_signup_meta', 'ust_signup_meta');
add_filter('bp_signup_usermeta', 'ust_signup_meta'); //buddypress support
add_action('signup_header', 'ust_signup_css');
add_action('ust_check_api_cron', 'ust_check_api'); //cron action
add_action('plugins_loaded', 'ust_show_widget');
add_action('muplugins_loaded', 'ust_preview_splog');
add_action('wp_ajax_ust_ajax', 'ust_do_ajax'); //ajax
add_filter('site_option_no_anti_spam_nag', create_function('', 'return 1;')); //remove 2.9 spam nag


//------------------------------------------------------------------------//

//---Functions------------------------------------------------------------//

//------------------------------------------------------------------------//

function ust_show_widget() {
	global $current_site, $blog_id;

  if ($current_site->blog_id == $blog_id)
    add_action('widgets_init', create_function('', 'return register_widget("UST_Widget");') );
}

function ust_localization() {
  // Load up the localization file if we're using WordPress in a different language
	// Place it in this plugin's "languages" folder and name it "ust-[locale].mo"
	load_plugin_textdomain( 'ust', 'wp-content/mu-plugins/anti-splog/languages' );
}

function ust_make_current() {

	global $wpdb, $ust_current_version;

	if (get_site_option( "ust_version" ) == '') {
		add_site_option( 'ust_version', '0.0.0' );
	}

	if (get_site_option( "ust_version" ) == $ust_current_version) {
		// do nothing
	} else {
		//update to current version
		update_site_option( "ust_installed", "no" );
		update_site_option( "ust_version", $ust_current_version );
	}
	ust_global_install();
}

function ust_global_install() {

	global $wpdb, $ust_current_version;

	if (get_site_option( "ust_installed" ) == '') {
		add_site_option( 'ust_installed', 'no' );
	}

	if (get_site_option( "ust_installed" ) == "yes") {
		// do nothing
	} else {
	  //create table
		$ust_table1 = "CREATE TABLE IF NOT EXISTS `" . $wpdb->base_prefix . "ust` (
                                  `blog_id` bigint(20) unsigned NOT NULL,
                                  `last_user_id` bigint(20) NULL DEFAULT NULL,
                                  `last_ip` varchar(30),
                                  `last_user_agent` varchar(255),
                                  `spammed` DATETIME default '0000-00-00 00:00:00',
                                  `certainty` int(3) NOT NULL default '0',
                                  `ignore` int(1) NOT NULL default '0',
                                  PRIMARY KEY  (`blog_id`)
                                ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $wpdb->query( $ust_table1 );

    //insert every blog_id
    $ust_query1 = "INSERT INTO `" . $wpdb->base_prefix . "ust` (`blog_id`) SELECT blog_id FROM `" . $wpdb->blogs . "` WHERE 1";
		$wpdb->query( $ust_query1 );

		//best guess estimate of spammed time by last updated
		$ust_query2 = "UPDATE ".$wpdb->base_prefix."ust u, ".$wpdb->blogs." b SET u.spammed = b.last_updated WHERE u.blog_id = b.blog_id AND b.spam = 1";
		$wpdb->query( $ust_query2 );

    //default options
    $ust_settings['api_key'] = '';
	  $ust_settings['certainty'] = 80;
    $ust_settings['post_certainty'] = 90;
	  $ust_settings['num_signups'] = '';
	  $ust_settings['strip'] = 0;
	  $ust_settings['paged_blogs'] = 15;
	  $ust_settings['paged_posts'] = 3;
	  $ust_settings['keywords'] = array('ugg', 'pharma', 'erecti');
	  $ust_settings['signup_protect'] = 'none';
	  update_site_option("ust_settings", $ust_settings);

		update_site_option( "ust_installed", "yes" );
	}
}

function ust_wpsignup_init() {
  global $blog_id, $current_site;

  //if on main blog
  if ($current_site->blog_id == $blog_id) {
    $ust_signup = get_site_option('ust_signup');
    if (!$ust_signup['active'])
      return;

  	add_filter('root_rewrite_rules', 'ust_wpsignup_rewrite');
  	add_filter('query_vars', 'ust_wpsignup_queryvars');
  	add_action('pre_get_posts', 'ust_wpsignup_page');
  	add_action('init', 'ust_wpsignup_flush_rewrite');
  	add_action('init', 'ust_wpsignup_change', 99); //run after the flush in case link has expired on already open page
  	add_action('init', 'ust_wpsignup_kill');
    add_filter('wp_signup_location', 'ust_wpsignup_filter');
  }
}

function ust_wpsignup_rewrite($rules){
	$ust_signup = get_site_option('ust_signup');

	$rules[$ust_signup['slug'] . '/?$'] = 'index.php?namespace=ust&newblog=$matches[1]';
	return $rules;
}

function ust_wpsignup_change(){
	$ust_signup = get_site_option('ust_signup');
	//change url every 24 hours
	if ($ust_signup['expire'] < time()) {
    $ust_signup['expire'] = time() + 86400; //extend 24 hours
    $ust_signup['slug'] = 'signup-'.substr(md5(time()), rand(0,30), 3); //create new random signup url
    update_site_option('ust_signup', $ust_signup);
    //clear cache if WP Super Cache is enabled
    if (function_exists('wp_cache_clear_cache'))
      wp_cache_clear_cache();
  }
}

function ust_wpsignup_flush_rewrite() {
	// This function clears the rewrite rules and forces them to be regenerated
	global $wp_rewrite;
	$wp_rewrite->flush_rules();
}

function ust_wpsignup_queryvars($vars) {
	// This function add the namespace (if it hasn't already been added) and the
	// eventperiod queryvars to the list that WordPress is looking for.
	// Note: Namespace provides a means to do a quick check to see if we should be doing anything
	if(!in_array('namespace',$vars)) $vars[] = 'namespace';
	$vars[] = 'newblog';

	return $vars;
}

function ust_wpsignup_page() {
	global $wp_query;

	if(isset($wp_query->query_vars['namespace']) && $wp_query->query_vars['namespace'] == 'ust') {

		// Set up the property query variables
		if(isset($wp_query->query_vars['newblog'])) $_GET['new'] = $wp_query->query_vars['newblog'];

		//include the signup page
    $wp_query->is_home = false;
		require_once('anti-splog/ust-wp-signup.php');

		die();
	}
}

/* Kill the wp-signup.php if custom registration signup templates are present */
function ust_wpsignup_kill() {
  global $current_site;

	if ( false === strpos( $_SERVER['SCRIPT_NAME'], 'wp-signup.php') )
		return false;

  /* could make it easy for sploggers to get current url from location header by setting the new variable
  if (isset($_GET['new'])) {
    $ust_signup = get_site_option('ust_signup');
    header( "Location: http://" . $current_site->domain . $current_site->path . $ust_signup['slug'] . "/?new=" . $_GET['new'];
  }
  */

  header("HTTP/1.0 404 Not Found");
	die(__('The signup page location has been changed.', 'ust'));
}

function ust_wpsignup_filter() {
	// filters redirect in wp-login.php
	return ust_wpsignup_url(false);
}

function ust_wpsignup_shortcode($content) {
  //replace shortcodes in content
  $content = str_replace( '[ust_wpsignup_url]', ust_wpsignup_url(false), $content );

  //replace unchanged wp-signup.php calls too
  $ust_signup = get_site_option('ust_signup');
  if ($ust_signup['active'])
    $content = str_replace( 'wp-signup.php', $ust_signup['slug'].'/', $content );

	return $content;
}

function ust_blog_spammed($blog_id) {
  global $wpdb, $current_site;

  //prevent the spamming of supporters
  if (function_exists('is_supporter') && is_supporter($blog_id)) {
    update_blog_status( $blog_id, "spam", '0' );
    return;
  }

  $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET spammed = '".current_time('mysql', true)."' WHERE blog_id = '$blog_id' LIMIT 1");

  //update spam stat
  $num = get_site_option('ust_spam_count');
  if (!$num) $num = 0;
  update_site_option('ust_spam_count', ($num+1));

  //don't send splog data if it was spammed automatically
  $auto_spammed = get_blog_option($blog_id, 'ust_auto_spammed');
  $post_auto_spammed = get_blog_option($blog_id, 'ust_post_auto_spammed');
  if (!$auto_spammed && !$post_auto_spammed) {
    //collect info
    $api_data = get_blog_option($blog_id, 'ust_signup_data');
    if (!$api_data) {
      $blog = $wpdb->get_row("SELECT * FROM {$wpdb->blogs} WHERE blog_id = '$blog_id'", ARRAY_A);
      $api_data['activate_user_ip'] = $wpdb->get_var("SELECT `IP` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
      $api_data['user_email'] = $wpdb->get_var("SELECT `email` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
      $api_data['blog_registered'] = $blog['registered'];
      $api_data['blog_domain'] = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
      $api_data['blog_title'] = get_blog_option($blog_id, 'blogname');
    }
    $last = $wpdb->get_row("SELECT * FROM {$wpdb->base_prefix}ust WHERE blog_id = '$blog_id'");
    $api_data['last_user_id'] = $last->last_user_id;
    $api_data['last_ip'] = $last->last_ip;
    $api_data['last_user_agent'] = $last->last_user_agent;

    //latest post
    $post = $wpdb->get_row("SELECT post_title, post_content FROM `{$wpdb->base_prefix}{$blog_id}_posts` WHERE post_status = 'publish' AND post_type = 'post' AND ID != '1' ORDER BY post_date DESC LIMIT 1");
    if ($post)
      $api_data['post_content'] = $post->post_title . "\n" . $post->post_content;

    //send blog info to API
    ust_http_post('spam_blog', $api_data);
  }
}

function ust_blog_unspammed($blog_id, $ignored=false) {
  global $wpdb, $current_site;

  if (!$ignored) {
    //update spam stat
    $num = get_site_option('ust_spam_count');
    if (!$num || $num = 0)
      $num = 0;
    else
      $num = $num-1;
    update_site_option('ust_spam_count', $num);

    //remove auto spammed status in case it is manually spammed again later
    update_blog_option($blog_id, 'ust_auto_spammed', 0);
    update_blog_option($blog_id, 'ust_post_auto_spammed', 0);
  }

  //collect info
  $api_data = get_blog_option($blog_id, 'ust_signup_data');
  if (!$api_data) {
    $blog = $wpdb->get_row("SELECT * FROM {$wpdb->blogs} WHERE blog_id = '$blog_id'", ARRAY_A);
    $api_data['activate_user_ip'] = $wpdb->get_var("SELECT `IP` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
    $api_data['user_email'] = $wpdb->get_var("SELECT `email` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
    $api_data['blog_registered'] = $blog['registered'];
    $api_data['blog_domain'] = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
    $api_data['blog_title'] = get_blog_option($blog_id, 'blogname');
  }
  $last = $wpdb->get_row("SELECT * FROM {$wpdb->base_prefix}ust WHERE blog_id = '$blog_id'");
  $api_data['last_user_id'] = $last->last_user_id;
  $api_data['last_ip'] = $last->last_ip;
  $api_data['last_user_agent'] = $last->last_user_agent;

  //latest post
  $post = $wpdb->get_row("SELECT post_title, post_content FROM `{$wpdb->base_prefix}{$blog_id}_posts` WHERE post_status = 'publish' AND post_type = 'post' AND ID != '1' ORDER BY post_date DESC LIMIT 1");
  if ($post)
    $api_data['post_content'] = $post->post_title . "\n" . $post->post_content;

  //send blog info to API
  ust_http_post('unspam_blog', $api_data);
}

function ust_blog_created($blog_id, $user_id) {
  global $wpdb, $current_site;
  $ust_signup_data = get_blog_option($blog_id, 'ust_signup_data');
  $user = new WP_User( (int) $user_id );
  $ip = preg_replace('/[^0-9., ]/', '', $_SERVER['REMOTE_ADDR']);
  $blog = $wpdb->get_row("SELECT * FROM {$wpdb->blogs} WHERE blog_id = '$blog_id'", ARRAY_A);

  //collect signup info
  $api_data = $ust_signup_data;
  $api_data['activate_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
  $api_data['activate_user_ip'] = $ip;
  $api_data['activate_user_referer'] = $_SERVER['HTTP_REFERER'];
  $api_data['user_login'] = $user->user_login;
  $api_data['user_email'] = $user->user_email;
  $api_data['user_registered'] = $user->user_registered;
  $api_data['blog_domain'] = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
  $api_data['blog_title'] = get_blog_option($blog_id, 'blogname');
  $api_data['blog_registered'] = $blog['registered'];

  //don't test if a site admin or supporter or blog-user-creator plugin is creating the blog
  if (is_site_admin() || (function_exists('is_supporter') && is_supporter()) || strpos($_SERVER['REQUEST_URI'], 'blog-user-creator')) {
    $certainty = 0;
  } else {
    //send blog info to API
    $result = ust_http_post('check_blog', $api_data);
    if ($result) {
      $certainty = (int)$result;
    } else {
      $certainty = 0;
    }
  }

  //create new record in ust table
  $wpdb->query( $wpdb->prepare("INSERT INTO `" . $wpdb->base_prefix . "ust` (blog_id, last_user_id, last_ip, last_user_agent, certainty) VALUES (%d, %d, %s, %s, %d)", $blog_id, $user->ID, $ip, $_SERVER['HTTP_USER_AGENT'], $certainty) );

  //save data to blog for retrieval in case it's spammed later
  update_blog_option($blog_id, 'ust_signup_data', $api_data);

  //spam blog if certainty is met
  $ust_settings = get_site_option("ust_settings");
  if ($certainty >= $ust_settings['certainty']) {
    update_blog_option($blog_id, 'ust_auto_spammed', 1);
    update_blog_status($blog_id, "spam", '1', 1);
  }
}

function ust_check_post($tmp_post_ID) {
  global $wpdb, $current_site, $blog_id;

  if (!$blog_id)
    $blog_id = $wpdb->blogid;

  $tmp_post = get_post($tmp_post_ID);

  $api_data = get_option('ust_signup_data');

  //only check the first valid post for blogs that were created after plugin installed
  if (get_option('ust_first_post') || !$api_data || $tmp_post->post_status != 'publish' || $tmp_post->post_type != 'post' || $tmp_post->post_content == '')
    return;

  //collect info
  if (!$api_data) {
    $blog = $wpdb->get_row("SELECT * FROM {$wpdb->blogs} WHERE blog_id = '$blog_id'", ARRAY_A);
    $api_data['activate_user_ip'] = $wpdb->get_var("SELECT `IP` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
    $api_data['user_email'] = $wpdb->get_var("SELECT `email` FROM {$wpdb->registration_log} WHERE blog_id = '$blog_id'");
    $api_data['blog_registered'] = $blog['registered'];
    $api_data['blog_domain'] = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
    $api_data['blog_title'] = get_blog_option($blog_id, 'blogname');
  }
  $last = $wpdb->get_row("SELECT * FROM {$wpdb->base_prefix}ust WHERE blog_id = '$blog_id'");
  $api_data['last_user_id'] = $last->last_user_id;
  $api_data['last_ip'] = $last->last_ip;
  $api_data['last_user_agent'] = $last->last_user_agent;

  //add post title/content
  $api_data['post_content'] = $tmp_post->post_title . "\n" . $tmp_post->post_content;

  //send blog info to API
  $result = ust_http_post('check_post', $api_data);
  if ($result) {
    $certainty = (int)$result;
  } else {
    $certainty = 0;
  }

  //update certainty in table if greater
  $last_certainty = $wpdb->get_var("SELECT certainty FROM {$wpdb->base_prefix}ust WHERE blog_id = '$blog_id'");
  if ($certainty > $last_certainty && $certainty > 60)
    $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET `certainty` = $certainty WHERE blog_id = '$blog_id' LIMIT 1");

  //save action so we don't check this blog again
  if ($result >= 0)
    update_option('ust_first_post', 1);

  //spam blog if certainty is met
  $ust_settings = get_site_option("ust_settings");
  if ($certainty >= $ust_settings['post_certainty']) {
    update_blog_option($blog_id, 'ust_post_auto_spammed', 1);
    update_blog_status($blog_id, "spam", '1', 1);
  }
}

function ust_blog_ignore($blog_id, $report=true) {
  global $wpdb;
  $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET `ignore` = '1' WHERE blog_id = '$blog_id' LIMIT 1");

  //send info to API for learning
  if ($report)
    ust_blog_unspammed($blog_id, true);
}

function ust_blog_unignore($blog_id) {
  global $wpdb;
  $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET `ignore` = '0' WHERE blog_id = '$blog_id' LIMIT 1");
}

function ust_blog_deleted($blog_id, $drop) {
  global $wpdb;

  if ($drop)
    $wpdb->query("DELETE FROM `" . $wpdb->base_prefix . "ust` WHERE blog_id = '$blog_id' LIMIT 1");
}

function ust_user_deleted($user_id) {
  global $wpdb;
  $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET last_user_id = NULL WHERE last_user_id = '$user_id'");
}

function ust_blog_updated($blog_id) {
  global $wpdb, $current_user;
  $wpdb->query("UPDATE `" . $wpdb->base_prefix . "ust` SET last_user_id = '".$current_user->ID."', last_ip = '".$_SERVER['REMOTE_ADDR']."', last_user_agent = '".addslashes($_SERVER['HTTP_USER_AGENT'])."' WHERE blog_id = '$blog_id' LIMIT 1");
}

function ust_plug_pages() {
	if ( is_site_admin() ) {
		$page = add_submenu_page('wpmu-admin.php', __('Anti-Splog', 'ust'), __('Anti-Splog', 'ust'), 10, 'ust', 'ust_admin_output');
	  /* Using registered $page handle to hook script load */
    add_action('admin_print_scripts-' . $page, 'ust_admin_script');
    add_action('admin_print_styles-' . $page, 'ust_admin_style');
  }
}

function ust_preview_splog() {
  global $current_blog;

  //temporarily unspams the blog while previewing from Splogs queue
  if (strpos($_SERVER['HTTP_REFERER'], 'wpmu-admin.php?page=ust&tab=splogs'))
    $current_blog->spam = '0';
}

function ust_do_ajax() {
	global $wpdb, $current_site;

  //make sure we have permission!
  if (!is_site_admin())
		die();

	$query = parse_url($_POST['url']);
  parse_str($query['query'], $_GET);

  //process any actions and messages
	if ( isset($_GET['spam_user']) ) {
	  //spam a user and all blogs they are associated with

	  //don't spam site admin
		$user_info = get_userdata((int)$_GET['spam_user']);
		if (!is_site_admin($user_info->user_login)) {
  		$blogs = get_blogs_of_user( (int)$_GET['spam_user'], true );
  		foreach ( (array) $blogs as $key => $details ) {
  			if ( $details->userblog_id == $current_site->blog_id ) { continue; } // main blog not a spam !
  			update_blog_status( $details->userblog_id, "spam", '1', 0 );
  			set_time_limit(60);
  		}
  		update_user_status( (int)$_GET['spam_user'], "spam", '1', 1 );
  	}

	} else if ( isset($_POST['check_ip']) ) {
	  //count all blogs created or modified with the IP address
	  $ip_query = parse_url($_POST['check_ip']);
    parse_str($ip_query['query'], $ip_data);
	  $spam_ip = addslashes($ip_data['spam_ip']);

	  $query = "SELECT COUNT(b.blog_id)
        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.blog_id = r.blog_id
        				AND b.blog_id = u.blog_id
        				AND b.spam = 0
        				AND (r.IP = '$spam_ip' OR u.last_ip = '$spam_ip')";
    $query2 = "SELECT COUNT(b.blog_id)
        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.blog_id = r.blog_id
        				AND b.blog_id = u.blog_id
        				AND b.spam = 1
        				AND (r.IP = '$spam_ip' OR u.last_ip = '$spam_ip')";
    //return json response
  	echo '{"num":"'.$wpdb->get_var($query).'", "numspam":"'.$wpdb->get_var($query2).'", "bid":"'.$ip_data['id'].'", "ip":"'.$ip_data['spam_ip'].'"}';

	} else if ( isset($_GET['spam_ip']) ) {
	  //spam all blogs created or modified with the IP address
	  $spam_ip = addslashes($_GET['spam_ip']);
	  $query = "SELECT b.blog_id
        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.blog_id = r.blog_id
        				AND b.blog_id = u.blog_id
        				AND b.spam = 0
        				AND (r.IP = '$spam_ip' OR u.last_ip = '$spam_ip')";
  	$blogs = $wpdb->get_results( $query, ARRAY_A );
		foreach ( (array) $blogs as $blog ) {
      if ( $blog['blog_id'] == $current_site->blog_id ) { continue; } // main blog not a spam !
			update_blog_status( $blog['blog_id'], "spam", '1', 0 );
			set_time_limit(60);
		}

	} else if ( isset($_GET['ignore_blog']) ) {
	  //ignore a single blog so it doesn't show up on the possible spam list
		ust_blog_ignore((int)$_GET['id']);
		echo $_GET['id'];

	} else if ( isset($_GET['unignore_blog']) ) {
	  //unignore a single blog so it can show up on the possible spam list
		ust_blog_unignore((int)$_GET['id']);
		echo $_GET['id'];

  } else if ( isset($_GET['spam_blog']) ) {
	  //spam a single blog
	  update_blog_status( (int)$_GET['id'], "spam", '1', 1 );
		echo $_GET['id'];

	} else if (isset($_GET['unspam_blog'])) {

    update_blog_status( (int)$_GET['id'], "spam", '0', 1 );
    ust_blog_ignore((int)$_GET['id'], false);
    echo $_GET['id'];

  } else if (isset($_POST['allblogs'])) {
    parse_str($_POST['allblogs'], $blog_list);

		foreach ( (array) $blog_list['allblogs'] as $key => $val ) {
			if( $val != '0' && $val != $current_site->blog_id ) {
				if ( isset($_POST['allblog_ignore']) ) {
					ust_blog_ignore($val);
					set_time_limit(60);
        } else if ( isset($_POST['allblog_unignore']) ) {
					ust_blog_unignore($val);
					set_time_limit(60);
				} else if ( isset($_POST['allblog_spam']) ) {
					update_blog_status( $val, "spam", '1', 0 );
					set_time_limit(60);
				} else if ( isset($_POST['allblog_notspam']) ) {
					update_blog_status( $val, "spam", '0', 0 );
					ust_blog_ignore( $val, false );
					set_time_limit(60);
				}
			}
		}
    _e("Selected blogs processed", 'ust');
  }

	die();
}

// call with array of additional commands
function ust_http_post($action='api_check', $request=false) {
	global $wp_version, $ust_current_version, $ust_api_url, $current_site;
  $ust_settings = get_site_option("ust_settings");

  //if api key is not set/valid
  if (!$ust_settings['api_key'] && $action != 'api_check')
    return false;

  //create the default request
  if (!$request["API_KEY"])
    $request["API_KEY"] = $ust_settings['api_key'];
  $request["SITE_DOMAIN"] =  $current_site->domain;
  $request["ACTION"] = $action;

	$query_string = '';
	if (is_array($request)) {
  	foreach ( $request as $key => $data )
  		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';
	}

	//build args
	$args['user-agent'] = "WordPress MU/$wp_version | Anti-Splog/$ust_current_version";
	$args['body'] = $query_string;

	$response = wp_remote_post($ust_api_url, $args);

	if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {

    if ($action != 'api_check') {
      //schedule a check in 24 hours to determine API key is valid (in case it's not a temporary server issue)
    	switch_to_blog($current_site->blog_id);
    	if (!wp_next_scheduled('ust_check_api_cron'))
        wp_schedule_single_event(time()+86400, 'ust_check_api_cron');
    	restore_current_blog();
  	}

    return false;
  } else {
    return $response['body'];
  }
}

function ust_check_api() {
  global $current_site;
  $ust_url = 'http://' . $current_site->domain . $current_site->path . "wp-admin/wpmu-admin.php?page=ust&tab=settings";

  //check the api key and connection
  $api_response = ust_http_post();
  if ($api_response && $api_response != 'Valid') {
    $message = __(sprintf("There seems to be a problem with the Anti-Splog plugin API key on your server at %s.\n%s\n\nFix it here: %s", $current_site->domain, $api_response, $ust_url), 'ust');
  } else if (!$api_response) {
    $message = __(sprintf("The Anti-Splog plugin on your server at %s is having a problem connecting to the API server.\n\nFix it here: %s", $current_site->domain, $ust_url), 'ust');
  }

  if ($message) {
    //email site admin
    $admin_email = get_site_option( "admin_email" );
    $subject = __('A problem with your Anti-Splog plugin', 'ust');
    wp_mail($admin_email, $subject, $message);

    //clear API key
    $ust_settings = get_site_option("ust_settings");
    $ust_settings['api_key'] = '';
    update_site_option("ust_settings", $ust_settings);
  }
}

function ust_signup_errorcheck($content) {
  $ust_settings = get_site_option("ust_settings");

  if($ust_settings['signup_protect'] == 'recaptcha') {

    //check reCAPTCHA
    $recaptcha = get_site_option('ust_recaptcha');
  	require_once('anti-splog/recaptchalib.php');
  	$resp = rp_recaptcha_check_answer($recaptcha['privkey'], $_SERVER["REMOTE_ADDR"], $_POST["recaptcha_challenge_field"], $_POST["recaptcha_response_field"]);

  	if (!$resp->is_valid) {
  	  $content['errors']->add('recaptcha', __("The reCAPTCHA wasn't entered correctly. Please try again.", 'ust'));
  	}

  } else if($ust_settings['signup_protect'] == 'asirra') {

    require_once('anti-splog/asirra.php');
    $asirra = new AsirraValidator($_POST['Asirra_Ticket']);
  	if (!$asirra->passed)
      $content['errors']->add('asirra', __("Please try to correctly identify the cats again.", 'ust'));

	} else if ($ust_settings['signup_protect'] == 'questions') {

    $ust_qa = get_site_option("ust_qa");
    if (is_array($ust_qa) && count($ust_qa)) {
      //check the encrypted answer field
      $salt = get_site_option("ust_salt");
      $datesalt = strtotime(date('Y-m-d H:00:00'));
      $valid_fields = false;
      foreach ($ust_qa as $qkey=>$answer) {
        $field_name = 'qa_'.md5($qkey.$salt.$datesalt);
        if (isset($_POST[$field_name])) {
          if (strtolower(trim($_POST[$field_name])) != strtolower(stripslashes($answer[1])))
            $content['errors']->add('qa', __("Incorrect Answer. Please try again.", 'ust'));
          $valid_fields = true;
        }
      }
      //if no fields are valid try again for previous hour
      if (!$valid_fields) {
        $datesalt = strtotime('-1 hour', $datesalt);
        foreach ($ust_qa as $qkey=>$answer) {
          $field_name = 'qa_'.md5($qkey.$salt.$datesalt);
          if (isset($_POST[$field_name])) {
            if (strtolower(trim($_POST[$field_name])) != strtolower(stripslashes($answer[1])))
              $content['errors']->add('qa', __("Incorrect Answer. Please try again.", 'ust'));
          }
        }
      }
    }

  }

	return $content;
}

function ust_signup_errorcheck_bp() {
  global $bp;
  $ust_settings = get_site_option("ust_settings");

  if($ust_settings['signup_protect'] == 'recaptcha') {

    //check reCAPTCHA
    $recaptcha = get_site_option('ust_recaptcha');
  	require_once('anti-splog/recaptchalib.php');
  	$resp = rp_recaptcha_check_answer($recaptcha['privkey'], $_SERVER["REMOTE_ADDR"], $_POST["recaptcha_challenge_field"], $_POST["recaptcha_response_field"]);

  	if (!$resp->is_valid) {
  	  $bp->signup->errors['recaptcha'] = __("The reCAPTCHA wasn't entered correctly. Please try again.", 'ust');
  	}

  } else if($ust_settings['signup_protect'] == 'asirra') {

    require_once('anti-splog/asirra.php');
    $asirra = new AsirraValidator($_POST['Asirra_Ticket']);
  	if (!$asirra->passed)
      $bp->signup->errors['asirra'] = __("Please try to correctly identify the cats again.", 'ust');

	} else if ($ust_settings['signup_protect'] == 'questions') {

    $ust_qa = get_site_option("ust_qa");
    if (is_array($ust_qa) && count($ust_qa)) {
      //check the encrypted answer field
      $salt = get_site_option("ust_salt");
      $datesalt = strtotime(date('Y-m-d H:00:00'));
      $valid_fields = false;
      foreach ($ust_qa as $qkey=>$answer) {
        $field_name = 'qa_'.md5($qkey.$salt.$datesalt);
        if (isset($_POST[$field_name])) {
          if (strtolower(trim($_POST[$field_name])) != strtolower(stripslashes($answer[1])))
            $bp->signup->errors['qa'] = __("Incorrect Answer. Please try again.", 'ust');
          $valid_fields = true;
        }
      }
      //if no fields are valid try again for previous hour
      if (!$valid_fields) {
        $datesalt = strtotime('-1 hour', $datesalt);
        foreach ($ust_qa as $qkey=>$answer) {
          $field_name = 'qa_'.md5($qkey.$salt.$datesalt);
          if (isset($_POST[$field_name])) {
            if (strtolower(trim($_POST[$field_name])) != strtolower(stripslashes($answer[1])))
              $bp->signup->errors['qa'] = __("Incorrect Answer. Please try again.", 'ust');
          }
        }
      }
    }

  }
}

//check for multiple signups from the same IP in 24 hours
function ust_signup_multicheck($content) {
  global $wpdb;
  $ust_settings = get_site_option("ust_settings");

  if ($ust_settings['num_signups']) {
    $date = date('Y-m-d H:i:s', strtotime('-1 day', time()));
    $ips = $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->registration_log} WHERE IP = '{$_SERVER['REMOTE_ADDR']}' AND date_registered >= '$date'");
    if ($ips > $ust_settings['num_signups'])
      $content['errors']->add('blogname', __("A limited number of blogs can be created in a short period of time. If you are not a spammer please try again in 24 hours.", 'ust'));
  }
  return $content;
}

function ust_signup_meta($meta) {

  $ust_signup_data['signup_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
  $ust_signup_data['signup_user_ip'] = preg_replace( '/[^0-9., ]/', '', $_SERVER['REMOTE_ADDR'] );
  $ust_signup_data['signup_user_referer'] = $_SERVER['HTTP_REFERER'];

  $meta['ust_signup_data'] = $ust_signup_data;

  return $meta;
}

//replace new blog admin notification email
function ust_newblog_notify_siteadmin( $blog_id, $deprecated = '' ) {
	global $current_site;
	if( get_site_option( 'registrationnotification' ) != 'yes' )
		return false;

	$email = get_site_option( 'admin_email' );
	if( is_email($email) == false )
		return false;

	switch_to_blog( $blog_id );
	$blogname = get_option( 'blogname' );
	$siteurl = get_option( 'siteurl' );
	restore_current_blog();

	$spam_url = clean_url("http://{$current_site->domain}{$current_site->path}wp-admin/wpmu-edit.php?action=confirm&action2=spamblog&id=$blog_id&ref=" . urlencode("http://{$current_site->domain}{$current_site->path}wp-admin/wpmu-admin.php?page=ust") . "&msg=" . urlencode( sprintf( __( "You are about to mark the blog %s as spam" ), $blogname ) ) );
	$ust_url = clean_url("http://{$current_site->domain}{$current_site->path}wp-admin/wpmu-admin.php?page=ust");
	$options_site_url = clean_url("http://{$current_site->domain}{$current_site->path}wp-admin/wpmu-options.php");

	$msg = sprintf( __( "New Blog: %1s
URL: %2s
Remote IP: %3s

Spam this blog: %4s
View suspected splog queue: %5s

Disable these notifications: %6s", 'ust'), $blogname, $siteurl, $_SERVER['REMOTE_ADDR'], $spam_url, $ust_url, $options_site_url);
	$msg = apply_filters( 'newblog_notify_siteadmin', $msg );

	wp_mail( $email, sprintf( __( "New Blog Registration: %s" ), $siteurl ), $msg );
	return true;
}

function ust_trim_title($title) {
  $title = strip_tags($title);

  if (strlen($title) > 20)
    return substr($title, 0, 17).'...';
  else
    return $title;
}

//------------------------------------------------------------------------//

//---Output Functions-----------------------------------------------------//

//------------------------------------------------------------------------//

function ust_api_warning() {
  if (!is_site_admin())
    return;

  $ust_settings = get_site_option("ust_settings");
  if (!$ust_settings['api_key'])
    echo "<div id='ust-warning' class='updated fade'><p><strong>".__('Anti-Splog is almost ready.')."</strong> ".sprintf(__('You must <a href="%1$s">enter your WPMU DEV Premium API key</a> for it to work.'), "wpmu-admin.php?page=ust&tab=settings")."</p></div>";
}

function ust_wpsignup_url($echo=true) {
  global $current_site;
  $ust_signup = get_site_option('ust_signup');
  $original_url = "http://" . $current_site->domain . $current_site->path . "wp-signup.php";
  $new_url = "http://" . $current_site->domain . $current_site->path . $ust_signup['slug'] . "/";

  if (!$ust_signup['active']) {
    if ($echo) {
      echo $original_url;
    } else {
      return $original_url;
    }
  } else {
    if ($echo) {
      echo $new_url;
    } else {
      return $new_url;
    }
  }
}

function ust_signup_fields($errors) {
  $ust_settings = get_site_option("ust_settings");

  if($ust_settings['signup_protect'] == 'recaptcha') {

    $recaptcha = get_site_option('ust_recaptcha');
    require_once('anti-splog/recaptchalib.php');

    echo "<script type='text/javascript'>var RecaptchaOptions = { theme : '{$recaptcha['theme']}', lang : '{$recaptcha['lang']}' , tabindex : 30 };</script>";
    echo '<p><label>'.__('Human Verification:', 'ust').'</label>';
    if ( $errmsg = $errors->get_error_message('recaptcha') ) {
  		echo '<p class="error">'.$errmsg.'</p>';
  	}
    echo '<div id="reCAPTCHA">';
    echo rp_recaptcha_get_html($recaptcha['pubkey']);
    echo '</div></p>&nbsp;<br />';

  } else if($ust_settings['signup_protect'] == 'asirra') {

    echo '<p><label>'.__('Human Verification:', 'ust').'</label></p>';
    if ( $errmsg = $errors->get_error_message('asirra') ) {
  		echo '<p class="error">'.$errmsg.'</p>';
  	} else {
      echo '<div id="asirraError"></div>';
    }
    echo '<script type="text/javascript" src="http://challenge.asirra.com/js/AsirraClientSide.js"></script>';
    echo '<script type="text/javascript">
          asirraState.SetEnlargedPosition("right");
          asirraState.SetCellsPerRow(4);
          formElt = document.getElementById("setupform");
          formElt.onsubmit = function() { return MySubmitForm(); };
          </script>';

  } else if ($ust_settings['signup_protect'] == 'questions') {

    $ust_qa = get_site_option("ust_qa");
    if (is_array($ust_qa) && count($ust_qa)) {
      $qkey = rand(0, count($ust_qa)-1);

      //encrypt the answer field name to make it harder for sploggers to guess. Changes every hour & different for every site.
      $salt = get_site_option("ust_salt");
      $datesalt = strtotime(date('Y-m-d H:00:00'));
      $field_name = 'qa_'.md5($qkey.$salt.$datesalt);

      echo '<p><label>'.__('Human Verification:', 'ust').'</label>';
      if ( $errmsg = $errors->get_error_message('qa') ) {
    		echo '<p class="error">'.$errmsg.'</p>';
    	}
      echo stripslashes($ust_qa[$qkey][0]);
      echo '<br /><input type="text" id="qa" name="'.$field_name.'" value="'.htmlentities($_POST[$field_name]).'" />';
      echo '<br /><small>'.__('NOTE: Answers are not case sensitive.', 'ust').'</small>';
      echo '</p>&nbsp;<br />';
    }

  }

}

function ust_signup_fields_bp() {
  $ust_settings = get_site_option("ust_settings");

  if($ust_settings['signup_protect'] == 'recaptcha') {

    $recaptcha = get_site_option('ust_recaptcha');
    require_once('anti-splog/recaptchalib.php');

    echo "<script type='text/javascript'>var RecaptchaOptions = { theme : '{$recaptcha['theme']}', lang : '{$recaptcha['lang']}' , tabindex : 30 };</script>";
    echo '<p><label>'.__('Human Verification:', 'ust').'</label>';
    do_action( 'bp_recaptcha_errors' );
    echo '<div id="reCAPTCHA">';
    echo rp_recaptcha_get_html($recaptcha['pubkey']);
    echo '</div></p>&nbsp;<br />';

  } else if($ust_settings['signup_protect'] == 'asirra') {

    echo '<p><label>'.__('Human Verification:', 'ust').'</label></p>';
    do_action( 'bp_asirra_errors' );
    echo '<div id="asirraError"></div>';
    echo '<script type="text/javascript" src="http://challenge.asirra.com/js/AsirraClientSide.js"></script>';
    echo '<script type="text/javascript">
          asirraState.SetEnlargedPosition("right");
          asirraState.SetCellsPerRow(4);
          formElt = document.getElementById("signup_form");
          formElt.onsubmit = function() { return MySubmitForm(); };
          </script>';

  } else if ($ust_settings['signup_protect'] == 'questions') {

    $ust_qa = get_site_option("ust_qa");
    if (is_array($ust_qa) && count($ust_qa)) {
      $qkey = rand(0, count($ust_qa)-1);

      //encrypt the answer field name to make it harder for sploggers to guess. Changes every hour & different for every site.
      $salt = get_site_option("ust_salt");
      $datesalt = strtotime(date('Y-m-d H:00:00'));
      $field_name = 'qa_'.md5($qkey.$salt.$datesalt);

      echo '<p><label>'.__('Human Verification:', 'ust').'</label>';
      do_action( 'bp_qa_errors' );
      echo stripslashes($ust_qa[$qkey][0]);
      echo '<br /><input type="text" id="qa" name="'.$field_name.'" value="'.htmlentities($_POST[$field_name]).'" />';
      echo '<br /><small>'.__('NOTE: Answers are not case sensitive.', 'ust').'</small>';
      echo '</p>&nbsp;<br />';
    }

  }

}

//Add CSS to signup
function ust_signup_css() {

  $ust_settings = get_site_option("ust_settings");
  if ($ust_settings['signup_protect'] == 'asirra') {
?>
<script type="text/javascript">
var passThroughFormSubmit = false;
function MySubmitForm() {
  if (passThroughFormSubmit) {
    return true;
  }
  Asirra_CheckIfHuman(HumanCheckComplete);

  return false;
}
function HumanCheckComplete(isHuman) {
  if (!isHuman) {
    asirraError = document.getElementById("asirraError");
    asirraError.innerHTML = '<div class="error"><p class="error"><?php _e('Please try to correctly identify the cats again.', 'ust'); ?></p></div>';
    return false;
  } else {
    passThroughFormSubmit = true;
    try {
      formElt = document.getElementById("setupform");
      formElt.submit.click();
    } catch(err) {
      formElt2 = document.getElementById("signup_form");
      formElt2.signup_submit.click();
    }
    return true;
  }
}
</script>
  <?php } ?>
<style type="text/css">
input#qa {
	font-size: 24px;
	width: 50%;
	padding: 3px;
	margin-left:20px;
}
#reCAPTCHA {
	position:relative;
	margin-left:10px;
}
#AsirraDiv {
	position:relative;
	margin-left:10px;
}
small {
	font-weight:normal;
	margin-left:20px;
}
</style>
<?php
}

function ust_admin_scripts_init() {
  global $ust_current_version;

  /* Register our scripts. */
  wp_register_script('anti-splog', WPMU_PLUGIN_URL.'/anti-splog/anti-splog.js', array('jquery'), $ust_current_version );
}

function ust_admin_script() {
  wp_enqueue_script('thickbox');
  wp_enqueue_script('anti-splog');
}

function ust_admin_style() {
  wp_enqueue_style('thickbox');
}

//------------------------------------------------------------------------//

//---Page Output Functions------------------------------------------------//

//------------------------------------------------------------------------//

function ust_admin_output() {
	global $wpdb, $current_user, $current_site;

	if(!is_site_admin()) {
		echo "<p>" . __('Nice Try...', 'ust') . "</p>";  //If accessed properly, this message doesn't appear.
		return;
	}

	//process any actions and messages
	if ( isset($_GET['spam_user']) ) {
	  //spam a user and all blogs they are associated with
		//don't spam site admin
		$user_info = get_userdata((int)$_GET['spam_user']);
		if (!is_site_admin($user_info->user_login)) {
  		$blogs = get_blogs_of_user( (int)$_GET['spam_user'], true );
  		foreach ( (array) $blogs as $key => $details ) {
  			if ( $details->userblog_id == $current_site->blog_id ) { continue; } // main blog not a spam !
  			update_blog_status( $details->userblog_id, "spam", '1', 0 );
  			set_time_limit(60);
  		}
  		update_user_status( (int)$_GET['spam_user'], "spam", '1', 1 );
		  $_GET['updatedmsg'] = sprintf(__('%s blog(s) spammed for user!', 'ust'), count($blogs));
  	}

	} else if ( isset($_GET['spam_ip']) ) {
	  //spam all blogs created or modified with the IP address
	  $spam_ip = addslashes($_GET['spam_ip']);
	  $query = "SELECT b.blog_id
        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.blog_id = r.blog_id
        				AND b.blog_id = u.blog_id
        				AND b.spam = 0
        				AND (r.IP = '$spam_ip' OR u.last_ip = '$spam_ip')";
  	$blogs = $wpdb->get_results( $query, ARRAY_A );
		foreach ( (array) $blogs as $blog ) {
      if ( $blog['blog_id'] == $current_site->blog_id ) { continue; } // main blog not a spam !
			update_blog_status( $blog['blog_id'], "spam", '1', 0 );
			set_time_limit(60);
		}
		$_GET['updatedmsg'] = sprintf(__('%s blog(s) spammed for %s!', 'ust'), count($blogs), $spam_ip);

	} else if ( isset($_GET['ignore_blog']) ) {
	  //ignore a single blog so it doesn't show up on the possible spam list
		ust_blog_ignore((int)$_GET['id']);

	} else if ( isset($_GET['unignore_blog']) ) {
	  //unignore a single blog so it can show up on the possible spam list
		ust_blog_unignore((int)$_GET['id']);

  } else if ( isset($_GET['spam_blog']) ) {
	  //spam a single blog
	  update_blog_status( (int)$_GET['id'], "spam", '1', 1 );

	} else if (isset($_GET['unspam_blog'])) {

    update_blog_status( (int)$_GET['id'], "spam", '0', 1 );
    ust_blog_ignore( (int)$_GET['id'], false );

  } else if ( $_GET['action'] == 'all_notspam' ) {

    $_GET['updatedmsg'] = __('Blogs marked as not spam.', 'ust');

	} else if ($_GET['action'] == 'allblogs') {

		foreach ( (array) $_POST['allblogs'] as $key => $val ) {
			if( $val != '0' && $val != $current_site->blog_id ) {
				if ( isset($_POST['allblog_ignore']) ) {
					$_GET['updatedmsg'] = __('Selected Blogs Ignored.', 'ust');
					ust_blog_ignore($val);
					set_time_limit(60);
        } else if ( isset($_POST['allblog_unignore']) ) {
					$_GET['updatedmsg'] = __('Selected Blogs Un-ignored.', 'ust');
					ust_blog_unignore($val);
					set_time_limit(60);
				} else if ( isset($_POST['allblog_spam']) ) {
				  $_GET['updatedmsg'] = __('Blogs marked as spam.', 'ust');
					update_blog_status( $val, "spam", '1', 0 );
					set_time_limit(60);
				}
			}
		}

  } else if ($_GET['action'] == 'delete') {

    $_GET['updatedmsg'] = __('Blog Deleted!', 'ust');

  }

	if (isset($_GET['updated'])) {
		?><div id="message" class="updated fade"><p><?php echo urldecode($_GET['updatedmsg']); ?></p></div><?php
	}
	?>

  <div class="wrap">
  <h2><?php _e('Anti-Splog', 'ust') ?></h2>
	<ul class="subsubsub">
  <?php
  $tab = ( !empty($_GET['tab']) ) ? $_GET['tab'] : 'queue';

	$tabs = array(
		'splogs'    => __('Recent Splogs', 'ust'),
		'ignored'   => __('Ignored Blogs', 'ust'),
		'settings'  => __('Settings', 'ust'),
		'help'  => __('Help', 'ust')
	);
	$tabhtml = array();

  // If someone wants to remove or add a tab
	$tabs = apply_filters( 'ust_tabs', $tabs );

	$class = ( 'queue' == $tab ) ? ' class="current"' : '';
	$tabhtml[] = '		<li><a href="' . admin_url( 'wpmu-admin.php?page=ust' ) . '"' . $class . '>' . __('Suspected Blogs', 'ust') . '</a>';

	foreach ( $tabs as $stub => $title ) {
		$class = ( $stub == $tab ) ? ' class="current"' : '';
		$tabhtml[] = '		<li><a href="' . admin_url( 'wpmu-admin.php?page=ust&amp;tab=' . $stub ) . '"' . $class . ">$title</a>";
	}

	echo implode( " |</li>\n", $tabhtml ) . '</li>';
  ?>

	</ul>
	<div class="clear"></div>
	<?php
	switch( $tab ) {
		//---------------------------------------------------//
		case "queue":

		  ?><h3><?php _e('Suspected Blogs', 'ust') ?></h3><?php

		  _e('<p>This is the moderation queue for suspicious blogs. When you are sure a blog is spam, mark it so. If it is definately a valid blog you should "ignore" it. It is best to leave blogs in here until you are sure whether they are spam or not spam, as the system learns from both actions.</p>', 'ust');

		  $ust_settings = get_site_option('ust_settings');
      $apage = isset( $_GET['apage'] ) ? intval( $_GET['apage'] ) : 1;
  		$num = isset( $_GET['num'] ) ? intval( $_GET['num'] ) : $ust_settings['paged_blogs'];
  		$page_link = ($apage > 1) ? '&amp;apage='.$apage : '';
  		//get sort
  		if ($_GET['orderby'] == 'lastupdated')
        $order_by = 'b.last_updated DESC';
      else if ($_GET['orderby'] == 'registered')
        $order_by = 'b.registered DESC';
      else
        $order_by = 'u.certainty DESC, b.last_updated DESC';

  		$blogname_columns = ( constant( "VHOST" ) == 'yes' ) ? __('Domain') : __('Path');

  		if (is_array($ust_settings['keywords']) && count($ust_settings['keywords'])) {
        foreach ($ust_settings['keywords'] as $word)
          $keywords[] = "`post_content` LIKE '%".addslashes(trim($word))."%'";

        $keyword_string = implode($keywords, ' OR ');
      }

      //if the Post Indexer plugin is installed and keywords are set
      if (function_exists('post_indexer_post_insert_update') && $keyword_string) {

    		$query = "SELECT *
                  FROM {$wpdb->blogs} b
                    JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                    JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
                    LEFT JOIN (SELECT `blog_id` as bid, COUNT( `site_post_id` ) AS total FROM `{$wpdb->base_prefix}site_posts` WHERE $keyword_string GROUP BY blog_id) as s ON b.blog_id = s.bid
                  WHERE b.site_id = '{$wpdb->siteid}'
                    AND b.spam = '0' AND b.deleted = '0' AND b.archived = '0'
                    AND u.`ignore` = '0' AND b.blog_id != '{$current_site->blog_id}'
                    AND (u.certainty > 0 OR s.total > 0)
                  ORDER BY s.total DESC, u.certainty DESC, b.last_updated DESC";

    		$total = $wpdb->get_var( "SELECT COUNT(b.blog_id)
                          				FROM {$wpdb->blogs} b
                                    JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                                    JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
                                    LEFT JOIN (SELECT `blog_id`, COUNT( `site_post_id` ) AS total FROM `{$wpdb->base_prefix}site_posts` WHERE $keyword_string GROUP BY blog_id) as s ON b.blog_id = s.blog_id
                                  WHERE b.site_id = '{$wpdb->siteid}'
                                    AND b.spam = '0' AND b.deleted = '0' AND b.archived = '0'
                                    AND u.`ignore` = '0' AND b.blog_id != '{$current_site->blog_id}'
                                    AND (u.certainty > 0 OR s.total > 0)");

        $posts_columns = array(
    			'id'           => __('ID', 'ust'),
    			'blogname'     => $blogname_columns,
    			'ips'          => __('IPs', 'ust'),
    			'users'        => __('Blog Users', 'ust'),
    			'keywords'     => __('Keywords', 'ust'),
    			'certainty'    => __('Splog Certainty', 'ust'),
    			'lastupdated'  => __('Last Updated'),
    			'registered'   => __('Registered'),
          'posts'        => __('Recent Posts', 'ust')
    		);

      } else { //no post indexer

        $query = "SELECT *
                  FROM {$wpdb->blogs} b
                    JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                    JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
                  WHERE b.site_id = '{$wpdb->siteid}'
                    AND b.spam = '0' AND b.deleted = '0' AND b.archived = '0'
                    AND u.ignore = '0' AND b.blog_id != '{$current_site->blog_id}'
                    AND u.certainty > 0
                  ORDER BY $order_by";

    		$total = $wpdb->get_var( "SELECT COUNT(b.blog_id)
                          				FROM {$wpdb->blogs} b
                                    JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                                    JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
                                  WHERE b.site_id = '{$wpdb->siteid}'
                                    AND b.spam = '0' AND b.deleted = '0' AND b.archived = '0'
                                    AND u.ignore = '0' AND b.blog_id != '{$current_site->blog_id}'
                                    AND u.certainty > 0");

    		$posts_columns = array(
    			'id'           => __('ID', 'ust'),
    			'blogname'     => $blogname_columns,
    			'ips'          => __('IPs', 'ust'),
    			'users'        => __('Blog Users', 'ust'),
    			'certainty'    => __('Splog Certainty', 'ust'),
    			'lastupdated'  => '<a href="wpmu-admin.php?page=ust'.$page_link.'&orderby=lastupdated">'.__('Last Updated').'</a>',
    			'registered'   => '<a href="wpmu-admin.php?page=ust'.$page_link.'&orderby=registered">'.__('Registered').'</a>',
          'posts'        => __('Recent Posts', 'ust')
    		);
      }

  		$query .= " LIMIT " . intval( ( $apage - 1 ) * $num) . ", " . intval( $num );

  		$blog_list = $wpdb->get_results( $query, ARRAY_A );

  		$blog_navigation = paginate_links( array(
  			'base' => add_query_arg( 'apage', '%#%' ).$url2,
  			'format' => '',
  			'total' => ceil($total / $num),
  			'current' => $apage
  		));
  		if ($_GET['order_by'])
  		  $page_link = $page_link . '&orderby='.urlencode($_GET['orderby']);
  		?>

  		<form id="form-blog-list" action="wpmu-admin.php?page=ust<?php echo $page_link; ?>&amp;action=allblogs&amp;updated=1" method="post">

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Ignore', 'ust') ?>" name="allblog_ignore" class="button-secondary allblog_ignore" />
  				<input type="submit" value="<?php _e('Mark as Spam') ?>" name="allblog_spam" class="button-secondary allblog_spam" />
  				<br class="clear" />
  			</div>
  		</div>

  		<br class="clear" />

  		<table width="100%" cellpadding="3" cellspacing="3" class="widefat">
  			<thead>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</thead>
  			<tbody id="the-list">
  			<?php
  			if ($blog_list) {
  				$bgcolor = $class = '';
  				$preview_id = 0;
  				foreach ($blog_list as $blog) {
  					$class = ('alternate' == $class) ? '' : 'alternate';

  					echo '<tr class="'.$class.' blog-row" id="bid-'.$blog['blog_id'].'">';

  					$blogname = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
  					foreach( $posts_columns as $column_name=>$column_display_name ) {
  						switch($column_name) {
  							case 'id': ?>
  								<th scope="row" class="check-column">
  									<input type='checkbox' id='blog_<?php echo $blog['blog_id'] ?>' name='allblogs[]' value='<?php echo $blog['blog_id'] ?>' />
  								</th>
  								<th scope="row">
  									<?php echo $blog['blog_id']; ?>
  								</th>
  							<?php
  							break;

  							case 'blogname': ?>
  								<td valign="top">
  									<a title="<?php _e('Preview', 'ust'); ?>" href="http://<?php echo $blog['domain'].$blog['path']; ?>?KeepThis=true&TB_iframe=true&height=450&width=900" class="thickbox"><?php echo $blogname; ?></a>
  									<br />
  									<div class="row-actions">
  										<?php echo '<a class="delete ust_ignore" href="wpmu-admin.php?page=ust'.$page_link.'&amp;ignore_blog=1&amp;id=' . $blog['blog_id'] . '&amp;updated=1&amp;updatedmsg=' . urlencode( __('Blog Ignored!', 'ust')).'">' . __('Ignore', 'ust') . '</a>'; ?> |
  										<?php echo '<a class="delete ust_spam" href="wpmu-admin.php?page=ust'.$page_link.'&amp;spam_blog=1&amp;id=' . $blog['blog_id'] . '&amp;updated=1&amp;updatedmsg=' . urlencode( __('Blog marked as spam!', 'ust')).'">' . __('Spam') . '</a>'; ?>
  									</div>
  								</td>
  							<?php
  							break;

                case 'ips':
                  $user_login = $wpdb->get_var("SELECT user_login FROM " . $wpdb->base_prefix . "users WHERE ID = '" . $blog['last_user_id'] . "'");
                ?>
  								<td valign="top">
  									Registered: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['IP'] ?>&blog_ip=1" class="edit"><?php echo $blog['IP']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['IP']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php if ($blog['last_user_id']) : ?>
                    Last User: <a title="<?php _e('Search for User Blogs', 'ust') ?>" href="wpmu-users.php?s=<?php echo $user_login; ?>" class="edit"><?php echo $user_login; ?></a>
                    <small class="row-actions"><a class="ust_spamuser" title="<?php _e('Spam all blogs tied to this User', 'ust') ?>" href="wpmu-admin.php?page=ust<?php echo $page_link; ?>&updated=1&spam_user=<?php echo $blog['last_user_id']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php endif; ?>
                  <?php if ($blog['last_ip']) : ?>
                    Last IP: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['last_ip']; ?>&blog_ip=1" class="edit"><?php echo $blog['last_ip']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['last_ip']; ?>"><?php _e('Spam', 'ust') ?></a></small>
  								<?php endif; ?>
                  </td>
  							<?php
  							break;

  							case 'users': ?>
  								<td valign="top">
  									<?php
  									$blogusers = get_users_of_blog( $blog['blog_id'] );
  									if ( is_array( $blogusers ) ) {
  										$blogusers_warning = '';
  										if ( count( $blogusers ) > 5 ) {
  											$blogusers = array_slice( $blogusers, 0, 5 );
  											$blogusers_warning = __( 'Only showing first 5 users.' ) . ' <a href="http://' . $blog[ 'domain' ] . $blog[ 'path' ] . 'wp-admin/users.php">' . __( 'More' ) . '</a>';
  										}
  										foreach ( $blogusers as $key => $val ) {
  											echo '<a title="Edit User: ' . $val->user_login . ' ('.$val->user_email.')" href="user-edit.php?user_id=' . $val->user_id . '">' . $val->user_login . '</a> ';
                        echo '<small class="row-actions"><a title="' . __('All Blogs of User', 'ust') . '" href="wpmu-users.php?s=' . $val->user_login . '">' . __('Blogs', 'ust') . '</a> | <a class="ust_spamuser" title="' . __('Spam all blogs tied to this User', 'ust') . '" href="wpmu-admin.php?page=ust'.$page_link.'&updated=1&spam_user=' . $val->user_id . '">' . __('Spam', 'ust') . '</a></small><br />';
                      }
  										if( $blogusers_warning != '' ) {
  											echo '<strong>' . $blogusers_warning . '</strong><br />';
  										}
  									}
  									?>
  								</td>
  							<?php
  							break;

                case 'certainty': ?>
  								<td valign="top">
  									<?php echo $blog['certainty']; ?>%
  								</td>
  							<?php
  							break;

  							case 'keywords':  //only called when post indexer is installed ?>
  								<td valign="top">
  									<?php echo ($blog['total']) ? $blog['total'] : 0; ?>
  								</td>
  							<?php
  							break;

  							case 'lastupdated': ?>
  								<td valign="top">
  									<?php echo ( $blog['last_updated'] == '0000-00-00 00:00:00' ) ? __("Never") : mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['last_updated']); ?>
  								</td>
  							<?php
  							break;

  							case 'registered': ?>
  								<td valign="top">
  									<?php echo mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['registered']); ?>
  								</td>
  							<?php
  							break;

  							case 'posts':
  							  $query = "SELECT ID, post_title, post_excerpt, post_content, post_author, post_date FROM `{$wpdb->base_prefix}{$blog['blog_id']}_posts` WHERE post_status = 'publish' AND post_type = 'post' AND ID != '1' ORDER BY post_date DESC LIMIT {$ust_settings['paged_posts']}";
                  $posts = $wpdb->get_results( $query, ARRAY_A );
                ?>
  								<td valign="top">
  									<?php
  									if (is_array($posts) && count($posts)) {
                      foreach ($posts as $post) {
                        $post_preview[$preview_id] = $post['post_content'];
                        $link = '#TB_inline?height=440&width=600&inlineId=post_preview_'.$preview_id;
                        if (empty($post['post_title']))
                          $title = __('No Title', 'ust');
                        else
                          $title = htmlentities($post['post_title']);
                        echo '<a title="'.mysql2date(__('Y-m-d g:i:sa - ', 'ust'), $post['post_date']).$title.'" href="'.$link.'" class="thickbox">'.ust_trim_title($title).'</a><br />';
                        $preview_id++;
                      }
                    } else {
                      _e('No Posts', 'ust');
                    }
                    ?>
  								</td>
  							<?php
  							break;

  						}
  					}
  					?>
  					</tr>
  					<?php
  				}

  			} else { ?>
  				<tr style='background-color: <?php echo $bgcolor; ?>'>
  					<td colspan="8"><?php _e('No blogs found.') ?></td>
  				</tr>
  			<?php
  			} // end if ($blogs)
  			?>

  			</tbody>
  			<tfoot>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</tfoot>
  		</table>

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Ignore', 'ust') ?>" name="allblog_ignore" class="button-secondary allblog_ignore" />
  				<input type="submit" value="<?php _e('Mark as Spam') ?>" name="allblog_spam" class="button-secondary allblog_spam" />
  				<br class="clear" />
  			</div>
  		</div>

  		</form>
      <?php
		  //print hidden post previews
		  if (is_array($post_preview) && count($post_preview)) {
		    echo '<div id="post_previews" style="display:none;">';
        foreach ($post_preview as $id => $content) {
          if ($ust_settings['strip'])
            $content = strip_tags($content, '<a><strong><em><ul><ol><li>');
          echo '<div id="post_preview_'.$id.'">'.wpautop(strip_shortcodes($content))."</div>\n";
        }
        echo '</div>';
      }

		break;


		//---------------------------------------------------//
		case "splogs":

      ?><h3><?php _e('Recent Splogs', 'ust') ?></h3><?php
      
      _e('<p>These are all the blogs that have been marked as spam in order of when they were spammed. You can instantly preview any of these splogs or their last posts, and unspam them if there has been a mistake.</p>', 'ust');

      $ust_settings = get_site_option('ust_settings');
      $apage = isset( $_GET['apage'] ) ? intval( $_GET['apage'] ) : 1;
  		$num = isset( $_GET['num'] ) ? intval( $_GET['num'] ) : $ust_settings['paged_blogs'];
      $review = ($_GET['bid']) ? "AND b.blog_id = '".(int)$_GET['bid']."'" : '';

  		$query = "SELECT *
        				FROM {$wpdb->blogs} b
                JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.spam = 1 $review
                ORDER BY u.spammed DESC";

  		$total = $wpdb->get_var( "SELECT COUNT(b.blog_id)
                        				FROM {$wpdb->blogs} b
                                JOIN {$wpdb->registration_log} r ON b.blog_id = r.blog_id
                                JOIN {$wpdb->base_prefix}ust u ON b.blog_id = u.blog_id
                        				WHERE b.site_id = '{$wpdb->siteid}'
                        				AND b.spam = 1 $review" );

  		$query .= " LIMIT " . intval( ( $apage - 1 ) * $num) . ", " . intval( $num );

  		$blog_list = $wpdb->get_results( $query, ARRAY_A );

  		$blog_navigation = paginate_links( array(
  			'base' => add_query_arg( 'apage', '%#%' ).$url2,
  			'format' => '',
  			'total' => ceil($total / $num),
  			'current' => $apage
  		));
  		$page_link = ($apage > 1) ? '&amp;apage='.$apage : '';
  		?>

  		<form id="form-blog-list" action="wpmu-edit.php?action=allblogs&amp;updatedmsg=Settings+Saved" method="post">

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Delete') ?>" name="allblog_delete" class="button-secondary delete" />
  				<input type="submit" value="<?php _e('Not Spam') ?>" name="allblog_notspam" class="button-secondary allblog_notspam" />
  				<?php wp_nonce_field( 'allblogs' ); ?>
  				<br class="clear" />
  			</div>
  		</div>

  		<br class="clear" />


  		<?php
  		// define the columns to display, the syntax is 'internal name' => 'display name'
  		$blogname_columns = ( constant( "VHOST" ) == 'yes' ) ? __('Domain') : __('Path');
  		$posts_columns = array(
  			'id'           => __('ID'),
  			'blogname'     => $blogname_columns,
  			'ips'          => __('IPs', 'ust'),
  			'users'        => __('Blog Users', 'ust'),
  			'certainty'    => __('Splog Certainty', 'ust'),
  			'method'       => __('Method'),
        'spammed'      => __('Spammed', 'ust'),
  			'registered'   => __('Registered'),
        'posts'        => __('Last Posts', 'ust')
  		);

  		?>

  		<table width="100%" cellpadding="3" cellspacing="3" class="widefat">
  			<thead>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</thead>
  			<tbody id="the-list">
  			<?php
  			if ($blog_list) {
  				$bgcolor = $class = '';
  				foreach ($blog_list as $blog) {
  					$class = ('alternate' == $class) ? '' : 'alternate';

  					echo '<tr class="'.$class.' blog-row" id="bid-'.$blog['blog_id'].'">';

  					$blogname = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
  					foreach( $posts_columns as $column_name=>$column_display_name ) {
  						switch($column_name) {
  							case 'id': ?>
  								<th scope="row" class="check-column">
  									<input type='checkbox' id='blog_<?php echo $blog['blog_id'] ?>' name='allblogs[]' value='<?php echo $blog['blog_id'] ?>' />
  								</th>
  								<th scope="row">
  									<?php echo $blog['blog_id'] ?>
  								</th>
  							<?php
  							break;

  							case 'blogname': ?>
  								<td valign="top">
  									<a title="<?php _e('Preview', 'ust'); ?>" href="http://<?php echo $blog['domain'].$blog['path']; ?>?KeepThis=true&TB_iframe=true&height=450&width=900" class="thickbox"><?php echo $blogname; ?></a>
  									<br />
  									<?php
  									$controlActions	= array();
  									$controlActions[]	= '<a class="delete ust_unspam" href="wpmu-admin.php?page=ust&amp;tab=splogs'.$page_link.'&amp;unspam_blog=1&amp;id=' . $blog['blog_id'] . '&amp;updated=1&amp;updatedmsg=' . urlencode( __('Blog marked as not spam!', 'ust')).'">' . __('Not Spam') . '</a>';
  									$controlActions[]	= '<a class="delete" href="wpmu-edit.php?action=confirm&amp;action2=deleteblog&amp;id=' . $blog['blog_id'] . '&amp;msg=' . urlencode( sprintf( __( "You are about to delete the blog %s" ), $blogname ) ) . '&amp;updatedmsg=' . urlencode( __('Blog Deleted!', 'ust')).'">' . __("Delete") . '</a>';
  									?>

  									<?php if (count($controlActions)) : ?>
  									<div class="row-actions">
  										<?php echo implode(' | ', $controlActions); ?>
  									</div>
  									<?php endif; ?>
  								</td>
  							<?php
  							break;

                case 'ips':
                  $user_login = $wpdb->get_var("SELECT user_login FROM " . $wpdb->base_prefix . "users WHERE ID = '" . $blog['last_user_id'] . "'");
                ?>
  								<td valign="top">
  									Registered: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['IP'] ?>&blog_ip=1" class="edit"><?php echo $blog['IP']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=splogs<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['IP']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php if ($blog['last_user_id']) : ?>
                    Last User: <a title="<?php _e('Search for User Blogs', 'ust') ?>" href="wpmu-users.php?s=<?php echo $user_login; ?>" class="edit"><?php echo $user_login; ?></a>
                    <small class="row-actions"><a class="ust_spamuser" title="<?php _e('Spam all blogs tied to this User', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=splogs<?php echo $page_link; ?>&updated=1&spam_user=<?php echo $blog['last_user_id']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php endif; ?>
                  <?php if ($blog['last_ip']) : ?>
                    Last IP: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['last_ip']; ?>&blog_ip=1" class="edit"><?php echo $blog['last_ip']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=splogs<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['last_ip']; ?>"><?php _e('Spam', 'ust') ?></a></small>
  								<?php endif; ?>
                  </td>
  							<?php
  							break;

  							case 'users': ?>
  								<td valign="top">
  									<?php
  									$blogusers = get_users_of_blog( $blog['blog_id'] );
  									if ( is_array( $blogusers ) ) {
  										$blogusers_warning = '';
  										if ( count( $blogusers ) > 5 ) {
  											$blogusers = array_slice( $blogusers, 0, 5 );
  											$blogusers_warning = __( 'Only showing first 5 users.' ) . ' <a href="http://' . $blog[ 'domain' ] . $blog[ 'path' ] . 'wp-admin/users.php">' . __( 'More' ) . '</a>';
  										}
  										foreach ( $blogusers as $key => $val ) {
  											echo '<a title="Edit User: ' . $val->user_login . ' ('.$val->user_email.')" href="user-edit.php?user_id=' . $val->user_id . '">' . $val->user_login . '</a> ';
                        echo '<small class="row-actions"><a title="' . __('All Blogs of User', 'ust') . '" href="wpmu-users.php?s=' . $val->user_login . '">' . __('Blogs', 'ust') . '</a> | <a class="ust_spamuser" title="' . __('Spam all blogs tied to this User', 'ust') . '" href="wpmu-admin.php?page=ust&tab=splogs'.$page_link.'&updated=1&spam_user=' . $val->user_id . '">' . __('Spam', 'ust') . '</a></small><br />';
                      }
  										if( $blogusers_warning != '' ) {
  											echo '<strong>' . $blogusers_warning . '</strong><br />';
  										}
  									}
  									?>
  								</td>
  							<?php
  							break;

                case 'certainty': ?>
  								<td valign="top">
  									<?php echo $blog['certainty']; ?>%
  								</td>
  							<?php
  							break;

                case 'method': ?>
  								<td valign="top">
  									<?php
                      if (get_blog_option($blog['blog_id'], 'ust_auto_spammed'))
                        _e('Auto: Signup', 'ust');
                      else if (get_blog_option($blog['blog_id'], 'ust_post_auto_spammed'))
                        _e('Auto: Post', 'ust');
                      else
                        _e('Manual', 'ust');
                    ?>
  								</td>
  							<?php
  							break;

                case 'spammed': ?>
  								<td valign="top">
  									<?php echo ( $blog['spammed'] == '0000-00-00 00:00:00' ) ? __("Never") : mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['spammed']); ?>
  								</td>
  							<?php
  							break;

  							case 'registered': ?>
  								<td valign="top">
  									<?php echo mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['registered']); ?>
  								</td>
  							<?php
  							break;

                case 'posts':
  							  $query = "SELECT ID, post_title, post_excerpt, post_content, post_author, post_date FROM `{$wpdb->base_prefix}{$blog['blog_id']}_posts` WHERE post_status = 'publish' AND post_type = 'post' AND ID != '1' ORDER BY post_date DESC LIMIT {$ust_settings['paged_posts']}";
                  $posts = $wpdb->get_results( $query, ARRAY_A );
                ?>
  								<td valign="top">
  									<?php
  									if (is_array($posts) && count($posts)) {
                      foreach ($posts as $post) {
                        $post_preview[$preview_id] = $post['post_content'];
                        $link = '#TB_inline?height=440&width=600&inlineId=post_preview_'.$preview_id;
                        if (empty($post['post_title']))
                          $title = __('No Title', 'ust');
                        else
                          $title = htmlentities($post['post_title']);
                        echo '<a title="'.mysql2date(__('Y-m-d g:i:sa - ', 'ust'), $post['post_date']).$title.'" href="'.$link.'" class="thickbox">'.ust_trim_title($title).'</a><br />';
                        $preview_id++;
                      }
                    } else {
                      _e('No Posts', 'ust');
                    }
                    ?>
  								</td>
  							<?php
  							break;

  						}
  					}
  					?>
  					</tr>
  					<?php
  				}
  			} else { ?>
  				<tr style='background-color: <?php echo $bgcolor; ?>'>
  					<td colspan="8"><?php _e('No blogs found.') ?></td>
  				</tr>
  			<?php
  			} // end if ($blogs)
  			?>

  			</tbody>
  			<tfoot>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</tfoot>
  		</table>

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Delete') ?>" name="allblog_delete" class="button-secondary delete" />
  				<input type="submit" value="<?php _e('Not Spam') ?>" name="allblog_notspam" class="button-secondary allblog_notspam" />
  				<br class="clear" />
  			</div>
  		</div>

  		</form>
      <?php
      //print hidden post previews
		  if (is_array($post_preview) && count($post_preview)) {
		    echo '<div id="post_previews" style="display:none;">';
        foreach ($post_preview as $id => $content) {
          if ($ust_settings['strip'])
            $content = strip_tags($content, '<a><strong><em><ul><ol><li>');
          echo '<div id="post_preview_'.$id.'">'.wpautop(strip_shortcodes($content))."</div>\n";
        }
        echo '</div>';
      }

		break;


		//---------------------------------------------------//
		case "ignored":

      ?><h3><?php _e('Ignored Blogs', 'ust') ?></h3><?php
      
      _e('<p>These are suspicious blogs that you have decided are valid. If you have made a mistake you can send them back to the Suspected Blogs queue or spam them.</p>', 'ust');

      $ust_settings = get_site_option('ust_settings');
      $apage = isset( $_GET['apage'] ) ? intval( $_GET['apage'] ) : 1;
  		$num = isset( $_GET['num'] ) ? intval( $_GET['num'] ) : $ust_settings['paged_blogs'];

  		$query = "SELECT *
        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
        				WHERE b.site_id = '{$wpdb->siteid}'
        				AND b.blog_id = r.blog_id
        				AND b.blog_id = u.blog_id
        				AND b.spam = 0 AND u.`ignore` = 1
                ORDER BY u.spammed DESC";

  		$total = $wpdb->get_var( "SELECT COUNT(b.blog_id)
                        				FROM {$wpdb->blogs} b, {$wpdb->registration_log} r, {$wpdb->base_prefix}ust u
                        				WHERE b.site_id = '{$wpdb->siteid}'
                        				AND b.blog_id = r.blog_id
                        				AND b.blog_id = u.blog_id
                        				AND b.spam = 0 AND u.`ignore` = 1" );

  		$query .= " LIMIT " . intval( ( $apage - 1 ) * $num) . ", " . intval( $num );

  		$blog_list = $wpdb->get_results( $query, ARRAY_A );

  		$blog_navigation = paginate_links( array(
  			'base' => add_query_arg( 'apage', '%#%' ).$url2,
  			'format' => '',
  			'total' => ceil($total / $num),
  			'current' => $apage
  		));
  		$page_link = ($apage > 1) ? '&amp;apage='.$apage : '';
  		?>

  		<form id="form-blog-list" action="wpmu-admin.php?page=ust&amp;tab=ignored<?php echo $page_link; ?>&amp;action=allblogs&amp;updated=1" method="post">

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Un-ignore', 'ust') ?>" name="allblog_unignore" class="button-secondary allblog_unignore" />
  				<input type="submit" value="<?php _e('Mark as Spam') ?>" name="allblog_spam" class="button-secondary allblog_spam" />
  				<br class="clear" />
  			</div>
  		</div>

  		<br class="clear" />

      <?php
  		// define the columns to display, the syntax is 'internal name' => 'display name'
  		$blogname_columns = ( constant( "VHOST" ) == 'yes' ) ? __('Domain') : __('Path');
  		$posts_columns = array(
  			'id'           => __('ID'),
  			'blogname'     => $blogname_columns,
  			'ips'          => __('IPs', 'ust'),
  			'users'        => __('Blog Users', 'ust'),
  			'certainty'    => __('Splog Certainty', 'ust'),
  			'lastupdated'  => __('Last Updated'),
  			'registered'   => __('Registered'),
        'posts'        => __('Recent Posts', 'ust')
  		);

  		?>

  		<table width="100%" cellpadding="3" cellspacing="3" class="widefat">
  			<thead>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</thead>
  			<tbody id="the-list">
  			<?php
  			if ($blog_list) {
  				$bgcolor = $class = '';
  				$preview_id = 0;
  				foreach ($blog_list as $blog) {
  					$class = ('alternate' == $class) ? '' : 'alternate';

  					echo '<tr class="'.$class.' blog-row" id="bid-'.$blog['blog_id'].'">';

  					$blogname = ( constant( "VHOST" ) == 'yes' ) ? str_replace('.'.$current_site->domain, '', $blog['domain']) : $blog['path'];
  					foreach( $posts_columns as $column_name=>$column_display_name ) {
  						switch($column_name) {
  							case 'id': ?>
  								<th scope="row" class="check-column">
  									<input type='checkbox' id='blog_<?php echo $blog['blog_id'] ?>' name='allblogs[]' value='<?php echo $blog['blog_id'] ?>' />
  								</th>
  								<th scope="row">
  									<?php echo $blog['blog_id']; ?>
  								</th>
  							<?php
  							break;

  							case 'blogname': ?>
  								<td valign="top">
  									<a title="<?php _e('Preview', 'ust'); ?>" href="http://<?php echo $blog['domain'].$blog['path']; ?>?KeepThis=true&TB_iframe=true&height=450&width=900" class="thickbox"><?php echo $blogname; ?></a>
  									<br />
  									<div class="row-actions">
  										<?php echo '<a class="delete ust_unignore" href="wpmu-admin.php?page=ust&amp;tab=ignored'.$page_link.'&amp;unignore_blog=1&amp;id=' . $blog['blog_id'] . '&amp;updated=1&amp;updatedmsg=' . urlencode( __('Blog Un-ignored!', 'ust')).'">' . __('Un-ignore', 'ust') . '</a>'; ?> |
  										<?php echo '<a class="delete ust_spam" href="wpmu-admin.php?page=ust&amp;tab=ignored'.$page_link.'&amp;spam_blog=1&amp;id=' . $blog['blog_id'] . '&amp;updated=1&amp;updatedmsg=' . urlencode( __('Blog marked as spam!', 'ust')).'">' . __('Spam') . '</a>'; ?>
  									</div>
  								</td>
  							<?php
  							break;

                case 'ips':
                  $user_login = $wpdb->get_var("SELECT user_login FROM " . $wpdb->base_prefix . "users WHERE ID = '" . $blog['last_user_id'] . "'");
                ?>
  								<td valign="top">
  									Registered: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['IP'] ?>&blog_ip=1" class="edit"><?php echo $blog['IP']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=ignored<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['IP']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php if ($blog['last_user_id']) : ?>
                    Last User: <a title="<?php _e('Search for User Blogs', 'ust') ?>" href="wpmu-users.php?s=<?php echo $user_login; ?>" class="edit"><?php echo $user_login; ?></a>
                    <small class="row-actions"><a class="ust_spamuser" title="<?php _e('Spam all blogs tied to this User', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=ignored<?php echo $page_link; ?>&updated=1&spam_user=<?php echo $blog['last_user_id']; ?>"><?php _e('Spam', 'ust') ?></a></small><br />
                  <?php endif; ?>
                  <?php if ($blog['last_ip']) : ?>
                    Last IP: <a title="<?php _e('Search for IP', 'ust') ?>" href="wpmu-blogs.php?action=blogs&amp;s=<?php echo $blog['last_ip']; ?>&blog_ip=1" class="edit"><?php echo $blog['last_ip']; ?></a>
                    <small class="row-actions"><a class="ust_spamip" title="<?php _e('Spam all blogs tied to this IP', 'ust') ?>" href="wpmu-admin.php?page=ust&tab=ignored<?php echo $page_link; ?>&updated=1&id=<?php echo $blog['blog_id']; ?>&spam_ip=<?php echo $blog['last_ip']; ?>"><?php _e('Spam', 'ust') ?></a></small>
  								<?php endif; ?>
                  </td>
  							<?php
  							break;

  							case 'users': ?>
  								<td valign="top">
  									<?php
  									$blogusers = get_users_of_blog( $blog['blog_id'] );
  									if ( is_array( $blogusers ) ) {
  										$blogusers_warning = '';
  										if ( count( $blogusers ) > 5 ) {
  											$blogusers = array_slice( $blogusers, 0, 5 );
  											$blogusers_warning = __( 'Only showing first 5 users.' ) . ' <a href="http://' . $blog[ 'domain' ] . $blog[ 'path' ] . 'wp-admin/users.php">' . __( 'More' ) . '</a>';
  										}
  										foreach ( $blogusers as $key => $val ) {
  											echo '<a title="Edit User: ' . $val->user_login . ' ('.$val->user_email.')" href="user-edit.php?user_id=' . $val->user_id . '">' . $val->user_login . '</a> ';
                        echo '<small class="row-actions"><a title="' . __('All Blogs of User', 'ust') . '" href="wpmu-users.php?s=' . $val->user_login . '">' . __('Blogs', 'ust') . '</a> | <a class="ust_spamuser" title="' . __('Spam all blogs tied to this User', 'ust') . '" href="wpmu-admin.php?page=ust&tab=splogs'.$page_link.'&updated=1&spam_user=' . $val->user_id . '">' . __('Spam', 'ust') . '</a></small><br />';
                      }
  										if( $blogusers_warning != '' ) {
  											echo '<strong>' . $blogusers_warning . '</strong><br />';
  										}
  									}
  									?>
  								</td>
  							<?php
  							break;

                case 'certainty': ?>
  								<td valign="top">
  									<?php echo $blog['certainty']; ?>%
  								</td>
  							<?php
  							break;

  							case 'lastupdated': ?>
  								<td valign="top">
  									<?php echo ( $blog['last_updated'] == '0000-00-00 00:00:00' ) ? __("Never") : mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['last_updated']); ?>
  								</td>
  							<?php
  							break;

  							case 'registered': ?>
  								<td valign="top">
  									<?php echo mysql2date(__('Y-m-d \<\b\r \/\> g:i:s a'), $blog['registered']); ?>
  								</td>
  							<?php
  							break;

  							case 'posts':
  							  $query = "SELECT ID, post_title, post_excerpt, post_content, post_author, post_date FROM `{$wpdb->base_prefix}{$blog['blog_id']}_posts` WHERE post_status = 'publish' AND post_type = 'post' AND ID != '1' ORDER BY post_date DESC LIMIT {$ust_settings['paged_posts']}";
                  $posts = $wpdb->get_results( $query, ARRAY_A );
                ?>
  								<td valign="top">
  									<?php
  									if (is_array($posts) && count($posts)) {
                      foreach ($posts as $post) {
                        $post_preview[$preview_id] = $post['post_content'];
                        $link = '#TB_inline?height=440&width=600&inlineId=post_preview_'.$preview_id;
                        if (empty($post['post_title']))
                          $title = __('No Title', 'ust');
                        else
                          $title = htmlentities($post['post_title']);
                        echo '<a title="'.mysql2date(__('Y-m-d g:i:sa - ', 'ust'), $post['post_date']).$title.'" href="'.$link.'" class="thickbox">'.ust_trim_title($title).'</a><br />';
                        $preview_id++;
                      }
                    } else {
                      _e('No Posts', 'ust');
                    }
                    ?>
  								</td>
  							<?php
  							break;

  						}
  					}
  					?>
  					</tr>
  					<?php
  				}

  			} else { ?>
  				<tr style='background-color: <?php echo $bgcolor; ?>'>
  					<td colspan="8"><?php _e('No blogs found.') ?></td>
  				</tr>
  			<?php
  			} // end if ($blogs)
  			?>

  			</tbody>
  			<tfoot>
  				<tr>
  				<th scope="col" class="check-column"><input type="checkbox" /></th>
  				<?php foreach($posts_columns as $column_id => $column_display_name) {
  					$col_url = $column_display_name;
  					?>
  					<th scope="col"><?php echo $col_url ?></th>
  				<?php } ?>
  				</tr>
  			</tfoot>
  		</table>

  		<div class="tablenav">
  			<?php if ( $blog_navigation ) echo "<div class='tablenav-pages'>$blog_navigation</div>"; ?>

  			<div class="alignleft">
  				<input type="submit" value="<?php _e('Un-ignore', 'ust') ?>" name="allblog_unignore" class="button-secondary allblog_unignore" />
  				<input type="submit" value="<?php _e('Mark as Spam') ?>" name="allblog_spam" class="button-secondary allblog_spam" />
  				<br class="clear" />
  			</div>
  		</div>

  		</form>
      <?php
      //print hidden post previews
		  if (is_array($post_preview) && count($post_preview)) {
		    echo '<div id="post_previews" style="display:none;">';
        foreach ($post_preview as $id => $content) {
          if ($ust_settings['strip'])
            $content = strip_tags($content, '<a><strong><em><ul><ol><li>');
          echo '<div id="post_preview_'.$id.'">'.wpautop(strip_shortcodes($content))."</div>\n";
        }
        echo '</div>';
      }

		break;


		//---------------------------------------------------//
		case "settings":

		  $domain = $current_site->domain;
      $ip = $_SERVER['SERVER_ADDR'];
		  $register_url = "http://premium.wpmudev.org/wp-admin/profile.php?page=ustapi&amp;ip=$ip&amp;domain=$domain";

		  function ust_trim_array($input) {
        if (!is_array($input))
          return trim($input);
        return array_map('ust_trim_array', $input);
      }

		  //process form
		  if (isset($_POST['ust_settings'])) {

		    //check the api key and connection
		    $request["API_KEY"] = $_POST['ust']['api_key'];
		    $api_response = ust_http_post('api_check', $request);
		    if ($api_response && $api_response != 'Valid') {
		      $_POST['ust']['api_key'] = '';
		      echo '<div id="message" class="error"><p>'.__(sprintf('There was a problem with the API key you entered: "%s" <a href="%s" target="_blank">Fix it here&raquo;</a>', $api_response, $register_url), 'ust').'</p></div>';
		    } else if (!$api_response) {
		      $_POST['ust']['api_key'] = '';
		      echo '<div id="message" class="error"><p>'.__('There was a problem connecting to the API server. Please try again later.', 'ust').'</p></div>';
        }
        if (trim($_POST['ust']['keywords']))
		      $_POST['ust']['keywords'] = explode("\n", trim($_POST['ust']['keywords']));
		    else
		      $_POST['ust']['keywords'] = '';
   		  update_site_option("ust_settings", $_POST['ust']);

   		  $ust_signup['active'] = ($_POST['ust_signup']) ? 1 : 0;
        $ust_signup['expire'] = time() + 86400; //extend 24 hours
        $ust_signup['slug'] = 'signup-'.substr(md5(time()), rand(0,30), 3); //create new random signup url
        update_site_option('ust_signup', $ust_signup);

        update_site_option("ust_recaptcha", ust_trim_array($_POST['recaptcha']));

        //process user questions
        $qa['questions'] = explode("\n", trim($_POST['ust_qa']['questions']));
        $qa['answers'] = explode("\n", trim($_POST['ust_qa']['answers']));
        $i = 0;
        foreach ($qa['questions'] as $question) {
          if (trim($qa['answers'][$i]))
            $ust_qa[] = array(trim($question), trim($qa['answers'][$i]));
          $i++;
        }
        update_site_option("ust_qa", $ust_qa);

  			do_action('ust_settings_process');

  			echo '<div id="message" class="updated fade"><p>'.__('Settings Saved!', 'ust').'</p></div>';
  		}

  		$ust_settings = get_site_option("ust_settings");
  		$ust_signup = get_site_option('ust_signup');
  		$ust_recaptcha = get_site_option("ust_recaptcha");
  		$ust_qa = get_site_option("ust_qa");
  		if (!$ust_qa)
  		  $ust_qa = array(array('What is the answer to "Ten times Two" in word form?','Twenty'), array('What is the last name of the current US president?','Obama'));

  		if (is_array($ust_qa) && count($ust_qa)) {
    		foreach ($ust_qa as $pair) {
          $questions[] = $pair[0];
          $answers[] = $pair[1];
        }
  		}

  		//create salt if not set
  		if (!get_site_option("ust_salt"))
  		  update_site_option("ust_salt", substr(md5(time()), rand(0,15), 10));

  		if (!$ust_settings['api_key'])
  		  $style = ' style="background-color:#FF7C7C;"';
  		else
  		  $style = ' style="background-color:#ADFFAA;"';


			?>
          <form method="post" action="wpmu-admin.php?page=ust&tab=settings">
          <input type="hidden" name="ust_settings" value="1" />
          <h3><?php _e('Settings', 'ust') ?></h3>
          <p><?php _e("You must enter an API key and register the IP (<strong>$ip</strong>) and WPMU Site Domain (<strong>$domain</strong>) of this server to enable live splog checking. <a href='$register_url' target='_blank'>Get your API key and register your server here.</a> You must be a current WPMU DEV Premium subscriber to access our API.", 'ust') ?></p>
          <table class="form-table">
              <tr valign="top">
              <th scope="row"><?php _e('API Key', 'ust') ?>*</th>
              <td><input type="text" name="ust[api_key]"<?php echo $style; ?> value="<?php echo stripslashes($ust_settings['api_key']); ?>" /><input type="submit" name="check_key" value="<?php _e('Check Key &raquo;', 'ust') ?>" /></td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Blog Signup Splog Certainty', 'ust') ?></th>
              <td><select name="ust[certainty]">
            	<?php
            		for ( $counter = 10; $counter <= 100; $counter += 5 ) {
                  echo '<option value="' . $counter . '"' . ($ust_settings['certainty']==$counter ? ' selected="selected"' : '') . '>' . $counter . '%</option>' . "\n";
            		}
            		echo '<option value="999"' . ($ust_settings['certainty']==999 ? ' selected="selected"' : '') . '>' . __("Don't Spam", 'ust') . '</option>' . "\n";
              ?>
              </select>
              <br /><em><?php _e('Blog signups that return a certainty number greater than or equal to this will automatically be marked as spam.', 'ust'); ?></em></td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Posting Splog Certainty', 'ust') ?></th>
              <td><select name="ust[post_certainty]">
            	<?php
            		for ( $counter = 50; $counter <= 100; $counter += 2 ) {
                  echo '<option value="' . $counter . '"' . ($ust_settings['post_certainty']==$counter ? ' selected="selected"' : '') . '>' . $counter . '%</option>' . "\n";
            		}
            		echo '<option value="999"' . ($ust_settings['post_certainty']==999 ? ' selected="selected"' : '') . '>' . __("Don't Spam", 'ust') . '</option>' . "\n";
              ?>
              </select>
              <br /><em><?php _e('If a post from a new blog is checked by the API and returns a certainty number greater than or equal to this, it will automatically be marked as spam.', 'ust'); ?></em></td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Limit Blog Signups Per Day', 'ust') ?></th>
              <td><select name="ust[num_signups]">
            	<?php
            		for ( $counter = 1; $counter <= 250; $counter += 1 ) {
                  echo '<option value="' . $counter . '"' . ($ust_settings['num_signups']==$counter ? ' selected="selected"' : '') . '>' . $counter . '</option>' . "\n";
            		}
                echo '<option value=""' . ($ust_settings['num_signups']=='' ? ' selected="selected"' : '') . '>' . __('Unlimited', 'ust') . '</option>' . "\n";
              ?>
              </select>
              <br /><em><?php _e('Splog bots and users often register a large number of blogs in a short amount of time. This setting will limit the number of blog signups per 24 hours per IP, which can drastically reduce the splogs you have to deal with if they get past other filters (human sploggers). Remember that an IP is not necessarily tied to a single user. For example employees behind a company firewall may share a single IP.', 'ust'); ?></em></td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Rename wp-signup.php', 'ust') ?>
              <br /><em><small><?php _e('(Not Buddypress compatible)', 'ust') ?></small></em>
              </th>
              <td>
              <label for="ust_signup"><input type="checkbox" name="ust_signup" id="ust_signup"<?php echo ($ust_signup['active']) ? ' checked="checked"' : ''; ?> /> <?php _e('Move wp-signup.php', 'ust') ?></label>
              <br /><?php _e('Current Signup URL:', 'ust') ?> <strong><a target="_blank" href="<?php ust_wpsignup_url(); ?>"><?php ust_wpsignup_url(); ?></a></strong>
              <br /><em><?php _e("Checking this option will disable the wp-signup.php form and change the signup url automatically every 24 hours. It will look something like <strong>http://$domain/signup-XXX/</strong>. To use this you may need to make some slight edits to your main theme's template files. Replace any hardcoded links to wp-signup.php with this function: <strong>&lt;?php ust_wpsignup_url(); ?&gt;</strong> Within post or page content you can insert the <strong>[ust_wpsignup_url]</strong> shortcode, usually in the href of a link. See the install.txt file for more detailed documentation on this function.", 'ust'); ?></em></td>
              </td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Queue Display Preferences', 'ust') ?></th>
              <td>
              <?php _e('Strip Images From Post Previews:', 'ust') ?>
              <select name="ust[strip]">
            	<?php
                echo '<option value="1"' . ($ust_settings['strip']==1 ? ' selected="selected"' : '') . '>' . __('Yes', 'ust') . '</option>' . "\n";
                echo '<option value="0"' . ($ust_settings['strip']==0 ? ' selected="selected"' : '') . '>' . __('No', 'ust') . '</option>' . "\n";
              ?>
              </select><br />
              <?php _e('Blogs Per Page:', 'ust') ?>
              <select name="ust[paged_blogs]">
            	<?php
            		for ( $counter = 5; $counter <= 100; $counter += 5 ) {
                  echo '<option value="' . $counter . '"' . ($ust_settings['paged_blogs']==$counter ? ' selected="selected"' : '') . '>' . $counter . '</option>' . "\n";
            		}
              ?>
              </select><br />
              <?php _e('Post Previews Per Blog:', 'ust') ?>
              <select name="ust[paged_posts]">
            	<?php
            		for ( $counter = 1; $counter <= 20; $counter += 1 ) {
                  echo '<option value="' . $counter . '"' . ($ust_settings['paged_posts']==$counter ? ' selected="selected"' : '') . '>' . $counter . '</option>' . "\n";
            		}
              ?>
              </select>
              </td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Spam Keyword Search', 'ust') ?></th>
              <td>
              <em><?php _e('Enter one word or phrase per line. Keywords are not case sensitive and may match any part of a word. Example: "Ugg" would match "s<strong>ugg</strong>estion".', 'ust'); ?></em><br />
              <?php if (!function_exists('post_indexer_post_insert_update')) { ?>
              <p class="error"><?php _e('You must install the <a target="_blank" href="http://premium.wpmudev.org/project/post-indexer">Post Indexer</a> plugin to enable keyword flagging.', 'ust'); ?></p>
              <textarea name="ust[keywords]" style="width:200px" rows="4" disabled="disabled"><?php echo stripslashes(implode("\n", (array)$ust_settings['keywords'])); ?></textarea>
              <?php } else { ?>
              <textarea name="ust[keywords]" style="width:200px" rows="4"><?php echo stripslashes(implode("\n", (array)$ust_settings['keywords'])); ?></textarea>
              <?php } ?>
              <br /><strong><em><?php _e('This feature is designed to work in conjunction with our Post Indexer plugin to help you find old and inactive splogs that the API service would no longer catch. Blogs that have these keywords in posts will be temporarily flagged and added to the potential splogs queue. Keywords should only be added here temporarily while searching for splogs. CAUTION: Do not enter more than a few (2-4) keywords at a time or it may slow down or timeout the Suspected Blogs page depending on the number of site-wide posts and server speed.', 'ust'); ?></em></strong></td>
              </tr>

              <tr valign="top">
              <th scope="row"><?php _e('Additional Signup Protection', 'ust') ?></th>
              <td>
              <select name="ust[signup_protect]" id="ust_signup_protect">
          			<option value="none" <?php if($ust_settings['signup_protect'] == 'none'){echo 'selected="selected"';} ?>><?php _e('None', 'ust') ?></option>
          			<option value="questions" <?php if($ust_settings['signup_protect'] == 'questions'){echo 'selected="selected"';} ?>><?php _e('Admin Defined Questions', 'ust') ?></option>
          			<option value="asirra" <?php if($ust_settings['signup_protect'] == 'asirra'){echo 'selected="selected"';} ?>><?php _e('ASIRRA - Pick the Cats', 'ust') ?></option>
          			<option value="recaptcha" <?php if($ust_settings['signup_protect'] == 'recaptcha'){echo 'selected="selected"';} ?>><?php _e('reCAPTCHA - Advanced Captcha', 'ust') ?></option>
        			</select>
              <br /><em><?php _e('These options are designed to prevent automated spam bot signups, so will have limited effect in stopping human sploggers. Be cautious using these options as it is important to find a balance between stopping bots and not annoying your users.', 'ust'); ?></em></td>
              </td>
              </tr>

              <?php do_action('ust_settings'); ?>
          </table>

          <h3><?php _e('Defined Questions Options', 'ust') ?></h3>
        	<p><?php _e('Displays a random question from the list, and the user must enter the correct answer. It is best to create a large pool of questions that have one-word answers. Answers are not case-sensitive.', 'ust') ?></p>
          <table class="form-table">
          <tr valign="top">
      		<th scope="row"><?php _e('Questions and Answers', 'ust') ?></th>
      		<td>
      			<table>
        			<tr>
          		  <td style="width:75%">
                  <?php _e('Questions (one per row)', 'ust') ?>
                  <textarea name="ust_qa[questions]" style="width:100%" rows="10"><?php echo stripslashes(implode("\n", $questions)); ?></textarea>
                </td>
          		  <td style="width:25%">
                  <?php _e('Answers (one per row)', 'ust') ?>
                  <textarea name="ust_qa[answers]" style="width:100%" rows="10"><?php echo stripslashes(implode("\n", $answers)); ?></textarea>
                </td>
        		  </tr>
      		  </table>
      	  </td>
          </tr>
          </table>

          <h3><?php _e('Assira', 'ust') ?></h3>
        	<p><?php _e('Asirra works by asking users to identify photographs of cats and dogs. This task is difficult for computers, but user studies have shown that people can accomplish it quickly and accurately. Many even think it\'s fun!. <a href="http://research.microsoft.com/en-us/um/redmond/projects/asirra/default.aspx" target="_blank">Read more and try a demo here.</a> You must have the cURL extension enabled in PHP to use this. There are no configuration options for Assira.', 'ust') ?></p>

          <h3><?php _e('reCAPTCHA Options', 'ust') ?></h3>
        	<p><?php _e('reCAPTCHA asks someone to retype two words scanned from a book to prove that they are a human. This verifies that they are not a spambot while also correcting the automatic scans of old books. So you get less spam, and the world gets accurately digitized books. Everybody wins! For details, visit the <a href="http://recaptcha.net/">reCAPTCHA website</a>.', 'ust') ?></p>
          <p><?php _e('<strong>NOTE</strong>: Even if you don\'t use reCAPTCHA on the signup form, you should setup an API key anyway to prevent spamming from the splog review forms.', 'ust') ?></p>
          <table class="form-table">
            <tr valign="top">
        		<th scope="row"><?php _e('Keys', 'ust') ?>*</th>
        		<td>
        			<?php _e('reCAPTCHA requires an API key for each domain, consisting of a "public" and a "private" key. You can sign up for a <a href="http://recaptcha.net/whyrecaptcha.html" target="_blank">free reCAPTCHA key</a>.', 'ust') ?>
        			<br />
        			<p class="re-keys">
        				<!-- reCAPTCHA public key -->
        				<label class="which-key" for="recaptcha_pubkey"><?php _e('Public Key:&nbsp;&nbsp;', 'ust') ?></label>
        				<input name="recaptcha[pubkey]" id="recaptcha_pubkey" size="40" value="<?php echo stripslashes($ust_recaptcha['pubkey']); ?>" />
        				<br />
        				<!-- reCAPTCHA private key -->
        				<label class="which-key" for="recaptcha_privkey"><?php _e('Private Key:', 'ust') ?></label>
        				<input name="recaptcha[privkey]" id="recaptcha_privkey" size="40" value="<?php echo stripslashes($ust_recaptcha['privkey']); ?>" />
        			</p>
        	    </td>
            </tr>
          	<tr valign="top">
        		<th scope="row"><?php _e('Theme:', 'ust') ?></th>
          		<td>
          			<!-- The theme selection -->
          			<div class="theme-select">
          			<select name="recaptcha[theme]" id="recaptcha_theme">
          			<option value="red" <?php if($ust_recaptcha['theme'] == 'red'){echo 'selected="selected"';} ?>>Red</option>
          			<option value="white" <?php if($ust_recaptcha['theme'] == 'white'){echo 'selected="selected"';} ?>>White</option>
          			<option value="blackglass" <?php if($ust_recaptcha['theme'] == 'blackglass'){echo 'selected="selected"';} ?>>Black Glass</option>
          			<option value="clean" <?php if($ust_recaptcha['theme'] == 'clean'){echo 'selected="selected"';} ?>>Clean</option>
          			</select>
          			</div>
          		</td>
          	</tr>
  	        <tr valign="top">
        		<th scope="row"><?php _e('Language:', 'ust') ?></th>
          		<td>
        				<select name="recaptcha[lang]" id="recaptcha_lang">
        				<option value="en" <?php if($ust_recaptcha['lang'] == 'en'){echo 'selected="selected"';} ?>>English</option>
        				<option value="nl" <?php if($ust_recaptcha['lang'] == 'nl'){echo 'selected="selected"';} ?>>Dutch</option>
        				<option value="fr" <?php if($ust_recaptcha['lang'] == 'fr'){echo 'selected="selected"';} ?>>French</option>
        				<option value="de" <?php if($ust_recaptcha['lang'] == 'de'){echo 'selected="selected"';} ?>>German</option>
        				<option value="pt" <?php if($ust_recaptcha['lang'] == 'pt'){echo 'selected="selected"';} ?>>Portuguese</option>
        				<option value="ru" <?php if($ust_recaptcha['lang'] == 'ru'){echo 'selected="selected"';} ?>>Russian</option>
        				<option value="es" <?php if($ust_recaptcha['lang'] == 'es'){echo 'selected="selected"';} ?>>Spanish</option>
        				<option value="tr" <?php if($ust_recaptcha['lang'] == 'tr'){echo 'selected="selected"';} ?>>Turkish</option>
        				</select>
          		</td>
          	</tr>
          </table>

          <p class="submit">
          <input type="submit" name="Submit" value="<?php _e('Save Changes', 'ust') ?>" />
          </p>
          </form>

			<?php
		break;

		//---------------------------------------------------//
		case "help":

		  _e("<h3>The plugin works in 3 phases:</h3>
          <ol>
          <li><b>Signup prevention</b> - these measures are mainly to stop bots. User friendly error messages are shown to users if any of these prevent signup. They are all optional and include:</li>
            <ul style=\"margin-left:20px;\">
              <li><b>Limiting the number of signups per IP per 24 hours</b> (this can slow down human spammers too if the site clientele supports it. Probably not edublogs though as it caters to schools which may need to make a large number of blogs from one IP)</li>
              <li><b>Changing the signup page location every 24 hours</b> - this is one of the most effective yet still user-friendly methods to stop bots dead. </li>
              <li><b>Human tests</b> - answering user defined questions, picking the cat pics, or recaptcha.</li>
            </ul>
          <li><b>The API</b> - when signup is complete (email activated) and blog is first created, or when a user publishes a new post it will send all kinds of blog and signup info to our premium server where we will rate it based on our secret ever-tweaking logic. Our API will then return a splog Certainty number (0%-100%). If that number is greater than the sensitivity preference you set in the settings (80% default) then the blog gets spammed. Since the blog was actually created, it will show up in the site admin still (as spammed) so you can unspam later if there was a mistake (and our API will learn from that).</li>
          <li><b>The Moderation Queue</b> - for existing blogs or blogs that get past other filters, the queue provides an ongoing way to monitor blogs and spam or flag them as valid (ignore) them more easily as they are updated with new posts. Also if a user tries to visit a blog that has been spammed, it will now show a user-friendly message and form to contact the admin for review if they think it was valid. The email contains links to be able to easily unspam or bring up the last posts. The entire queue is AJAX based so you can moderate blogs with incredible speed.</li>
            <ul style=\"margin-left:20px;\">
              <li><b>Suspected Blogs</b> - this list pulls in any blogs that the plugin thinks may be splogs. It pulls in blogs that have a greater that 0% certainty as previously returned by our API, and those that contain at least 1 keyword in recent posts from the keyword list you define. The list attempts to bring the most suspected blogs to the top, ordered by # of keyword matches, then % splog certainty (as returned by the API), then finally by last updated. The list has a bunch of improvements for moderation, including last user id, last user ip, links to search for or spam any user and their blogs or blogs tied to an IP (be careful with that one!), ability to ignore (dismiss) valid blogs from the queue, and a list of recent posts and instant previews of their content without leaving the page (the most time saving feature of all!)</li>
              <li><b>Recent Splogs</b> - this is simply a list of all blogs that have been spammed on the site ever, in order of the time they were spammed. The idea here is that if you make a mistake you can come back here to undo. Also if a user complains that a valid blog was spammed, you can quickly pull it up here and see previews of the latest posts to confirm (normally you wouldn't be able to see blog content at all).</li>
              <li><b>Ignored Blogs</b> - If a valid blog shows up in the suspect list, simply mark it as ignored to get it out of there. It will then show in the ignored list just in case you need to undo.</li>
            </ul>
          </ol>", 'ust');
        echo '<p style="text-align:center;"><img src="'.WPMU_PLUGIN_URL.'/anti-splog/anti-splog.gif" /></p>';

		break;


	} //end switch

	//hook to extend admin screen. Check $_GET['tab'] for new tab
	do_action('ust_add_screen');

	echo '</div>';
}


class UST_Widget extends WP_Widget {

	function UST_Widget() {
		$widget_ops = array('classname' => 'ust_widget', 'description' => __('Displays counts of site blogs and splogs caught by the Anti-Splog.', 'ust') );
    $this->WP_Widget('ust_widget', __('Splog Statistics', 'ust'), $widget_ops);
	}

	function widget($args, $instance) {
		global $wpdb, $current_user, $bp;

		extract( $args );
		$date_format = __('m/d/Y g:ia', 'ust');

		echo $before_widget;
	  $title = $instance['title'];
		if ( !empty( $title ) ) { echo $before_title . apply_filters('widget_title', $title) . $after_title; };
		?>
		<ul>
		  <li><?php _e('Blogs: ', 'ust'); echo get_blog_count(); ?></li>
		  <li><?php _e('Splogs Caught: ', 'ust'); echo get_site_option('ust_spam_count'); ?></li>
		</ul>

	<?php echo $after_widget; ?>
	<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

	function form( $instance ) {
    $instance = wp_parse_args( (array) $instance, array( 'title' => __('Splog Statistics', 'ust') ) );
		$title = strip_tags($instance['title']);
  ?>
			<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'ust') ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo attribute_escape($title); ?>" /></label></p>
	<?php
	}
}

//------------------------------------------------------------------------//

//---Support Functions----------------------------------------------------//

//------------------------------------------------------------------------//


?>