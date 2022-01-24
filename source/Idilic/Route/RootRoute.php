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
		$server = new \SeanMorris\SubSpace\WebSocketServer;

		while(true)
		{
			$server->tick();
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

		$producer1 = new MessageProducer($socket, 0);

		stream_set_blocking($socket, FALSE);

		$authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer1->send($authMessage->encode());

		$motdMessage = new \SeanMorris\SubSpace\Message('motd', 1);

		$producer1->send($motdMessage->encode());

		$motdMessage = new \SeanMorris\SubSpace\Message('random', 1);

		$producer1->send($motdMessage->encode());

		$subMessage = new \SeanMorris\SubSpace\Message('sub ' . $channel, 1);

		$producer1->send($subMessage->encode());

		$subMessage = new \SeanMorris\SubSpace\Message('sub 0', 1);

		$producer1->send($subMessage->encode());

		while($producer1)
		{
			if($messsge = $producer1->check())
			{
				if($messsge->type() === 1)
				{
					try
					{
						$content = json_decode($messsge->content());
					}
					catch(\Exception $e)
					{
						fwrite(STDERR, "Unexpected error.\n");
					}

					if(is_object($content))
					{
						fwrite(STDERR, print_r($content, 1) . "\n");
					}
					else
					{
						fwrite(STDERR, $content);
					}

				}
				else if($messsge->type() === 2)
				{
					$bytes = $messsge->content();

					if(ord($bytes[0]) === 0)
					{
						$header  = substr($bytes, 4, 2);
						$content = substr($bytes, 6);

						fwrite(STDERR, '0x' . implode(NULL, array_map(
							function($x) { return str_pad(ord($x),2,0,STR_PAD_LEFT); }
							, str_split($header)
						)) . ' ');
					}
					else if(ord($bytes[0]) === 1)
					{
						$header  = substr($bytes, 1, 4);
						$content = substr($bytes, 6);

						print $content;

						fwrite(STDERR, '0x' . implode(NULL, array_map(
							function($x) { return str_pad(ord($x),2,0,STR_PAD_LEFT); }
							, str_split($header)
						)) . ' ');
					}

					for($i = 0; $i < strlen($content); $i++)
					{
						fwrite(STDERR, dechex(ord($content[$i])) . ' ');
					}
				}

				fwrite(STDERR, PHP_EOL);
			}
		}

		// $multiple  = new MultipleIterator(MultipleIterator::MIT_NEED_ANY | MultipleIterator::MIT_KEYS_NUMERIC);

		// $multiple->attachIterator($producer1);

		// foreach($multiple as $key => $messages)
		// {
		// 	if($key === NULL)
		// 	{
		// 		continue;
		// 	}

		// 	if(!$messages = array_filter($messages))
		// 	{
		// 		continue;
		// 	}

		// 	var_dump($messages);
		// }
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

		// stream_set_write_buffer($socket, 0);
		// stream_set_read_buffer($socket, 0);

		fwrite($socket
			, "GET / HTTP/1.1"    ."\r\n".
				"Host: " . $hostname ."\r\n".
				"Upgrade: WebSocket"   ."\r\n".
				"Connection: Upgrade"  ."\r\n".
				"Sec-WebSocket-Key: "  . bin2hex(random_bytes(16)) ."\r\n".
				"Sec-WebSocket-Version: 13" ."\r\n"."\r\n"
	    );

	    echo fread($socket, 1024);

	    $producer = new MessageProducer($socket, 0);

	    $authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer->send($authMessage->encode());

		while($input = fgets(STDIN))
		{
			$inputMessage = \SeanMorris\SubSpace\Message::enc(
				chr(0) . chr($channel) . $input, 2
			);

			$producer->send($inputMessage);

			fflush($socket);

			usleep(500);
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

		// stream_set_write_buffer($socket, 0);
		// stream_set_read_buffer($socket, 0);

		$handshakeInit = implode("\r\n", [
			'GET / HTTP/1.1'
			, 'Host: ' . $hostname
			, 'Upgrade: WebSocket'
			, 'Connection: Upgrade'
			, 'Sec-WebSocket-Key: '  . bin2hex(random_bytes(16))
			, 'Sec-WebSocket-Version: 13'
		]) . "\r\n\r\n";

		fwrite(STDERR, 'HANDSHAKE: ' . $handshakeInit);
		fwrite($socket, $handshakeInit);

	    $headers = fread($socket, 1024);

	    fwrite(STDERR, 'HEADERS: ' . $headers);

	    $sendable = new \SeanMorris\SubSpace\Message();

	    $encoded = $sendable->encode('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		])) . "\r\n";

		fwrite(STDERR, 'JWT: ' . $encoded);
		fwrite($socket, $encoded);

		$authResponse = fread($socket, 2048);

		fwrite(STDERR, 'AUTHED? ' . $authResponse);

		$recv = new \SeanMorris\SubSpace\Message();

		$sendable = new \SeanMorris\SubSpace\Message();
		$encoded  = $sendable->encode('sub ' . $channel);

		fwrite(STDERR, 'SUB: ' . $encoded);
		fwrite($socket, $encoded);

		while (!feof($socket))
		{
			$chunk = fread($socket, 256);
			fwrite(STDERR, $chunk);

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

	public function forkTest()
	{
		$clientProcess = new \SeanMorris\SubSpace\ClientProcess(STDIN);

		$clientProcess->fork();

		$wrote = 0;

		while($line = fread($clientProcess->proxy, 32))
		{
			print $line;
		}

		sleep(2);
		$clientProcess->done();
		sleep(2);
		$clientProcess->wait();
	}
}
