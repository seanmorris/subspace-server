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
		if(!file_exists('/tmp/kallisti/'))
		{
			mkdir('/tmp/kallisti/');
		}
		// $socket = new \SeanMorris\SubSpace\SocketServer;
		$socket = new \SeanMorris\SubSpace\WebSocketServer;

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

		$producer1 = new MessageProducer($socket);

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
						echo "Unexpected error.\n";
					}

					if(is_object($content))
					{
						print_r($content);
					}
					else
					{
						echo $content;
					}

				}
				else if($messsge->type() === 2)
				{
					$bytes = $messsge->content();

					if(ord($bytes[0]) === 0)
					{
						$header  = substr($bytes, 4, 2);
						$content = substr($bytes, 6);

						echo '0x' . implode(NULL, array_map(
							function($x) { return str_pad(ord($x),2,0,STR_PAD_LEFT); }
							, str_split($header))
						);

						echo ' ';
					}
					else if(ord($bytes[0]) === 1)
					{
						$header  = substr($bytes, 1, 4);
						$content = substr($bytes, 6);

						echo '0x' . implode(NULL, array_map(
							function($x) { return str_pad(ord($x),2,0,STR_PAD_LEFT); }
							, str_split($header))
						);

						echo ' ';
					}

					for($i = 0; $i < strlen($content); $i++)
					{
						echo dechex(ord($content[$i]));

						echo ' ';
					}
				}

				echo PHP_EOL;
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

	    $producer1 = new MessageProducer($socket, 0);

	    $authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer1->send($authMessage->encode());

		while($input = fgets(STDIN))
		{
			// $input = trim($input);
			$input = rtrim($input);

			$inputMessage = new \SeanMorris\SubSpace\Message(
				'pub ' . $channel . ' ' . $input
				, 1
			);

			$producer1->send($inputMessage->encode());

			fflush($socket);

			usleep(500);
		}

		// fclose($socket);
	}

	public function klwrite($router)
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

	    $producer1 = new MessageProducer($socket, 0);

	    $authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer1->send($authMessage->encode());

	    $read = fread($socket, 1024);

		if($read)
		{
			echo $read . "\n";
		}

		while($input = fgets(STDIN))
		{
			echo 'write ' . $channel . ' ' . $input . "\n";

			$input = trim($input);

			$inputMessage = new \SeanMorris\SubSpace\Message(
				'write ' . $channel . ' ' . $input
				, 1
			);

			$producer1->send($inputMessage->encode());

			fflush($socket);

			usleep(500);

			// $read = fread($socket, 1024);

			// if($read)
			// {
			// 	echo $read . "\n";
			// }

		}
	}

	public function klread($router)
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

	    $producer1 = new MessageProducer($socket, 0);

	    $authMessage = new \SeanMorris\SubSpace\Message('auth ' . (string) new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => -1
		]), 1 );

		$producer1->send($authMessage->encode());

	    $read = fread($socket, 1024);

		if($read)
		{
			echo $read . "\n";
		}

		stream_set_blocking($socket, FALSE);

		while($input = fgets(STDIN))
		{
			echo 'read ' . $channel . "\n";

			$input = trim($input);

			$inputMessage = new \SeanMorris\SubSpace\Message(
				'read ' . $channel
				, 1
			);

			$producer1->send($inputMessage->encode());

			fflush($socket);

			usleep(500);

			$read = fread($socket, 1024);

			if($read)
			{
				echo $read . "\n";
			}
		}
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
