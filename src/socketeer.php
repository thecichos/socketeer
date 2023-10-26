<?php

/**
 * @license GPL-3.0+
 *
 */

namespace Thecichos\Socketeer;

abstract class socketeer
{

	##########################
	#    Abstract methods    #
	##########################

	/**
	 * This method is called whenever a socket connects
	 *
	 * @return bool
	 */
    abstract protected function connect_socket($socket) : bool;

	/**
	 * This method is called whenever a socket is receiving data
	 *
	 * @param String $socketData
	 * @param $socketResource
	 * @return bool
	 */
    abstract protected function socket_receive(string $socketData, $socketResource) : bool;

	/**
	 * This method is called whenever a socket is disconnecting
	 *
	 * @return bool
	 */
    abstract protected function on_socket_disconnect() : bool;

	/**
	 * This method is called in the while loop in the start method
	 *
	 * @return void
	 */
    abstract protected function cycle_check() : void;

	/**
	 * This method must return whether or not the socket is running or not<br>If this returns false the socket exits neatly
	 *
	 * @return bool
	 */
    abstract protected function is_alive() : bool;

	/**
	 * This method will be called at the end of the socket lifetime to clean
	 *
	 * @return void
	 */
    abstract protected function cleanup() : void;

	/**
	 * This method must return the hostname of the socket
	 *
	 * @return string
	 */
    abstract protected function get_host_name(): string;

	/**
	 * The main socket
	 */
    protected		 	$socket;

	/**
	 * The port the socket communicates on
	 *
	 * @var int 
	 */
    protected int 		$port;
	
	/**
	 * The log-level
	 *
	 * @var int 
	 */
    protected int 		$log;
	
	/**
	 * @var string 
	 */
    protected string 	$handle;
	
	/**
	 * A list of sockets, updated by connect_socket and disconnect_socket
	 *
	 * @var array 
	 */
    protected array 	$sockets = [];
	
	/**
	 * @var array 
	 */
    protected array 	$newSockets = [];

    protected function log(string $string, $intCaller)
    {

		if (!in_array($intCaller, [1, 2])) {
			$strMessage = "Log was not given a valid log level and could therefore not write to log\n\n";
			$strMessage .= var_export(debug_backtrace(), true);
			error_log($strMessage);
			return;
		}

		$boolLog =
			(
				$this->log === 3
				&& (
					$intCaller === 2 || $intCaller === 1
				)
			)
			|| (
				$this->log === 2 && $intCaller === 2
			) || (
				$this->log === 1 && $intCaller === 1
			);

        if ($this->log !== 0 && $boolLog) {
            file_put_contents("socket_" . $this->handle . ".log", strtotime("now") . " ::: " . $string . "\n", FILE_APPEND);
        }
    }

	private function is_socket_to_be_disconnected($socket) : bool
	{
		$socketData = @socket_read($socket, 1024, PHP_NORMAL_READ);
		if (socket_last_error($socket)) {
			$this->log($this->handle . " met error: " . socket_last_error($socket) . " " . socket_last_error($socket), 1);
		}
		return $socketData === false;
	}

	/**
	 * @param string $handle The name of the socket, this is used for the logging and can be used by other implementation things
	 * @param int $port The port the socket communicates on
	 * @param int $log The loglevel<hr>0 (default) => No loggin<br>1 => Only abstract class<br>2 => Only implementation<br>3 => All
	 */
    public function __construct(string $handle, int $port, int $log = 0)
    {
        $this->handle = $handle;
        $this->port = $port;
        $this->log = $log;

        $this->log("Starting server = " . $handle, 1);

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (socket_last_error($this->socket))
            $this->log($handle . " met error: " . socket_last_error($this->socket) . " " . socket_last_error($this->socket), 1);

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (socket_last_error($this->socket))
            $this->log($handle . " met error: " . socket_last_error($this->socket) . " " . socket_last_error($this->socket), 1);

        socket_bind($this->socket, $this->get_host_name(), $this->port);
        if (socket_last_error($this->socket))
            $this->log($handle . " met error: " . socket_last_error($this->socket) . " " . socket_last_error($this->socket), 1);

        socket_listen($this->socket);
        if (socket_last_error($this->socket))
            $this->log($handle . " met error: " . socket_last_error($this->socket) . " " . socket_last_error($this->socket), 1);

        $this->log($handle . " settings set", 1);

        $this->start();
    }

    private function start()
    {
        $this->log($this->handle . " started", 1);

        $this->sockets = [$this->socket];
        while ($this->is_alive()) {

			$this->cycle_check();

            $this->newSockets = $this->sockets;

            socket_select($this->newSockets, $null, $null, 0, 10);

            if (in_array($this->socket, $this->newSockets)) {
				$newSocket = socket_accept($this->socket);
				$this->sockets[] = $newSocket;

				$header = socket_read($newSocket, 1024);
				$this->do_handshake($header, $newSocket, $this::get_host_name());

				socket_getpeername($newSocket, $client_ip_address);

				$newSocketIndex = array_search($this->socket, $this->newSockets);

				unset($this->newSockets[$newSocketIndex]);
                $this->connect_socket($newSocket);
            }

            foreach ($this->newSockets as $newSocketResource) {
                while (@socket_recv($newSocketResource, $socketData, 1024, 0) >= 1) {
                    $this->log("receive data", 1);
                    $this->socket_receive($socketData, $newSocketResource);
                    break 2;
                }

				if (self::is_socket_to_be_disconnected($newSocketResource)) {
					$this->disconnect_single_socket($newSocketResource);
					$this->on_socket_disconnect();
				}
            }
        }
        socket_close($this->socket);

        $this->cleanup();

        $this->log($this->handle . " shut down successfully\n", 1);
    }

	/**
	 * This method is to be used whenever the a new socket connects to establish a connection
	 *
	 * @param $received_header
	 * @param $client_socket_resource
	 * @param $host_name
	 * @return void
	 */
    protected function do_handshake($received_header, $client_socket_resource, $host_name)
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $received_header);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $buffer = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host_name\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        socket_write($client_socket_resource, $buffer, strlen($buffer));
    }

	/**
	 * Unpacks a message from the client
	 *
	 * @param $socketData
	 * @return string
	 */
    protected function unseal($socketData) : string
    {
        $length = ord($socketData[1]) & 127;
        if ($length == 126) {
            $masks = substr($socketData, 4, 4);
            $data = substr($socketData, 8);
        } elseif ($length == 127) {
            $masks = substr($socketData, 10, 4);
            $data = substr($socketData, 14);
        } else {
            $masks = substr($socketData, 2, 4);
            $data = substr($socketData, 6);
        }
        $socketData = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $socketData .= $data[$i] ^ $masks[$i % 4];
        }
        return $socketData;
    }

	/**
	 * Pack a message for the client
	 *
	 * @param $socketData
	 * @return string
	 */
    protected function seal($socketData) : string
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($socketData);

        if ($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header . $socketData;
    }

	protected function disconnect_single_socket($socket) : bool
	{
		@socket_getpeername($socket, $client_ip_address);
		$newSocketIndex = array_search($socket, $this->sockets);
		unset($this->sockets[$newSocketIndex]);
		return true;
	}

	protected function write_to_single_socket($socket, string $text) : bool
	{
		$strMessage = $this->seal($text);
		$intLength = strlen($strMessage);
		@socket_write($socket, $strMessage, $intLength);
		return true;
	}

	protected function write_to_sockets(array $sockets, string $text) : bool
	{
		foreach($sockets as $socket) {
			$this->write_to_single_socket($socket, $text);
		}
		return true;
	}


}