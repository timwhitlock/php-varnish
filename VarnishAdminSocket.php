<?php
/**
 * Varnish admin socket for executing varnishadm CLI commands.
 * @see http://varnish-cache.org/wiki/CLI
 * @author Tim Whitlock http://twitter.com/timwhitlock
 * 
 * @todo add all short cut methods to commands listed below
 * @todo sanitise command parameters, such as regexp
 * 
 * Tested with varnish-2.1.3 SVN 5049:5055; 
 *             varnish-2.1.5 SVN 0843d7a
 * CLI commands available as follows:
    help [command]
    ping [timestamp]
    auth response
    quit
    banner
    status
    start
    stop
    stats
    vcl.load <configname> <filename>
    vcl.inline <configname> <quoted_VCLstring>
    vcl.use <configname>
    vcl.discard <configname>
    vcl.list
    vcl.show <configname>
    param.show [-l] [<param>]
    param.set <param> <value>
    purge.url <regexp>
    purge <field> <operator> <arg> [&& <field> <oper> <arg>]...
    purge.list
 */



 

/**
 * varnishadm connection class
 */
class VarnishAdminSocket {
    private $ban;
    /**
     * Socket pointer
     * @var resource
     */
    private $fp;
    
    /**
     * Host on which varnishadm is listening
     * @var string
     */
    private $host;
    
    /**
     * Port on which varnishadm is listening, usually 6082
     * @var string port
     */
    private $port;
    
    /**
     * Secret to use in authentication challenge.
     * @var string 
     */
    private $secret;
    
    /**
     * Major version of Varnish top which you're connecting; 2 or 3
     * @var int
     */
    private $version;
    
    /**
     * Minor version of Varnish top which you're connecting
     * @var int
     */
    private $version_minor;
    
    
    /**
     * Constructor
     * @param string host
     * @param int port
     * @param string optional version, defaults to 2.1
     */
    public function __construct( $host = '127.0.0.1', $port = 6082, $v = '2.1' ){
        $this->host = $host;
        $this->port = $port;
        // parse expected version number
        $vers = explode('.',$v,3);
        $this->version = isset($vers[0]) ? (int) $vers[0] : 2;
        $this->version_minor = isset($vers[1]) ? (int) $vers[1] : 1;
        if( 2 === $this->version ){
            // @todo sanity check 2.x number
        }
        else if( 3 === $this->version ){
            // @todo sanity check 3.x number
        }
        else {
            throw new Exception('Only versions 2 and 3 of Varnish are supported');
        }
        $this->ban = $this->version === 3 ? 'ban' : 'purge';
    }
    
    
    /**
     * Set authentication secret.
     * Warning: may require a trailing newline if passed to varnishadm from a text file
     * @param string
     * @return void
     */
    public function set_auth( $secret ){
        $this->secret = $secret;
    }
    
    
    /**
     * Connect to admin socket
     * @param int optional timeout in seconds, defaults to 5; used for connect and reads
     * @return string the banner, in case you're interested
     */
    public function connect( $timeout = 5 ){
        $this->fp = fsockopen( $this->host, $this->port, $errno, $errstr, $timeout );
        if( ! is_resource( $this->fp ) ){
            // error would have been raised already by fsockopen
            throw new Exception( sprintf('Failed to connect to varnishadm on %s:%s; "%s"', $this->host, $this->port, $errstr ));
        }
        // set socket options
        stream_set_blocking( $this->fp, 1 );
        stream_set_timeout( $this->fp, $timeout );
        // connecting should give us the varnishadm banner with a 200 code, or 107 for auth challenge
        $this->banner = $this->read( $code );
        if( $code === 107 ){
            if( ! $this->secret ){
                throw new Exception('Authentication required; see VarnishAdminSocket::set_auth');
            }
            try {
                $challenge = substr( $this->banner, 0, 32 );
                $response = hash('sha256', $challenge."\n".$this->secret.$challenge."\n");
                $this->banner = $this->command('auth '.$response, $code, 200 );
            }
            catch( Exception $Ex ){
                throw new Exception('Authentication failed');
            }
        }
        if( $code !== 200 ){
            throw new Exception( sprintf('Bad response from varnishadm on %s:%s', $this->host, $this->port));
        }
        return $this->banner;
    }
    
    
    
