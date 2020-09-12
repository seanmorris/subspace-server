<?php
namespace SeanMorris\SubSpace\Idilic\Route;
class RootRoute implements \SeanMorris\Ids\Routable
{
	const FREQUENCY = 120;

	public function compa($router)
	{
		$args = $router->path()->consumeNodes();

		var_dump(\SeanMorris\Kallisti\Channel::compareNames(...$args));
	}

	public function server()
	{
		$socket = new \SeanMorris\SubSpace\Socket;

		while(true)
		{
			usleep( 1000000 / static::FREQUENCY );

			$socket->tick();
		}
	}

	public function kpub($router)
	{
		$hostname = 'localhost:9998';

		$server  = 'tcp://' . $hostname;

		$errno   = 0;
		$error   = '';
		$timeout = 1;
		$flags   = STREAM_CLIENT_CONNECT;

		$socket = stream_socket_client($server, $errno, $error, $timeout, $flags);

		if(!$socket)
		{
			throw new \Exception(sprintf(
				'Could not connect to %s. Error #%d (%s)'
				, $errno
				, $error
			));
		}

		stream_set_write_buffer($socket, 0);
		stream_set_read_buffer($socket, 0);

		fwrite($socket
			, "GET / HTTP/1.1"    ."\r\n".
				"Host: " . $hostname ."\r\n".
				"Upgrade: WebSocket"   ."\r\n".
				"Connection: Upgrade"  ."\r\n".
				"Sec-WebSocket-Key: "  . bin2hex(random_bytes(16)) ."\r\n".
				"Sec-WebSocket-Version: 13" ."\r\n"."\r\n"
	    );

	    echo fread($socket, 1024);

		$send = new \SeanMorris\SubSpace\Message();

		fwrite($socket, $send->encode('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		])));

		$recv = new \SeanMorris\SubSpace\Message();

		while($input = fgets(STDIN))
		{
			$send = new \SeanMorris\SubSpace\Message();
			fwrite($socket, $send->encode('pub 0 ' . $input));
		}

		fclose($socket);
	}

	public function ksub()
	{
		$hostname = 'localhost:9998';

		$server  = 'tcp://' . $hostname;

		$errno   = 0;
		$error   = '';
		$timeout = 1;
		$flags   = STREAM_CLIENT_CONNECT;

		$socket = stream_socket_client($server, $errno, $error, $timeout, $flags);

		if(!$socket)
		{
			throw new \Exception(sprintf(
				'Could not connect to %s. Error #%d (%s)'
				, $errno
				, $error
			));
		}

		stream_set_write_buffer($socket, 0);
		stream_set_read_buffer($socket, 0);

		fwrite($socket
			, "GET / HTTP/1.1"    ."\r\n".
				"Host: " . $hostname ."\r\n".
				"Upgrade: WebSocket"   ."\r\n".
				"Connection: Upgrade"  ."\r\n".
				"Sec-WebSocket-Key: "  . bin2hex(random_bytes(16)) ."\r\n".
				"Sec-WebSocket-Version: 13" ."\r\n"."\r\n"
	    );

	    echo fread($socket, 1024);

	    $send = new \SeanMorris\SubSpace\Message();

		fwrite($socket, $send->encode('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		])));

		$recv = new \SeanMorris\SubSpace\Message();

		$send = new \SeanMorris\SubSpace\Message();
		fwrite($socket, $send->encode('sub 0'));

		while (!feof($socket))
		{
			$chunk = fread($socket, 256);

			if(!strlen($chunk))
			{
				continue;
			}

			$recv->decode($chunk);

			if($recv->isDone())
			{
				print $recv->content();
				$recv = new \SeanMorris\SubSpace\Message();
			}
		}
	}
}
