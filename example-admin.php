<?php
/**
 * Example use of VarnishAdminSocket class.
 * All commands throw an Exception on failure
 */


// plain text output; make like CLI
if( PHP_SAPI !== 'cli' ){
	header('Content-Type: text/plain; charset=utf-8');
	ini_set('html_errors', 0 );
	ob_implicit_flush( 1 );
}

error_reporting( E_ALL | E_STRICT );
require 'VarnishAdminSocket.php';


// open socket connection with your known host and IP
$Sock = new VarnishAdminSocket( 'localhost', 8080 );
$Sock->connect(1);


// Check that child is running. If varnish wasn't running at all, connect would have timed out
$running = $Sock->status();
var_dump( $running );


// stop it, and check again
$Sock->stop();
sleep(1);
$running = $Sock->status();
var_dump( $running );


// start it up again, and check
$Sock->start();
sleep(1);
$running = $Sock->status();
var_dump( $running );


// purge your home page
$Sock->purge_url('^/$');


// purge by a more complex rule
$Sock->purge('req.url ~ ^/$ && req.http.host ~ example\\\\.com$');


// show purge list
$list = $Sock->purge_list();
var_dump( $list );


// exit gracefully, quits CLI and closes socket
$Sock->quit();









