<?php
namespace SeanMorris\SubSpace;
class Socket
{
	const
		MAX              = 1
		, FREQUENCY      = 120
		, MESSAGE_TYPES  = [
			'continuous' => 0
			, 'text'     => 1
			, 'binary'   => 2
			, 'close'    => 8
			, 'ping'     => 9
			, 'pong'     => 10
		];

	public function __construct()
	{
		$keyFile    = '/etc/letsencrypt/live/example.com/privkey.pem';
		$chainFile  = '/etc/letsencrypt/live/example.com/chain.pem';

		$keyFile    = '/home/sean/ssl_test/privkey.pem';
		$chainFile  = '/home/sean/ssl_test/chain.pem';
		$passphrase = '';
		$address    = '0.0.0.0:9998';

		$context = stream_context_create([]);
		// $context = stream_context_create([
		// 	'ssl'=>[
		// 		'local_cert'    => $chainFile
		// 		, 'local_pk'    => $keyFile
		// 		, 'passphrase'  => $passphrase
		// 		, 'verify_peer' => FALSE
		// 	]
		// ]);

		$this->socket = stream_socket_server(
			$address
			, $errorNumber
			, $errorString
			, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN
			, $context
		);

		$this->clients = [];
	}

	public function tick()
	{
		if($newStream = stream_socket_accept($this->socket, 0))
		{
			stream_set_blocking($newStream, TRUE);

			// stream_socket_enable_crypto(
			// 	$newStream
			// 	, TRUE
			// 	, STREAM_CRYPTO_METHOD_SSLv23_SERVER
			// );

			$incomingHeaders = fread($newStream, 2**16);

			if(preg_match('#^Sec-WebSocket-Key: (\S+)#mi', $incomingHeaders, $match))
			{
				stream_set_blocking($newStream, FALSE);

				fwrite(
					$newStream
					, "HTTP/1.1 101 Switching Protocols\r\n"
						. "Upgrade: websocket\r\n"
						. "Connection: Upgrade\r\n"
						. "Sec-WebSocket-Accept: " . base64_encode(
							sha1(
								$match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
								, TRUE
							)
						)
						. "\r\n\r\n"
				);

				$this->clients[] = $newStream;

				$this->onConnect($newStream, count($this->clients) - 1);
			}
			else
			{
				stream_socket_shutdown($newStream, STREAM_SHUT_RDWR);
			}
		}

		// Get data from clients

		$messages = [];

		foreach($this->clients as $i => $client)
		{
			if(!$client)
			{
				continue;
			}

			while($message = fread($client, 2**16))
			{
				$type = $this->dataType($message);

				switch($type)
				{
					case(static::MESSAGE_TYPES['text']):
					case(static::MESSAGE_TYPES['binary']):
						$this->onReceive($message, $client, $i);
						break;
					case(static::MESSAGE_TYPES['close']):
						if($client)
						{
							$this->onDisconnect($client, $i);

							unset( $this->clients[$i] );

							fclose($client);

							return FALSE;
						}
						break;
					// case(static::MESSAGE_TYPES['ping']):
					// case(static::MESSAGE_TYPES['pong']):
						break;
				}
			}
		}

		return;
	}

	public function send($message, $client, $typeByte = 0x1)
	{
		// Send data to clients
		// fwrite(STDERR, 'Sending ' . $message);

		$length   = strlen($message);

		$typeByte += 128;

		if($length < 126)
		{
			$encoded = pack('CC', $typeByte, $length) . $message;
		}
		else if($length < 65536)
		{
			$encoded = pack('CCn', $typeByte, 126, $length) . $message;
		}
		else
		{
			$encoded = pack('CCNN', $typeByte, 127, 0, $length) . $message;
		}

		if(get_resource_type($client) == 'stream')
		{
			fwrite($client, $encoded);
		}
	}

	protected function decode($message)
	{
		if($message === FALSE)
		{
			return;
		}

		$type = $this->dataType($message);

		$return = FALSE;

		switch($type)
		{
			case(static::MESSAGE_TYPES['close']):
				break;
			case(static::MESSAGE_TYPES['text']):
			case(static::MESSAGE_TYPES['binary']):
				$length = ord($message[1]) & 127;

				if($length == 126)
				{
					$masks = substr($message, 4, 4);
					$data = substr($message, 8);
				}
				else if($length == 127)
				{
					$masks = substr($message, 10, 4);
					$data = substr($message, 14);
				}
				else
				{
					$masks = substr($message, 2, 4);
					$data = substr($message, 6);
				}

				$return = '';

				for ($i = 0; $i < strlen($data); ++$i)
				{
					$return .= $data[$i] ^ $masks[$i%4];
				}
				break;
			case(static::MESSAGE_TYPES['ping']):
				fwrite(STDERR, 'Received a ping!');
				break;
			case(static::MESSAGE_TYPES['pong']):
				fwrite(STDERR, 'Received a pong!');
				break;
		}

		return $return;
	}

