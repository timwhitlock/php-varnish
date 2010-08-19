<?php
/**
 * Varnish admin socket for executing varnishadm CLI commands.
 * @see http://varnish-cache.org/wiki/CLI
 * @author Tim Whitlock http://twitter.com/timwhitlock
 * 
 * @todo authentication
 * @todo add all short cut methods to commands listed below
 * @todo sanitise command parameters, such as regexp
 * 
 * Tested with varnish-2.1.3 SVN 5049:5055; 
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
	
	/**
	 * @var resource
	 */
	private $fp;
	
	/**
	 * @param string host name varnishadm is listening on
	 */
	private $host;
	
	/**
	 * @param string port varnishadm is listening on
	 */
	private $port;
	
	
	/**
	 * Constructor
	 */
	function __construct( $host = '127.0.0.1', $port = 6082 ){
		$this->host = $host;
		$this->port = $port;
	}

	
	
	/**
	 * Connect to admin socket
	 * @param int optional timeout in seconds, defaults to 5; used for connect and reads
	 * @return string the banner, in case you're interested
	 */
	function connect( $timeout = 5 ){
		$this->fp = fsockopen( $this->host, $this->port, $errno, $errstr, $timeout );
		if( ! is_resource( $this->fp ) ){
			// error would have been raised already by fsockopen
			throw new Exception( sprintf('Failed to connect to varnishadm on %s:%s', $this->host, $this->port));
		}
		// set socket options
		stream_set_blocking( $this->fp, true );
		stream_set_timeout( $this->fp, $timeout );
		// connecting should give us the varnishadm banner with a 200 code
		$banner = $this->read( $code );
		if( $code !== 200 ){
			throw new Exception( sprintf('Bad response from varnishadm on %s:%s', $this->host, $this->port));
		}
		return $banner;
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
			throw new Exception( sprintf("%s\n - Command responded %d:\n > %s", $cmd, $code, $response) );
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
		unset($this->fp);
	}
	
	
	
	/**
	 * Graceful close, sends quit command
	 * @return void
	 */
	public function quit(){
		$this->command('quit', $code, 500 );
		$this->close();
	}
	
	
	
	/**
	 * Shortcut to purge function
	 * @see http://varnish-cache.org/wiki/Purging
	 * @param string purge expression in form "<field> <operator> <arg> [&& <field> <oper> <arg>]..."
	 * @return string
	 */
	public function purge( $expr ){
		return $this->command( 'purge '.$expr, $code );
	}
	
	
	
	/**
	 * Shortcut to purge.url function
	 * @see http://varnish-cache.org/wiki/Purging
	 * @param string url to purge
	 * @return string
	 */
	public function purge_url( $expr ){
		return $this->command( 'purge.url '.$expr, $code );
	}	
	
	
	
	/**
	 * Shortcut to purge.list function
	 * @todo should we parse the reponse lines?
	 * @return array
	 */
	public function purge_list(){
		$response = $this->command( 'purge.list', $code );
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
