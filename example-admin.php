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


// open socket connection with your known host, port and version
$Sock = new VarnishAdminSocket( 'localhost', 6082, '3.0.1' );
// secret text from file probably has a trailing newline
$Sock->set_auth("extremelysecrettext\n");
// connect to socket with a timeout parameter
echo 'Connecting ... ';
try {
    $Sock->connect(1);
    echo "OK\n";
}
catch( Exception $Ex ){
    echo '**FAIL**: ', $Ex->getMessage(), "\n";
    exit(0);
}

// Check that child is running. If varnish wasn't running at all, connect would have timed out
$running = $Sock->status();
echo 'Running: ', $running ? 'Yep' : 'Nope', "\n";


// stop it, and check again
if( $running ){
    echo "Stopping ... \n";
    $Sock->stop();
    sleep(1);
    $running = $Sock->status();
    echo 'Running: ', $running ? 'Yep' : 'Nope', "\n";
}

// start it up again, and check
echo "Starting ... \n";
$Sock->start();
sleep(1);
$running = $Sock->status();
echo 'Running: ', $running ? 'Yep' : 'Nope', "\n";


// purge your home page
$Sock->purge_url('^/$');


// purge by a more complex rule
$Sock->purge('req.url ~ ^/$ && req.http.host ~ example\\\\.com$');


// show purge list
echo "Getting purge list ...\n";
$list = $Sock->purge_list();
var_dump( $list );


// exit gracefully, quits CLI and closes socket
$Sock->quit();









