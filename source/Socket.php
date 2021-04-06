<?php
namespace SeanMorris\SubSpace;


use \SeanMorris\SubSpace\Message;
use \SeanMorris\SubSpace\MessageProducer;

use \SeanMorris\SubSpace\Kallisti\Hub;

class Socket
{
	protected static
		$Producer  = MessageProducer::Class
		, $Message = Message::Class
		, $Hub     = Hub::Class
	;

	protected
		$socket
		, $partials
		, $index       = 0
		, $producers   = []
		, $userContext = []
		, $crypto      = false
		, $agents      = []
		, $hub         = NULL
	;

	public function __construct()
	{
		$this->hub = new static::$Hub;

		$socketSettings = \SeanMorris\Ids\Settings::read('websocket');

		$passphrase = NULL;
		$certFile   = NULL;
		$keyFile    = NULL;
		$keyPath    = NULL;
		$address    = 'localhost:9998';

		if($socketSettings)
		{
			$address    = $socketSettings->listen ?? $address;
			$keyPath    = IDS_ROOT . '/data/local/certbot/';

			$passphrase = $socketSettings->passphrase ?? NULL;
			$certFile   = $socketSettings->certFile   ?? NULL;
			$keyFile    = $socketSettings->keyFile    ?? NULL;
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
		if($newProducers = $this->checkForNewUsers())
		{
			foreach($newProducers as $producer)
			{
				$this->producers[ ++$this->index ] = $producer;

				$this->onConnect($producer, $this->index);
			}
		}

		$dead = [];

		foreach($this->producers as $i => $producer)
		{
			if(!$producer)
			{
				continue;
			}

			if($producer->done())
			{
				$dead[$i] = $producer;
			}

			if($message = $producer->check())
			{
				\SeanMorris\Ids\Log::debug($message->__debugInfo());

				$this->onReceive(
					$message->type()
					, $message->content()
					, $producer
					, $i
				);

				continue;
			}
		}

		foreach($dead as $i => $producer)
		{
			$this->onDisconnect($producer, $i);

			$this->cleanupDeadClient($producer, $i);
		}

		$this->hub->tick();
	}

	protected function checkForNewUsers()
	{
		$producers = [];

		if($newSocket = stream_socket_accept($this->socket, 0))
		{
			stream_set_blocking($newSocket, TRUE);

			if($this->crypto)
			{
				stream_socket_enable_crypto(
					$newSocket
					, TRUE
					, STREAM_CRYPTO_METHOD_SSLv23_SERVER
				);
			}

			$incomingHeaders = fread($newSocket, 2**16);

			if(preg_match('#^Sec-WebSocket-Key: (\S+)#mi', $incomingHeaders, $match))
			{
				stream_set_blocking($newSocket, FALSE);

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

				$producer = new static::$Producer($newSocket);

				array_push($producers, $producer);
			}
			else
			{
				stream_socket_shutdown($newSocket, STREAM_SHUT_RDWR);
			}
		}

		return $producers;
	}

	protected function cleanupDeadClient($client, $clientIndex)
	{
		if(!isset($this->agents[ $clientIndex ]))
		{
			return;
		}

		$this->hub->unsubscribe('*', $this->agents[ $clientIndex ]);

		unset($this->userContext[ $clientIndex ]);
		unset($this->producers[ $clientIndex ]);
		unset($this->agents[ $clientIndex]);
	}

	protected function onConnect($client, $clientIndex)
	{
		\SeanMorris\Ids\Log::debug(sprintf("#%d joined.\n", $clientIndex));
	}

	protected function onDisconnect($client, $clientIndex)
	{
		\SeanMorris\Ids\Log::debug(sprintf("#%d left.\n", $clientIndex));
	}

	protected function onReceive($type, $message, $client, $clientIndex)
	{
		$response = NULL;

		if(!isset($this->agents[$clientIndex]))
		{
			$this->agents[$clientIndex] = new \SeanMorris\Kallisti\Agent;
		}

		$agent = $this->agents[$clientIndex];

		if(!isset($this->userContext[$clientIndex]))
		{
			$agent->register($this->hub);

			$this->hub->unsubscribe('*', $agent);

			$agent->expose(function($content, $output, $origin, $channel, $originalChannel, $cc = NULL) use($client, $clientIndex){

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
					, $clientIndex
					, strlen($syndicated->encoded())
				));

				if($client->done())
				{
					$this->cleanupDeadClient($client, $clientIndex);
					$this->onDisconnect($client, $clientIndex);

					return;
				}

				try
				{
					$client->send($syndicated->encoded());
				}
				catch (\Exception $exception)
				{
					\SeanMorris\Ids\Log::error($exception->getMessage());

					$this->onDisconnect($client, $clientIndex);

					$this->cleanupDeadClient($client, $clientIndex);
				}
			});

			$this->userContext[$clientIndex] = [
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

			case(static::$Message::TYPE['TEXT']):

				\SeanMorris\Ids\Log::debug(sprintf(
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

				\SeanMorris\Ids\Log::debug(sprintf(
					"<< %d[%d]: \"%s\"\n"
					, $clientIndex
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
	function($errCode, $message, $file, $line, $context) use(&$errorHandler) {
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
