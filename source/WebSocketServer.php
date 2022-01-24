<?php
namespace SeanMorris\SubSpace;

use \SeanMorris\SubSpace\Message;
use \SeanMorris\SubSpace\MessageProducer;

use \SeanMorris\SubSpace\Kallisti\Hub;

class WebSocketServer
{
	protected static
		$Producer  = MessageProducer::Class
		, $Message = Message::Class
		, $Hub     = Hub::Class
	;

	protected
		$socket
		, $partials
		, $intervalId  = 0
		, $lastTick    = 0
		, $lastBeat    = 0
		, $index       = 0
		, $producers   = []
		, $hanging     = []
		, $processes   = []
		, $sockets     = []
		, $userContext = []
		, $crypto      = false
		, $agents      = []
		, $hub         = NULL
		, $threaded    = false
		, $deepSleep   = false
		, $dozeCount   = 0
	;

	public function __construct($options = [])
	{
		$options = (object) $options;

		$this->hub = new static::$Hub;

		$this->settings = \SeanMorris\Ids\Settings::read('subspace');

		$keyPath    = NULL;
		$passphrase = NULL;
		$certFile   = NULL;
		$keyFile    = NULL;

		$address = $options->address
			?? $this->settings->address
			?:'localhost:9998';

		$threaded = $options->threaded
			?? $this->settings->threaded
			?: false;

		if($this->settings)
		{
			$address = $this->settings->address ?? $address;

			$keyPath    = $this->settings->keyPath    ?: NULL;
			$passphrase = $this->settings->passphrase ?: NULL;
			$certFile   = $this->settings->certFile   ?: NULL;
			$keyFile    = $this->settings->keyFile    ?: NULL;
		}

		$contextOptions = [];

		if($keyFile && $certFile)
		{
			$contextOptions = [
				'ssl'=>[
					'local_cert'    => $keyPath . $certFile
					, 'local_pk'    => $keyPath . $keyFile
					, 'passphrase'  => $passphrase
					, 'verify_peer' => FALSE
				]
			];

			$this->crypto = TRUE;
		}

		$context = stream_context_create($contextOptions);

		\SeanMorris\Ids\Log::debug(sprintf(
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
	}

	public function tick()
	{
		$now = 1000 * microtime(true);

		$this->checkForNewClients();

		$minTickInterval  = $this->settings->throttle ?? 0;
		$lastTickInterval = round(($now - $this->lastTick) / 1000);
		$lastBeatInterval = round(($now - $this->lastBeat));

		if($this->settings->throttle && $minTickInterval > $lastTickInterval)
		{
			usleep($minTickInterval - $lastTickInterval);
		}

		if(!$this->sockets && $this->settings->sleep)
		{
			$sleepTime = $this->settings->sleep;

			if($this->settings->doze && $this->dozeCount > $this->settings->doze)
			{
				$sleepTime = $this->settings->deepSleep;
			}

			\SeanMorris\Ids\Log::info(
				sprintf(
					'[%07d] No clients connected. Sleeping for %dms'
					, $this->intervalId
					, $sleepTime
				)
			);

			usleep(1000 * $sleepTime);

			$this->dozeCount++;
			$this->intervalId++;
			return;
		}

		$later = 1000 * microtime(true);

		if($this->sockets)
		{
			$sockets = $this->sockets;
			$empty = [];

			stream_select($sockets, $empty, $empty, 0);

			$sockets = array_values($sockets);

			$dead = [];

			$socketCount = count($sockets);

			foreach($this->hanging as $socketId => $producer)
			{
				$this->checkProducer($producer, $socketId);

				if($producer->done())
				{
					$dead[] = $producer;
				}
			}

			foreach($sockets as $socket)
			{
				$socketId = (int) $socket;

				$producer = $this->producers[ $socketId ];

				$this->checkProducer($producer, $socketId);

				if($producer->done())
				{
					$dead[] = $producer;
				}
			}

			$timedout = [];

			if($this->settings->netTimeout || $this->settings->idleTimeout)
			{
				foreach($this->sockets as $socket)
				{
					$socketId = (int) $socket;

					$producer = $this->producers[ $socketId ];

					$idleTimeout = $this->settings->idleTimeout ?? 0;
					$netTimeout  = $this->settings->netTimeout  ?? 0;

					$idleNetTime = $now + -$producer->lastNetwork();
					$idleTime    = $now + -$producer->lastActive();
					$pingTime    = $this->settings->netTimeout + -250;

					if($netTimeout && $idleNetTime >= $netTimeout)
					{
						\SeanMorris\Ids\Log::info(
							sprintf(
								'[%07d] client id %d timed out due to %dms of inactivity.'
								, $this->intervalId
								, $socketId
								, $idleTime
							)
						);

						$timedout[] = $producer;

						$producer->send(Message::enc(
							json_encode(['error' => 'Timed out due to lack of network activity.'])
							, Message::TYPE['TEXT']
						));

						$producer->send(Message::enc(
							chr(0x03) . chr(0xE8)
							, Message::TYPE['CLOSE'])
						);
					}

					if($netTimeout && !$producer->wasPinged() && $idleNetTime >= $pingTime)
					{
						$producer->ping();

						\SeanMorris\Ids\Log::debug(
							sprintf(
								'[%07d] client id %d pinged after %dms of inactivity.'
								, $this->intervalId
								, $socketId
								, $idleNetTime
							)
						);
					}

					if($idleTimeout && $idleTime >= $idleTimeout)
					{
						\SeanMorris\Ids\Log::info(
							sprintf(
								'[%07d] client id %d timed out due to %dms of inactivity.'
								, $this->intervalId
								, $socketId
								, $idleNetTime
							)
						);

						$timedout[] = $producer;

						$producer->send(Message::enc(
							json_encode(['error' => 'Timed out due to lack of user activity.'])
							, Message::TYPE['TEXT']
						));

						$producer->send(Message::enc(
							chr(0x03) . chr(0xE8)
							, Message::TYPE['CLOSE'])
						);
					}
				}
			}

			foreach([...$dead, ...$timedout] as $producer)
			{
				$this->onDisconnect($producer);

				$this->cleanupDeadClient($producer);
			}
		}

		$this->hub->tick();

		if($lastBeatInterval >= 15000)
		{
			$clientCount = count($this->sockets);
			\SeanMorris\Ids\Log::info(
				sprintf(
					'[%07d] %d client%s connected, last frame duration: ~%sms'
					, $this->intervalId
					, $clientCount
					, $clientCount === 1 ? '' : 's'
					, number_format(1000 * microtime(true) - $later, 2)
				)
			);


			$this->lastBeat = 1000 * microtime(true);
		}

		$this->dozeCount = 0;
		$this->lastTick  = $now;

		$this->intervalId++;
	}

	protected function checkProducer($producer, $socketId)
	{
		if($message = $producer->check())
		{
			$this->onReceive(
				$message->type()
				, $message->content()
				, $producer
			);
		}

		if($producer->hanging())
		{
			$this->hanging[ $socketId ] = $producer;
		}
		else
		{
			unset($this->hanging[ $socketId ]);
		}
	}

	protected function checkForNewClients()
	{
		$producers = [];

		if($newSocket = stream_socket_accept($this->socket, 0))
		{
			if($this->crypto)
			{
				stream_set_blocking($newSocket, TRUE);
				stream_socket_enable_crypto(
					$newSocket
					, TRUE
					, STREAM_CRYPTO_METHOD_ANY_SERVER
				);
			}

			$incomingHeaders = fread($newSocket, 2**16);

			stream_set_blocking($newSocket, FALSE);

			if(preg_match('#^Sec-WebSocket-Key: (\S+)#mi', $incomingHeaders, $match))
			{
				fwrite(
					$newSocket
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

				++$this->index;

				$clientSocket = $newSocket;

				if($this->threaded)
				{
					$process = new \SeanMorris\SubSpace\ClientProcess($clientSocket);
					$clientSocket = $process->proxy;
					$process->fork();
				}

				$producer = new static::$Producer($clientSocket, $this->index);

				$socketId = $producer->socketId();

				$this->producers[ $socketId ] = $producer;

				$this->sockets[ $socketId ] = $clientSocket;

				if($this->threaded)
				{
					$this->processes[ $socketId ] = $process;
				}

			}
			else
			{
				stream_socket_shutdown($newSocket, STREAM_SHUT_RDWR);
			}

			stream_set_blocking($newSocket, FALSE);
			stream_set_write_buffer($newSocket, 0);
			stream_set_read_buffer($newSocket, 0);
		}

		foreach($producers as $producer)
		{
			$this->onConnect($producer, $producer->id());
		}
	}

	protected function cleanupDeadClient($client)
	{
		$socketId = $client->socketId();

		if(!isset($this->agents[ $socketId ]))
		{
			return;
		}

		$this->hub->unsubscribe('*', $this->agents[ $socketId ]);

		unset(
			$this->sockets[ $socketId ]
			, $this->userContext[ $socketId ]
			, $this->producers[ $socketId ]
			, $this->hanging[ $socketId ]
			, $this->agents[ $socketId ]
		);

		if($this->processes)
		{
			$this->processes[ $socketId ]->done();

			unset($this->processes[ $socketId ]);
		}
	}

	protected function onConnect($client)
	{
		\SeanMorris\Ids\Log::debug(sprintf("#%d joined.\n", $client->id()));
	}

	protected function onDisconnect($client)
	{
		\SeanMorris\Ids\Log::debug(sprintf("#%d left.\n", $client->id()));
	}

	protected function onReceive($type, $message, $client)
	{
		$response = NULL;

		$clientId = $client->id();
		$socketId = $client->socketId();

		if(!isset($this->agents[$socketId]))
		{
			$this->agents[$socketId] = new \SeanMorris\SubSpace\Kallisti\Agent;
		}

		$agent = $this->agents[$socketId];

		if(!isset($this->userContext[$socketId]))
		{
			$agent->register($this->hub);

			$this->hub->unsubscribe('*', $agent);

			$agent->expose(function($content, $output, $origin, $channel, $originalChannel, $cc = NULL) use($client, $clientId){


				if($content === NULL)
				{
					return;
				}

				$syndicated = static::$Message::assemble(
					$origin
					, $channel
					, $content
					, $originalChannel
					, $cc
				);

				\SeanMorris\Ids\Log::debug(sprintf(
					"<< %d: { %s bytes } \n"
					, $clientId
					, strlen($syndicated->encoded())
				));

				if($client->done())
				{
					return;
				}

				try
				{
					$client->send($syndicated->encoded());
				}
				catch (\Exception $exception)
				{
					\SeanMorris\Ids\Log::error($exception->getMessage());
				}
			});

			$this->userContext[$socketId] = [
				'__client'   => $client
				, '__hub'    => $this->hub
				, '__agent'  => $agent
				, '__authed' => FALSE
				, '__remote' => $client->name()
				, '__uniqid' => uniqid()
			];
		}

		$response = NULL;

		switch($type)
		{
			case(static::$Message::TYPE['BINARY']):

				if(isset($this->userContext[$socketId])
					&& $this->userContext[$socketId]['__authed']
				){
					$channelId = ord($message[0]) + (ord($message[1]) << 8);

					$finalMessage = substr($message, 2);

					$this->hub->publish($channelId, $finalMessage, $agent);
				}

				break;

			case(static::$Message::TYPE['TEXT']):

				\SeanMorris\Ids\Log::debug(sprintf(
					">> %d[%d]: \"%s\"\n"
					, $clientId
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

				$router->setContext($this->userContext[$socketId]);
				$response = $router->route();

				if($response instanceof \SeanMorris\Theme\View)
				{
					$response = (string) $response;
				}

				\SeanMorris\Ids\Log::debug(sprintf(
					"<< %d[%d]: \"%s\"\n"
					, $clientId
					, $type
					, print_r($response, 1)
				));

				break;
		}

		$message = new static::$Message;

		if(is_integer($response))
		{
			$encoded = $message->encode(pack('vvvP', 0, 0, 0, $response), static::$Message::TYPE['BINARY']);

			$client->send($encoded);
		}
		else if($response !== NULL)
		{
			$encoded = $message->encode(json_encode($response), static::$Message::TYPE['TEXT']);

			$client->send($encoded);
		}
	}
}

$errorHandler = set_error_handler(
	function($errCode, $message, $file, $line, $context = []) use(&$errorHandler) {
		if(substr($message, -9) == 'timed out' || substr($message, -11) ==  'Broken pipe')
		{
			return;
		}

		\SeanMorris\Ids\Log::error(sprintf(
			"[%d] '%s' in %s:%d\n"
			, $errCode
			, $message
			, $file
			, $line
		));

		if($errorHandler)
		{
			$errorHandler($errCode, $message, $file, $line, $context);
		}
	}
);
