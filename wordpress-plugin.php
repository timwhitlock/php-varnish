<?php
/**
Plugin Name: WordPress Varnish Admin
Plugin URI: http://github.com/timwhitlock/php-varnish
Description: A plugin enabling Wordpress to purge Varnish caches via the varnishadm program
Version: 0.1
Author: Tim Whitlock
Author URI: http://twitter.com/timwhitlock
*/



/**
 * Test whether current request is proxied
 * @return bool
 */
function wpv_is_proxied(){
	return isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_VARNISH']);
}



/**
 * Gets base URL of site according to *currently* serving host.
 * This is required of you want to access origin server directly for debugging.
 * @return string e.g. http://origin.example.com:8080
 */
function wpv_baseurl(){
	static $baseurl;
	if( ! isset($baseurl) ){
		$hostname = $_SERVER['HTTP_HOST']; // <- includes :port
		$protocol = empty($_SERVER['HTTPS']) ? 'http' : 'https';
		$baseurl = $protocol.'://'.$hostname;
	}
	return $baseurl;
}



/**
 * As above, this ensures that theme can be served by origin server directly
 */
function wpv_themeurl( $themes_baseurl, $site_baseurl, $theme_dir ){
	return wpv_baseurl(). '/wp-content/themes';
}



/** admin menu hook */
function wpv_admin_menu() {
    add_options_page( 'Varnish Admin Options', 'Varnish Admin', 'manage_options', 'wpv-admin', 'wpv_admin_page');
}



/** admin page hook */
function wpv_admin_page(){
    include dirname(__FILE__).'/wordpress-plugin/wordpress-admin.php';
}



/**
 * Parse raw postdata because Wordpress is *insane* and forces addslashes
 * Note: this doesn't parse array parameters like "foo[]=bar&foo[]=baz"
 * @param string
 * @return array
 */
function wpv_postdata( $raw, $pair_sep = '&', $arg_sep = '=' ){
    $data = array();
    foreach( explode($pair_sep, $raw) as $pair ){
        @list($key, $val) = explode($arg_sep, $pair);
        $key = urldecode($key);
        $val = urldecode($val);
        if( isset( $data[$key] ) ){
            if( ! is_array($data[$key]) ){
                $data[$key] = array( $data[$key] );
            }
            $data[$key][] = $val;
        }
        else{
            $data[$key] = $val;
        }
    }
    return $data;
}



/**
 * parse client options saved as raw text
 * @return array [ [ host, port, vers ], ... ]
 */
function wpv_get_clients( $raw = null ){
    if( is_null($raw) ){
        $raw = get_option('wpv_clients');
    }
    if( ! $raw ){
        return;
    }
    $clients = array();
    foreach( preg_split('/[^a-z0-9\.\:\- ]+/i', trim($raw), -1, PREG_SPLIT_NO_EMPTY ) as $line ){
        $client = preg_split('/[^a-z0-9\.]/i', $line, 3, PREG_SPLIT_NO_EMPTY );
        empty($client[1]) and $client[1] = '6082';
        empty($client[2]) and $client[2] = '2.1';
        $clients[] = $client;
    }
    return $clients;
}


/**
 * parse auth secret options saved as urlencoded text
 */
function wpv_get_secrets( $raw = null ){
    if( is_null($raw) ){
        $raw = get_option('wpv_secrets');
    }
    if( ! $raw ){
        return;
    }
    $secrets = array();
    foreach( preg_split('/[^a-z0-9_\-\.~\%]+/i', $raw ) as $line ){
        $secrets[] = rawurldecode($line);
    }
    return $secrets;
}



/**
 * instantiate an admin socket
 * @return VarnishAdminSocket
 */
function wpv_admin_socket( $host, $post, $vers ){
    if( ! class_exists('VarnishAdminSocket') ){
        include dirname(__FILE__).'/VarnishAdminSocket.php';
        if( ! class_exists('VarnishAdminSocket') ){
            throw new Exception('Failed to include VarnishAdminSocket class');
        }
    }
    return new VarnishAdminSocket( $host, $post, $vers );
}



/**
 * Send multiple purge commands to all varnishadm sockets
 */
function wpv_purge_urls( array $urls ){
    $hostpattern = get_option('wpv_host_pattern','');
    $secrets = wpv_get_secrets();
    $clients = wpv_get_clients();
    if( ! $clients ){
        return;
    }
    // iterate over all available sockets
    foreach( $clients as $i => $client ){
        try {
            list( $host, $port, $vers ) = $client;
            $Sock = wpv_admin_socket( $host, $port, $vers );
            if( ! empty($secrets[$i]) ){
                $Sock->set_auth( $secrets[$i] );
            }
            $Sock->connect();
            if( ! $Sock->status() ){
                throw new Exception( sprintf('Varnish server stopped on host %s:%d', $host, $port ) );
            }
            // iterate over all URLs and purge from this socket
            foreach( $urls as $url => $bool ){
                try {
                    $expr = sprintf('req.url ~ "%s"', $url );
                    if( $hostpattern ){
                        $expr .= sprintf(' && req.http.host ~ "%s"', $hostpattern );
                    }
                    $Sock->purge( $expr );
                }
                catch( Exception $Ex ){
                    trigger_error( $Ex->getMessage(), E_USER_WARNING );
                    continue;
                }
            }
        }
        catch( Exception $Ex ){
            trigger_error( $Ex->getMessage(), E_USER_WARNING );
        }
        // next socket
        $Sock->quit();
    }
    return true;
}



