<?php
/*
Plugin Name: WordPress Varnish Admin
Plugin URI: http://github.com/timwhitlock/php-varnish/tree/master/php-varnish/wordpress
Version: 0.1
Author: <a href="http://twitter.com/timwhitlock">Tim Whitlock</a>
Description: A plugin enabling Wordpress to purge Varnish caches via the varnishadm program
*/




/**
 * Send multiple purge commands to all sockets
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
            foreach( $urls as $url ){
                try {
                    $expr = sprintf('req.url ~ "%s"', $url );
                    if( $hostpattern ){
                        $expr .= sprintf(' && req.host.http ~ "%s"', $hostpattern );
                    }
                    $Sock->purge( $expr );
                    // purge ok - @todo log this?
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
}




