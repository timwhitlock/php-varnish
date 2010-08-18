<?php
/**
 * 
 */
error_reporting( E_ALL | E_STRICT );
require 'VarnishAdminSocket.php';



$Sock = new VarnishAdminSocket( 'localhost', 6082 );

$banner = $Sock->connect();
echo $banner, "\n\n";

echo "Purge list\n";
$list = $Sock->purge_list();
var_dump( $list );


$Sock->quit();