	protected function dataType($message)
	{
		$type = ord($message[0]);

		if($type > 128)
		{
			$type -= 128;
		}

		return $type;
	}

	/***********************************************/

	protected
		$userContext = []
		, $hub       = NULL
		, $agents    = [];

	protected function onConnect($client, $clientIndex)
	{
		// fwrite(STDERR, sprintf("#%d joined.\n", $clientIndex));
	}

	protected function onDisconnect($client, $clientIndex)
	{
		fwrite(STDERR, sprintf("#%d left.\n", $clientIndex));
	}

	protected function onReceive($message, $client, $clientIndex)
	{
		$type     = $this->dataType($message);
		$received = $this->decode($message);
		$response = NULL;

		if(!$this->hub)
		{
			$this->hub = new \SeanMorris\Kalisti\Hub;	
		}

		if(!isset($this->agents[$clientIndex]))
		{
			$this->agents[$clientIndex] = new \SeanMorris\Kalisti\Agent;
		}

		$agent = $this->agents[$clientIndex];

		if(!isset($this->userContext[$clientIndex]))
		{
			$agent->register($this->hub);

			$this->hub->unsubscribe('*', $agent);

			$agent->expose(function($content, $output, $origin, $channel, $originalChannel) use($client){

				if(is_numeric($channel->name) || preg_match('/^\d+-\d+$/', $channel->name))
				{
					$typeByte = static::MESSAGE_TYPES['binary'];
					
					$header = pack(
						'vvv'
						, $origin
							? 1
							: 0
						, $origin
							? $origin->id
							: 0
						, $channel->name
					);

					if(is_numeric($content))
					{
						if(is_int($content))
						{
							$content = pack('l', $content);
						}
						else if(is_float($content))
						{
							$content = pack('e', $content);
						}
					}

					$outgoing = $header . $content;
				}
				else
				{
					$typeByte = static::MESSAGE_TYPES['text'];

					$originType = NULL;

					if(!$origin)
					{
						$originType = 'server';
						$originId   = NULL;
					}
					else if($origin instanceof \SeanMorris\Kalisti\Agent)
					{
						$originType = 'user';
						$originId   = $origin->id;
					}

					$message = [
						'message'  => $content
						, 'origin' => $originType
					];

					if(isset($originId))
					{
						$message['originId'] = $originId;
					}

					if(isset($channel))
					{
						$message['channel'] = $channel->name;

						if(isset($originalChannel) && $channel !== $originalChannel)
						{
							$message['originalChannel'] = $originalChannel;
						}
					}

					$outgoing = json_encode($message);			
				}

				$this->send($outgoing, $client, $typeByte);
			});

			$this->userContext[$clientIndex] = [
				'__clientIndex' => $clientIndex
				, '__client'    => $client
				, '__hub'       => $this->hub
				, '__agent'     => $agent
				, '__authed'    => FALSE
			];
		}

		$response = NULL;

		switch($type)
		{
			case(static::MESSAGE_TYPES['binary']):
				if(isset($this->userContext[$clientIndex])
					&& $this->userContext[$clientIndex]['__authed']
				){
					$channelId = ord($received[0]) + (ord($received[1]) << 8);

					$finalMessage = '';

					for($i = 2; $i < strlen($received); $i++)
					{
						$finalMessage .= $received[$i];
					}

					$this->hub->publish($channelId, $finalMessage, $agent);
				}
				break;
			case(static::MESSAGE_TYPES['text']):
				// fwrite(STDERR, sprintf(
				// 	"%d[%d]: \"%s\"\n"
				// 	, $clientIndex
				// 	, $type
				// 	, $received
				// ));

				$routes  = new EntryRoute;
				$path    = new \SeanMorris\Ids\Path(...preg_split('/\s+/', $received));
				$request = new \SeanMorris\Ids\Request(['path' => $path]);
				$router  = new \SeanMorris\Ids\Router($request, $routes);

				$router->setContext($this->userContext[$clientIndex]);
				$response = $router->route();

				if($response instanceof \SeanMorris\Theme\View)
				{
					$response = (string) $response;
				}
			
				break;
		}

		if(is_integer($response))
		{
			$this->send(
				pack(
					'vvvP'
					, 0
					, 0
					, 0
					, $response
				)
				, $client
				, static::MESSAGE_TYPES['binary']
			);
		}
		else if($response !== NULL)
		{
			$this->send(
				json_encode($response)
				, $client
				, static::MESSAGE_TYPES['text']
			);
		}
	}
}

$errorHandler = set_error_handler(
	function($errCode, $message, $file, $line, $context) use(&$errorHandler) {
		if(substr($message, -9) == 'timed out')
		{
			return;
		}

		// fwrite(STDERR, sprintf(
		// 	"[%d] '%s' in %s:%d\n"
		// 	, $errCode
		// 	, $message
		// 	, $file
		// 	, $line
		// ));

		if($errorHandler)
		{
			$errorHandler($errCode, $message, $file, $line, $context);
		}
	}
);