/**
 * Collect URLs to purge relating to a post
 */
function wpv_edit_post_action( $postid, $comment = false ){

    global $wpv_to_purge;
    $uri = parse_url( get_permalink($postid), PHP_URL_PATH );
    if( ! $uri ){
        trigger_error('Failed to get permalink path from post with id '.var_export($postid,1), E_USER_NOTICE);
        return;
    }

    // the actual post page, and any extensions thereof
    $wpv_to_purge['^'.$uri.'?'] = true;

    // only purge feed and taxonomies when it is not a comment
    if( $comment ){
        return;
    }
    // always purge all feeds
    $wpv_to_purge['^/feed'] = true;

    // to purge all archives and index pages we will purge all sub paths absolutely
    $bits = preg_split( '!/!', $uri, -1, PREG_SPLIT_NO_EMPTY );
    while( array_pop($bits) ){
        $path = implode('/',$bits);
        // rebuild the post page
        $patt = $path ? '^/'.$path.'/?$' : '^/$';
        $wpv_to_purge[$patt] = true;
        // rebuild all the paginated pages
        $patt = $path ? '^/'.$path.'/page/.*' : '^/page/.*';
        $wpv_to_purge[$patt] = true;
    }
    // purge pop up comments?
    // '\\?comments_popup='.$postid; // <- untested
    // tag and category page listings
    foreach( get_taxonomies() as $t ){
        $terms = get_the_terms( $postid, $t);
        if( $terms ){
            foreach( $terms as $term ){
                $uri = get_term_link( $term, $t) and
                $uri = parse_url( $uri, PHP_URL_PATH ) and
                $wpv_to_purge['^'.$uri.'?'] = true;
            }
        }
    }
}



/**
 * Collect URLs to purge relating to a comment
 */
function wpv_edit_comment_action( $commentid ){
    if( ! $commentid ){
        return;
    }
    $comment = get_comment($commentid) and
    $postid = $comment->comment_post_ID;
    if( empty($postid) ){
        trigger_error('Failed to get post from comment with id '.var_export($commentid), E_USER_NOTICE);
        return;
    }
    // purge post that comment is on
    wpv_edit_post_action( $postid, true );
    global $wpv_to_purge;
    $wpv_to_purge['^/comments/feed'] = true;
}



/**
 * Shutdown function; purges all URLs this execution
 * @todo async this with Gearman perhaps?
 */
function wpv_purge_on_shutdown(){
     global $wpv_to_purge;
     if( $wpv_to_purge ){
         wpv_purge_urls( $wpv_to_purge );
     }
}



if( get_option('wpv_enabled') ){
    // invoke purge actions when posts and comments are edited
    add_action( 'publish_page',      'wpv_edit_post_action',    99, 1 );
    add_action( 'publish_post',      'wpv_edit_post_action',    99, 1 );
    add_action( 'deleted_post',      'wpv_edit_post_action',    99, 1 );
    add_action( 'comment_post',      'wpv_edit_comment_action', 99, 1 );
    add_action( 'edit_comment',      'wpv_edit_comment_action', 99, 1 );
    add_action( 'trashed_comment',   'wpv_edit_comment_action', 99, 1 );
    add_action( 'untrashed_comment', 'wpv_edit_comment_action', 99, 1 );
    add_action( 'deleted_comment',   'wpv_edit_comment_action', 99, 1 );

    // hold all URLs in a global and purge on shutdown - this is designed to avoid duplicate purges.
    // yes, I know globals are nasty, but this is Wordpress we're dealing with here.
    $GLOBALS['wpv_to_purge'] = array();
    add_action( 'shutdown', 'wpv_purge_on_shutdown', 0 );
}

// register admin pages
add_action('admin_menu', 'wpv_admin_menu');


// filter base URLs when accessing directly
if( ! wpv_is_proxied() ){
	add_filter('pre_option_siteurl', 'wpv_baseurl', 0 );
	add_filter('pre_option_home', 'wpv_baseurl', 0 );
	add_filter('theme_root_uri', 'wpv_themeurl', 0, 3 );
}


// hack REMOTE_ADDR, because neither Wordpress nor Akismet check for proxy
// note that Varnish should set this header before passing to backend
if( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ){
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
}


