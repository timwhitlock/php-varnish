<?php
/*
Plugin Name: WordPress Varnish Admin
Plugin URI: http://github.com/timwhitlock/php-varnish/tree/master/php-varnish/wordpress
Version: 0.1
Author: <a href="http://twitter.com/timwhitlock">Tim Whitlock</a>
Description: A plugin enabling Wordpress to purge Varnish caches via the varnishadm program
*/




/**
 * Send multiple purge commands to all varnishadm sockets
 */
function wpv_purge_urls( array $urls ){
    // @todo get server params from config
    $hostpattern = 'example\\\\.com$';
    $servers[] = array( '127.0.0.1', '6082' ); 
    // ensure admin class is available
    if( ! class_exists('VarnishAdminSocket') ){
        include dirname(__FILE__).'/../VarnishAdminSocket.php';
        if( ! class_exists('VarnishAdminSocket') ){
            throw new Exception('Failed to include VarnishAdminSocket class');
        }
    }
    // iterate over all available sockets
    foreach( $servers as $server ){
        try {
            list( $host, $port ) = $server;
            $Sock = new VarnishAdminSocket( $host, $port );
            $Sock->connect();
            if( ! $Sock->status() ){
                throw new Exception( sprintf('Varnish server stopped on host %s:%d', $host, $port ) );
            }
            // iterate over all URLs and purge from this socket
            foreach( $urls as $url => $bool ){
                try {
                    $expr = sprintf('req.url ~ "%s"', $url );
                    if( $hostpattern ){
                        $expr .= sprintf(' && req.host.http ~ "%s"', $hostpattern );
                    }
                    $Sock->purge( $expr );
                    // purge ok - temporary logging for debug
                    error_log('wpv purged: '.var_export($expr,1), 0 );
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
function wpv_edit_post_action( $postid ){
    global $wpv_to_purge;
    $uri = parse_url( get_permalink($postid), PHP_URL_PATH );
    if( ! $uri ){
        trigger_error('Failed to get permalink path from post with id '.$postid, E_USER_WARNING);
        return;
    }
    // common, home page and all feeds
    $wpv_to_purge['^/$'] = true;
    $wpv_to_purge['^/feed'] = true;
    // the actual post page, and any extentions thereof
    $wpv_to_purge['^'.$uri] = true;
    // archive page, if permalink starts with a date
    // @todo support for posts that don't have permalink beginning with date
    if( preg_match( '!^/\d+/\d+/\d+/!', $uri, $r ) ){
        $wpv_to_purge['^'.$r[0].'$'] = true;
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
                $wpv_to_purge['^'.$uri] = true;
            }
        }
    }
}



/**
 * Collect URLs to purge relating to a comment
 */
function wpv_edit_comment_action( $commentid ){
    $comment = get_comment($commentid) and
    $postid = $comment->comment_post_ID;
    if( ! isset($postid) ){
        trigger_error('Failed to get post from comment with id '.$commentid, E_USER_WARNING);
        return;
    }
    // purge post that comment is on
    wpv_edit_post_action( $post_id );
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



// invoke purge actions when posts and comments are edited
add_action( 'edit_post',         'wpv_edit_post_action',    99 );
add_action( 'deleted_post',      'wpv_edit_post_action',    99 );
add_action( 'comment_post',      'wpv_edit_comment_action', 99 );
add_action( 'edit_comment',      'wpv_edit_comment_action', 99 );
add_action( 'trashed_comment',   'wpv_edit_comment_action', 99 );
add_action( 'untrashed_comment', 'wpv_edit_comment_action', 99 );
add_action( 'deleted_comment',   'wpv_edit_comment_action', 99 );


// hold all URLs in a global and purge on shutdown - this is designed to avoid duplicate purges.
// yes, I know globals are nasty, but this is Wordpress we're dealing with here.
$GLOBALS['wpv_to_purge'] = array();
add_action( 'shutdown', 'wpv_purge_on_shutdown', 0 );



// hack REMOTE_ADDR, because neither Wordpress or Akismet check for proxy
// note that Varnish should set this header before passing to backend
if( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ){
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
}


