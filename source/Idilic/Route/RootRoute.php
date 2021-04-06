<?php
namespace SeanMorris\SubSpace\Idilic\Route;

use \MultipleIterator, \NoRewindIterator, \RecursiveIteratorIterator;
use \SeanMorris\SubSpace\MessageProducer;

class RootRoute implements \SeanMorris\Ids\Routable
{
	const INTERVAL = FALSE;//1000 * 250;

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
			static::INTERVAL && usleep(static::INTERVAL);

			$socket->tick();
		}
	}

	public function klmpd($router)
	{
		$defaults  = ['localhost:9998', 0];

		$args      = $router->path()->consumeNodes();

		[$authority, $channel] = $args + $defaults;

		$address = 'tcp://' . $authority;
		$timeout = 1;
		$errno   = 0;
		$error   = '';
		$flags   = STREAM_CLIENT_CONNECT;

		$socket = stream_socket_client(
			$address
			, $errno
			, $error
			, $timeout
			, $flags
		);

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

		fwrite($socket, implode("\r\n", [
			'GET / HTTP/1.1'
			, 'Host: ' . $authority
			, 'Upgrade: WebSocket'
			, 'Connection: Upgrade'
			, 'Sec-WebSocket-Key: '  . bin2hex(random_bytes(16))
			, 'Sec-WebSocket-Version: 13'
		]) . "\r\n");

		while($connectResponse = fread($socket, 2048))
		{
			if($connectResponse)
			{
				fwrite(STDERR, $connectResponse);
				break;
			}
		}

		$send = new \SeanMorris\SubSpace\Message();

		fwrite($socket, $send->encode('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		])));

		stream_set_blocking($socket, FALSE);

		$producer1 = new MessageProducer($socket);
		$producer1->subscribe(1);

		$multiple  = new MultipleIterator(MultipleIterator::MIT_NEED_ANY | MultipleIterator::MIT_KEYS_NUMERIC);

		$multiple->attachIterator($producer1);

		foreach($multiple as $key => $messages)
		{
			if($key === NULL)
			{
				continue;
			}

			if(!$messages = array_filter($messages))
			{
				continue;
			}

			var_dump($messages);
		}
	}

	public function klpub($router)
	{
		$args     = $router->path()->consumeNodes();
		$defaults = ['localhost:9998', 0];

		[$hostname, $channel] = $args + $defaults;

		$server   = 'tcp://' . $hostname;
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
			fwrite($socket, $send->encode(implode(' ', ['pub', $channel, $input])));
		}

		fclose($socket);
	}

	public function klsub($router)
	{
		$args     = $router->path()->consumeNodes();
		$defaults = ['localhost:9998', 0];

		[$hostname, $channel] = $args + $defaults;

		$address  = 'tcp://' . $hostname;
		$errno    = 0;
		$error    = '';
		$timeout  = 1;
		$flags    = STREAM_CLIENT_CONNECT;

		$socket = stream_socket_client($address, $errno, $error, $timeout, $flags);

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

		fwrite(
			$socket
			, implode("\r\n", [
				'GET / HTTP/1.1'
				, 'Host: ' . $hostname
				, 'Upgrade: WebSocket'
				, 'Connection: Upgrade'
				, 'Sec-WebSocket-Key: '  . bin2hex(random_bytes(16))
				, 'Sec-WebSocket-Version: 13'
			]) . "\r\n"
		);

	    $headers = fread($socket, 1024);

	    $send = new \SeanMorris\SubSpace\Message();

		fwrite($socket, $send->encode('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		])));

		$authResponse = fread($socket, 2048);

		fwrite(STDERR, $authResponse);

		$recv = new \SeanMorris\SubSpace\Message();

		$send = new \SeanMorris\SubSpace\Message();
		fwrite($socket, $send->encode('sub ' . $channel));

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
