<?php
//return header to remove from search engines
status_header( 410 );

//don't display spam form if archived
if ( $current_blog->archived == '1' ) {
	if ( file_exists( WP_CONTENT_DIR . '/blog-suspended.php' ) )
		return WP_CONTENT_DIR . '/blog-suspended.php';
	else
		wp_die( __( 'This site has been archived or suspended.' ), '', array( 'response' => 410 ) );
}

require_once( ABSPATH . WPINC . '/pluggable.php' );

//setup proper urls
if ( version_compare( $wp_version, '3.0.9', '>' ) ) {
	$ust_admin_url = network_admin_url( 'settings.php?page=ust' );
} else {
	$ust_admin_url = network_admin_url( 'ms-admin.php?page=ust' );
}

//process form
$email_sent = $error1 = $error2 = $reason = false;
if ( isset( $_POST['wp-submit'] ) && ! get_option( 'ust_email_sent' ) ) {
	$reason = wp_filter_nohtml_kses( stripslashes( trim( $_POST['reason'] ) ) );

	if ( strlen( $reason ) < 20 ) {
		$error1 = '<p class="error">' . __( "Please enter a valid reason.", 'ust' ) . '</p>';
	}

	//check reCAPTCHA
	$recaptcha = get_site_option( 'ust_recaptcha' );
	if ( $recaptcha['privkey'] ) {
		$resp = ust_recaptcha_check_answer( $recaptcha['privkey'], $_SERVER["REMOTE_ADDR"], $_POST["g-recaptcha-response"] );
		if ( ! $resp ) {
			$error2 = '<p class="error">' . __( "The reCAPTCHA wasn't entered correctly. Please try again.", 'ust' ) . '</p>';
		}
	}

	if ( ! $error1 && ! $error2 ) {

		$admin_email     = get_site_option( "admin_email" );
		$user_email      = get_option( 'admin_email' );
		$review_url      = $ust_admin_url . "&tab=splogs&bid=$blog_id";
		$message_headers = "MIME-Version: 1.0\n" . "From: $user_email\n" . "Content-Type: text/plain; charset=\"" . get_option( 'blog_charset' ) . "\"\n";
		$subject         = sprintf( __( 'Splog Review Request: %s', 'ust' ), get_bloginfo( 'url' ) );
		$message         = sprintf( __( "Someone is disputing the spam status for the blog %s (%s).\nHere is their reason:\n_______________________\n\n%s\n\n_______________________\n", 'ust' ), get_bloginfo( 'name' ), get_bloginfo( 'url' ), $reason );
		$message .= sprintf( __( "Review: %s\n", 'ust' ), $review_url );
		wp_mail( $admin_email, $subject, $message, $message_headers );

		//save that the email was sent
		update_option( 'ust_email_sent', '1' );
		$email_sent = true;
	}
}

$auto_spammed = get_option( 'ust_auto_spammed' );


//fixes css urls to be from home site so they are not blocked
function override_css_url( $url ) {
	return str_replace( site_url( '/' ), network_site_url( '/' ), $url );
}
add_filter( 'style_loader_src', 'override_css_url' );

/**
 * Output the login page header.
 *
 * @param string   $title    Optional. WordPress Log In Page title to display in <title> element. Default 'Log In'.
 * @param string   $message  Optional. Message to display in header. Default empty.
 * @param WP_Error $wp_error Optional. The error to pass. Default empty.
 */