    /**
     * Write data to the socket input stream
     * @param string
     * @return bool
     */
    private function write( $data ){
        $bytes = fputs( $this->fp, $data );
        if( $bytes !== strlen($data) ){
            throw new Exception( sprintf('Failed to write to varnishadm on %s:%s', $this->host, $this->port) );
        }
        return true;
    }    
    
    

    /**
     * Write a command to the socket with a trailing line break and get response straight away
     * @param string
     * @return string
     */
    public function command( $cmd, &$code, $ok = 200 ){
        $cmd and $this->write( $cmd );
        $this->write("\n");
        $response = $this->read( $code );
        if( $code !== $ok ){
            $response = implode("\n > ", explode("\n",trim($response) ) );
            throw new Exception( sprintf("%s command responded %d:\n > %s", $cmd, $code, $response), $code );
        }
        return $response;
    }
    
    
    
    /**
     * @param int reference for reply code
     * @return string
     */
    private function read( &$code ){
        $code = 0;
        // get bytes until we have either a response code and message length or an end of file
        // code should be on first line, so we should get it in one chunk
        while ( ! feof($this->fp) ) {
            $response = fgets( $this->fp, 1024 );
            if( ! $response ){
                $meta = stream_get_meta_data($this->fp);
                if( $meta['timed_out'] ){
                    throw new Exception(sprintf('Timed out reading from socket %s:%s',$this->host,$this->port));
                }
            }
            if( preg_match('/^(\d{3}) (\d+)/', $response, $r) ){
                $code = (int) $r[1];
                $len = (int) $r[2];
                break;
            }
        }
        if( ! isset($code) ){
            throw new Exception('Failed to get numeric code in response');
        }
        $response = '';
        while ( ! feof($this->fp) && strlen($response) < $len ) {
            $response .= fgets( $this->fp, 1024 );
        }
        return $response;
    }
    
    
    
    /**
     * Brutal close, doesn't send quit command to varnishadm
     * @return void
     */
    public function close(){
        is_resource($this->fp) and fclose($this->fp);
        $this->fp = null;
    }
    
    
    
    /**
     * Graceful close, sends quit command
     * @return void
     */
    public function quit(){
        try {
            $this->command('quit', $code, 500 );
        }
        catch( Exception $Ex ){
            // slient fail - force close of socket
        }
        $this->close();
    }
    
    
    
    /**
     * Shortcut to purge function
     * @see http://varnish-cache.org/wiki/Purging
     * @param string purge expression in form "<field> <operator> <arg> [&& <field> <oper> <arg>]..."
     * @return string
     */
    public function purge( $expr ){
        return $this->command( $this->ban.' '.$expr, $code );
    }
    
    
    
    /**
     * Shortcut to purge.url function
     * @see http://varnish-cache.org/wiki/Purging
     * @param string url to purge
     * @return string
     */
    public function purge_url( $expr ){
        return $this->command( $this->ban.'.url '.$expr, $code );
    }    
    
    
    
    /**
     * Shortcut to purge.list function
     * @todo should we parse the reponse lines?
     * @return array
     */
    public function purge_list(){
        $response = $this->command( $this->ban.'.list', $code );
        return explode( "\n",trim($response) );
    }
    
    
    
    /**
     * Test varnish child status
     * @return bool whether child is alive
     */
    public function status(){
        try {
            $response = $this->command( 'status', $code );
            if( ! preg_match('/Child in state (\w+)/', $response, $r ) ) {
                return false;
            }
            return $r[1] === 'running';
        }
        catch( Exception $Ex ){
            return false;
        }
    }
    
    
    
    /**
     * @return bool
     */
    public function stop(){
        if( ! $this->status() ){
            trigger_error(sprintf('varnish host already stopped on %s:%s', $this->host, $this->port), E_USER_NOTICE);
            return true;
        }
        $this->command( 'stop', $code );
        return true;
    }    
    
    
    
    /**
     * @return bool
     */
    public function start(){
        if( $this->status() ){
            trigger_error(sprintf('varnish host already started on %s:%s', $this->host, $this->port), E_USER_NOTICE);
            return true;
        }
        $this->command( 'start', $code );
        return true;
    }
    
    
    
    
}
