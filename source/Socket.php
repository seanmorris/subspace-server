<?php
namespace SeanMorris\SubSpace;
class Socket
{
	protected $socket, $clients, $partials, $types, $multiframes, $leftovers;
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
		// $this->hub = new \SeanMorris\Kallisti\Hub;
		// $this->localAgent = new \SeanMorris\Kallisti\Agent;
		// $this->localAgent->register($this->hub);

		// $keyFile    = '/etc/letsencrypt/live/example.com/privkey.pem';
		// $chainFile  = '/etc/letsencrypt/live/example.com/chain.pem';

		$socketSettings = \SeanMorris\Ids\Settings::read('websocket');

		$passphrase = NULL;
		$certFile   = NULL;
		$keyFile    = NULL;
		$keyPath    = NULL;
		$address    = '0.0.0.0:9998';

		if($socketSettings)
		{
			$address    = $socketSettings->listen ?? NULL;
			$keyPath    = IDS_ROOT . '/data/local/certbot/';

			$passphrase = $socketSettings->passphrase ?? NULL;
			$certFile   = $socketSettings->certFile   ?? NULL;
			$keyFile    = $socketSettings->keyFile    ?? NULL;
		}

		$contextOptions = [];

		if($keyFile && $certFile)
		{
			// $contextOptions = [
			// 	'ssl'=>[
			// 		'local_cert'    => $keyPath . $certFile
			// 		, 'local_pk'    => $keyPath . $keyFile
			// 		, 'passphrase'  => $passphrase
			// 		, 'verify_peer' => FALSE
			// 	]
			// ];
		}

		\SeanMorris\Ids\Log::error($contextOptions);

		$context = stream_context_create($contextOptions);

		fwrite(STDERR, sprintf(
			'Attempting to listen on "%s"...' . PHP_EOL
			, $address
		));

		$this->socket = stream_socket_server(
			$address
			, $errorNumber
			, $errorString
			, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN
			, $context
		);

		$this->clients     = [];
		$this->partials    = [];
		$this->types       = [];
		$this->multiframes = [];
		$this->leftovers   = [];
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

		foreach($this->clients as $i => $client)
		{
			if(!$client)
			{
				continue;
			}

			$decoded = '';

			while(($rawBytes = fread($client, 2**16)) || isset($this->leftovers[$i]))
			{
				if(isset($this->leftovers[$i]))
				{
					$rawBytes = $this->leftovers[$i] . $rawBytes;
					$this->leftovers[$i] = NULL;
				}

				while($rawBytes)
				{
					\SeanMorris\Ids\Log::debug(sprintf('Got message from %d.', $i));
					\SeanMorris\Ids\Log::debug($rawBytes);

					if(isset($this->partials[$i]))
					{
						\SeanMorris\Ids\Log::debug('Resuming deferred message...');

						list($type, $data, $masks, $fin, $length) = $this->partials[$i];

						$remaining = $length - strlen($data);

						$append   = substr($rawBytes, 0, $remaining);
						$rawBytes = substr($rawBytes, $remaining);

						$data .= $append;

						$remaining = $length - strlen($data);

						\SeanMorris\Ids\Log::debug(sprintf(
							'Appending %d bytes, %d/%d remaining...'
							, strlen($append)
							, $remaining
							, $length
						));

						if(isset($this->multiframes[$i]))
						{
							$decoded = $this->multiframes[$i];
						}

						if($remaining <= 0)
						{
							\SeanMorris\Ids\Log::debug(sprintf(
								'Decoding...'
							));

							for ($ii = 0; $ii < $length; ++$ii)
							{
								if(!isset($data[$ii]))
								{
									\SeanMorris\Ids\Log::debug(sprintf(
										'Deferring...'
									));
									$this->partials[$i] = [
										$type, $data, $masks, $fin, $length
									];
									return FALSE;
								}

								$decoded .= $data[$ii] ^ $masks[$ii%4];
							}

							$this->partials[$i] = NULL;

							if(!$fin && $rawBytes)
							{
								if(!isset($this->multiframes[$i]))
								{
									$this->multiframes[$i] = '';
								}

								$this->multiframes[$i] = $decoded;

								if($rawBytes)
								{
									$this->leftovers[$i] = $rawBytes;
									continue 3;
								}
								\SeanMorris\Ids\Log::debug(sprintf(
									'Waiting for next few bytes...'
								));
								continue 3;
							}

							\SeanMorris\Ids\Log::debug(sprintf(
								'Decoding complete. Got %d bytes.'
								, strlen($decoded)
							), $this->types[$i]);

							$this->onReceive(
								$this->types[$i]
								, $decoded
								, $client
								, $i
							);

							$this->multiframes[$i] = $decoded = '';
						}
						else
						{
							$this->partials[$i] = [
								$type, $data, $masks, $fin, $length
							];
						}

						return FALSE;
					}

					$fin  = $this->fin($rawBytes);
					$type = $this->dataType($rawBytes);

					if($type)
					{
						$this->types[$i] = $type;
					}

					\SeanMorris\Ids\Log::debug('Type', $type);

					switch($type)
					{
						case(static::MESSAGE_TYPES['ping']):
							fwrite(STDERR, 'Received a ping!');
							break;
						case(static::MESSAGE_TYPES['pong']):
							fwrite(STDERR, 'Received a pong!');
							break;
						case(static::MESSAGE_TYPES['continuous']):
							if(isset($this->multiframes[$i]))
							{
								$decoded = $this->multiframes[$i];
							}
						case(static::MESSAGE_TYPES['text']):
						case(static::MESSAGE_TYPES['binary']):
							$length = ord($rawBytes[1]) & 127;

							if($length == 126)
							{
								$length = unpack('n', substr($rawBytes, 2, 2))[1];
								$masks = substr($rawBytes, 4, 4);
								$data = substr($rawBytes, 8);
								\SeanMorris\Ids\Log::debug(sprintf(
									'Message length %d, bytes got %d.'
									, $length
									, strlen($data)
								));
							}
							else if($length == 127)
							{
								$length = unpack('J', substr($rawBytes, 2, 8))[1];
								$masks = substr($rawBytes, 10, 4);
								$data = substr($rawBytes, 14);
								\SeanMorris\Ids\Log::debug(sprintf(
									'Message length %d, bytes got %d.'
									, $length
									, strlen($data)
								));
							}
							else
							{
								$masks = substr($rawBytes, 2, 4);
								$data = substr($rawBytes, 6);
								\SeanMorris\Ids\Log::debug(sprintf(
									'Message length %d, bytes got %d.'
									, $length
									, strlen($data)
								));
							}

							for ($ii = 0; $ii < $length; ++$ii)
							{
								if(!isset($data[$ii]))
								{
									\SeanMorris\Ids\Log::debug(sprintf(
										'Deferring...'
									));
									$this->partials[$i] = [
										$type, $data, $masks, $fin, $length
									];
									return FALSE;
								}

								$decoded .= $data[$ii] ^ $masks[$ii%4];
							}

							\SeanMorris\Ids\Log::debug($decoded);

							if(!$fin)
							{
								if(!isset($this->multiframes[$i]))
								{
									$this->multiframes[$i] = '';
								}

								$this->multiframes[$i] .= $decoded;
								continue 4;
							}

							$this->onReceive($type, $decoded, $client, $i);

							$this->partials[$i] = NULL;

							$this->multiframes[$i] = $decoded = '';

							if($rawBytes = substr($data, $length))
							{
								$this->leftovers[$i] = $rawBytes;
								continue 4;
							}

							break;
						case(static::MESSAGE_TYPES['close']):
							if($client)
							{
								$this->onDisconnect($client, $i);

								unset( $this->clients[$i] );

								fclose($client);

							}
							return FALSE;
							break;
						default:
							\SeanMorris\Ids\Log::debug('Rejecting...');
							if($client)
							{
								$this->onDisconnect($client, $i);

								unset( $this->clients[$i] );

								fclose($client);
							}

							return FALSE;
							break;
					}

				}
			}
		}