function login_header( $title = 'Log In', $message = '', $wp_error = '' ) {
global $error, $interim_login, $action;

// Don't index any of these forms
add_action( 'login_head', 'wp_no_robots' );

if ( wp_is_mobile() ) {
	add_action( 'login_head', 'wp_login_viewport_meta' );
}

if ( empty( $wp_error ) ) {
	$wp_error = new WP_Error();
}


?><!DOCTYPE html>
<!--[if IE 8]>
<html xmlns="http://www.w3.org/1999/xhtml" class="ie8" <?php language_attributes(); ?>>
<![endif]-->
<!--[if !(IE 8) ]><!-->
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<!--<![endif]-->
<head>
    <meta http-equiv="Content-Type"
          content="<?php bloginfo( 'html_type' ); ?>; charset=<?php bloginfo( 'charset' ); ?>"/>
    <title><?php bloginfo( 'name' ); ?> &rsaquo; <?php echo $title; ?></title>
	<?php

	wp_admin_css( 'login', true );

	/**
	 * Enqueue scripts and styles for the login page.
	 *
	 * @since 3.1.0
	 */
	do_action( 'login_enqueue_scripts' );
	/**
	 * Fires in the login page header after scripts are enqueued.
	 *
	 * @since 2.1.0
	 */
	do_action( 'login_head' );

	if ( is_multisite() ) {
		$login_header_url   = network_home_url();
		$login_header_title = get_current_site()->site_name;
	} else {
		$login_header_url   = __( 'https://wordpress.org/' );
		$login_header_title = __( 'Powered by WordPress' );
	}


	$classes = array( 'login-action-' . $action, 'wp-core-ui' );
	if ( wp_is_mobile() ) {
		$classes[] = 'mobile';
	}
	if ( is_rtl() ) {
		$classes[] = 'rtl';
	}
	if ( $interim_login ) {
		$classes[] = 'interim-login';
		?>

		<?php

		if ( 'success' === $interim_login ) {
			$classes[] = 'interim-login-success';
		}
	}
	$classes[] = ' locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) );

	/**
	 * Filter the login page body classes.
	 *
	 * @since 3.5.0
	 *
	 * @param array  $classes An array of body classes.
	 * @param string $action  The action that brought the visitor to the login page.
	 */
	$classes = apply_filters( 'login_body_class', $classes, $action );

	?>
    <style type="text/css">#login { width: 350px; }</style>
</head>
<body class="login <?php echo esc_attr( implode( ' ', $classes ) ); ?>">
<div id="login">
    <h1><a href="<?php echo esc_url( $login_header_url ); ?>" title="<?php echo esc_attr( $login_header_title ); ?>"
           tabindex="-1"><?php bloginfo( 'name' ); ?></a></h1>
	<?php

	unset( $login_header_url, $login_header_title );

	/**
	 * Filter the message to display above the login form.
	 *
	 * @since 2.1.0
	 *
	 * @param string $message Login message text.
	 */
	$message = apply_filters( 'login_message', $message );
	if ( ! empty( $message ) ) {
		echo $message . "\n";
	}

	// In case a plugin uses $error rather than the $wp_errors object
	if ( ! empty( $error ) ) {
		$wp_error->add( 'error', $error );
		unset( $error );
	}

	if ( $wp_error->get_error_code() ) {
		$errors   = '';
		$messages = '';
		foreach ( $wp_error->get_error_codes() as $code ) {
			$severity = $wp_error->get_error_data( $code );
			foreach ( $wp_error->get_error_messages( $code ) as $error_message ) {
				if ( 'message' == $severity ) {
					$messages .= '	' . $error_message . "<br />\n";
				} else {
					$errors .= '	' . $error_message . "<br />\n";
				}
			}
		}
		if ( ! empty( $errors ) ) {
			/**
			 * Filter the error messages displayed above the login form.
			 *
			 * @since 2.1.0
			 *
			 * @param string $errors Login error message.
			 */
			echo '<div id="login_error">' . apply_filters( 'login_errors', $errors ) . "</div>\n";
		}
		if ( ! empty( $messages ) ) {
			/**
			 * Filter instructional messages displayed above the login form.
			 *
			 * @since 2.5.0
			 *
			 * @param string $messages Login messages.
			 */
			echo '<p class="message">' . apply_filters( 'login_messages', $messages ) . "</p>\n";
		}
	}
	} // End of login_header()

	/**
	 * Outputs the footer for the login page.
	 *
	 * @param string $input_id Which input to auto-focus
	 */
	function login_footer( $input_id = '' ) {
	?>

</div>


<?php
/**
 * Fires in the login page footer.
 *
 * @since 3.1.0
 */
do_action( 'login_footer' ); ?>
<div class="clear"></div>
</body>
</html>
<?php
}

//start loading up the page
nocache_headers();

header( 'Content-Type: ' . get_bloginfo( 'html_type' ) . '; charset=' . get_bloginfo( 'charset' ) );

login_header( __( 'Blog Spammed' ) );

?>

<style type="text/css" media="screen">
    #login form p {
        margin-bottom: 5px;
    }

    #reCAPTCHA {
        margin-left: -10px;
    }

    p.error {
        border: 1px solid red;
        padding: 5px;
    }
</style>


<form name="contactform" id="loginform" action="<?php echo trailingslashit( get_bloginfo( 'url' ) ); ?>" method="post">

	<?php if ( $email_sent ) { ?>

        <p><?php _e( 'Your message has been sent. We will review it shortly.', 'ust' ); ?></p>

	<?php } else { ?>
		<?php if ( $auto_spammed ) { ?>
            <p><?php _e( 'Our automated filters have determined that this blog signup looks like it could be by a spammer. Because of this, to complete you registration please describe in one or two sentences what you intend to use this blog for in the form below and we will review your request. Thank you for your cooperation!', 'ust' ); ?></p>
		<?php } else { ?>
            <p><?php _e( 'Sorry, but this blog has been marked as spam as defined in our Terms of Service.', 'ust' ); ?></p>
		<?php } ?>

		<?php if ( ! get_option( 'ust_email_sent' ) ) { ?>
			<?php if ( ! $auto_spammed ) { ?>
                <p><?php _e( 'If you believe this decision was made in error you may contact us with your <strong>detailed</strong> reasons using the form below:', 'ust' ); ?></p>
			<?php }
			echo $error1; ?>
            <p>
                <label><?php _e( 'Reason:', 'ust' ) ?><br/>
                    <textarea name="reason" style="width: 100%" rows="5"
                              tabindex="20"><?php echo esc_textarea( $reason ); ?></textarea></label>
            </p>
			<?php
			$recaptcha = get_site_option( 'ust_recaptcha' );

			if ( $recaptcha['privkey'] ) {
				echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
				echo $error2;
				echo '<div class="g-recaptcha" data-sitekey="' . esc_attr( $recaptcha['pubkey'] ) . '" data-theme="' . esc_attr( $recaptcha['theme'] ) . '"></div>';
				echo '<br />';
			}
			?>
            <br class="clear"/>
            <p class="submit"><input type="submit" name="wp-submit" id="wp-submit"
                                     class="button button-primary button-large"
                                     value="<?php _e( 'Submit', 'ust' ); ?>"/></p>
		<?php } else { ?>
            <p><?php _e( 'The admin has already been contacted to review.', 'ust' ); ?></p>
			<?php
		}
	} ?>

</form>

<?php
login_footer();