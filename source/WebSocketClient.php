<?php
namespace SeanMorris\SubSpace;

use \SeanMorris\SubSpace\Message;
use \SeanMorris\SubSpace\MessageProducer;

class WebSocketClient
{
	public function __constructor($hostname, $port)
	{
		$server   = 'tcp://' . $hostname . ':' . (int) $port;

		$errno    = 0;
		$error    = '';
		$timeout  = 1;
		$flags    = STREAM_CLIENT_CONNECT;

		$socket = stream_socket_client($server, $errno, $error, $timeout, $flags);

		if(!$socket)
		{
			throw new \Exception(sprintf(
				'Could not connect to %s. Error #%d (%s)'
				, $errno
				, $error
			));
		}

		fwrite($socket
			, "GET / HTTP/1.1"    ."\r\n".
				"Host: " . $hostname ."\r\n".
				"Upgrade: WebSocket"   ."\r\n".
				"Connection: Upgrade"  ."\r\n".
				"Sec-WebSocket-Key: "  . bin2hex(random_bytes(16)) ."\r\n".
				"Sec-WebSocket-Version: 13" ."\r\n"."\r\n"
	    );

	    echo fread($socket, 1024) . "--\n";

	    $producer = new MessageProducer($socket, 0);

	    $authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer->send($authMessage->encode());

	    $read = fread($socket, 1024);

		if($read)
		{
			echo $read . "\n";
		}

		stream_set_blocking($socket, FALSE);
	}
}