		if(!$this->hub)
		{
			$this->hub = new \SeanMorris\SubSpace\Kallisti\Hub;
		}

		$this->hub->tick();

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
			stream_set_blocking($client, TRUE);

			fwrite($client, $encoded);

			stream_set_blocking($client, FALSE);
		}
	}

	protected function fin($message)
	{
		$type = ord($message[0]);

		if($type >= 128)
		{
			return true;
		}
	}

	protected function dataType($message)
	{
		$type = ord($message[0]);

		if($type >= 128)
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

	protected function onReceive($type, $message, $client, $clientIndex)
	{
		$response = NULL;

		if(!$this->hub)
		{
			$this->hub = new \SeanMorris\SubSpace\Kallisti\Hub;
		}

		if(!isset($this->agents[$clientIndex]))
		{
			$this->agents[$clientIndex] = new \SeanMorris\Kallisti\Agent;
		}

		$agent = $this->agents[$clientIndex];

		if(!isset($this->userContext[$clientIndex]))
		{
			$agent->register($this->hub);

			$this->hub->unsubscribe('*', $agent);

			$agent->expose(function($content, $output, $origin, $channel, $originalChannel, $cc = NULL) use($client){

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
					else if($origin instanceof \SeanMorris\Kallisti\Agent)
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

					if(isset($cc))
					{
						$message['cc'] = $cc;
					}

					$outgoing = json_encode($message);
				}

				$this->send($outgoing, $client, $typeByte);
			});

			$this->userContext[$clientIndex] = [
				'__client'   => $client
				, '__hub'    => $this->hub
				, '__agent'  => $agent
				, '__authed' => FALSE
				, '__remote' => stream_socket_get_name($client, TRUE)
				, '__uniqid' => uniqid()
			];
		}

		$response = NULL;

		switch($type)
		{
			case(static::MESSAGE_TYPES['binary']):
				if(isset($this->userContext[$clientIndex])
					&& $this->userContext[$clientIndex]['__authed']
				){
					$channelId = ord($message[0]) + (ord($message[1]) << 8);

					$finalMessage = '';

					for($i = 2; $i < strlen($message); $i++)
					{
						$finalMessage .= $message[$i];
					}

					$this->hub->publish($channelId, $finalMessage, $agent);
				}
				break;
			case(static::MESSAGE_TYPES['text']):
				fwrite(STDERR, sprintf(
					">> %d[%d]: \"%s\"\n"
					, $clientIndex
					, $type
					, $message
				));

				$entryRoute = 'SeanMorris\SubSpace\EntryRoute';

				if($configRoute = \SeanMorris\Ids\Settings::read('socketEntryPoint'))
				{
					$entryRoute = $configRoute;
				}

				$routes  = new $entryRoute;
				$path    = new \SeanMorris\Ids\Path(...explode(' ', $message));
				$request = new \SeanMorris\Ids\Request(['path' => $path]);
				$router  = new \SeanMorris\Ids\Router($request, $routes);

				$router->setContext($this->userContext[$clientIndex]);
				$response = $router->route();

				if($response instanceof \SeanMorris\Theme\View)
				{
					$response = (string) $response;
				}

				fwrite(STDERR, sprintf(
					"<< %d[%d]: \"%s\"\n"
					, $clientIndex
					, $type
					, print_r($response, 1)
				));

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
