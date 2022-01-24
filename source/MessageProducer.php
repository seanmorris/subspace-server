<?php
namespace SeanMorris\SubSpace;

class MessageProducer implements \Iterator
{
	static $Message = Message::Class;

	protected $id, $count, $socket, $done, $current, $maxMessageSize, $lastActive, $pinged = false;

	public function __construct($socket, $id)
	{
		$this->id          = $id;
		$this->socket      = $socket;
		$this->count       = 0;
		$this->done        = false;
		$this->pinged      = false;
		$this->lastActive  = 1000 * microtime(true);
		$this->lastNetwork = 1000 * microtime(true);
		$this->current     = new static::$Message(null, null, $this->count);
		$this->settings    = \SeanMorris\Ids\Settings::read('subspace');
		$this->socketId    = (int) $socket;
		$this->leftovers   = '';

		if($this->settings->messageSizeMax)
		{
			$this->messageSizeMax = $this->settings->messageSizeMax;
		}
	}

	public function ping($message = NULL)
	{
		$message = $message ?? 'ping-' . uniqid();

		$this->send(Message::enc($message, Message::TYPE['PING']));

		$this->pinged = TRUE;
	}

	public function wasPinged($message = NULL)
	{
		return $this->pinged;
	}

	public function check()
	{
		$chunk = fread($this->socket, 2**16);

		if($this->leftovers)
		{
			$chunk = $this->leftovers . $chunk;

			$this->leftovers = NULL;
		}


		if(!strlen($chunk))
		{
			return;
		}

		try
		{
			$this->current->decode($chunk);

			if($this->maxMessageSize && $this->current->length() > $this->maxMessageSize)
			{
				throw new \LengthException('Message exceeds maximum length.');
			}
		}
		catch(\LengthException $error)
		{
			$message = new static::$Message;

			$encoded = $message->encode(
				json_encode(['error' => $error->getMessage()])
				, static::$Message::TYPE['TEXT']
			);

			$this->send($encoded);

			\SeanMorris\Ids\Log::logException($error);

			$this->current = null;

			$this->done = true;

			fclose($this->socket);

			return;
		}
		catch(\Exception $error)
		{
			\SeanMorris\Ids\Log::logException($error);

			$this->done = true;

			$this->current = null;

			$this->done = true;

			fclose($this->socket);

			return;
		}

		if($this->current->content() && $this->current->isDone())
		{
			$current = $this->current;

			if($current->type() === static::$Message::TYPE['PONG'])
			{
				$this->lastNetwork = 1000 * microtime(true);
				$this->pinged = FALSE;
			}
			else
			{
				$this->lastNetwork = $this->lastActive = 1000 * microtime(true);
			}

			$this->leftovers = $current->leftover();

			\SeanMorris\Ids\Log::debug(sprintf(
				'Carrying %d leftover bytes...'
				, strlen($this->leftovers ?? ''))
			);

			$this->count++;

			$this->current = new static::$Message(null, null, $this->count);

			return $current;
		}
	}

	public function hanging()
	{
		return !!$this->leftovers;
	}

	public function done()
	{
		return $this->done;
	}

	public function id()
	{
		return $this->id;
	}

	public function socketId()
	{
		return $this->socketId;
	}

	public function send($raw, $type = NULL)
	{
		if($this->done)
		{
			\SeanMorris\Ids\Log::warn('Writing to disconnected client!');
			return;
		}

		$wrote = FALSE;

		try
		{
			$wrote = fwrite($this->socket, $raw);
		}
		catch(\Exception $error)
		{
			\SeanMorris\Ids\Log::logException($error);
		}

		if($wrote === FALSE)
		{
			$this->done = true;
		}
		else
		{
			fflush($this->socket);
		}
	}

	public function lastActive()
	{
		return $this->lastActive;
	}

	public function lastNetwork()
	{
		return $this->lastNetwork;
	}

	public function name()
	{
		return stream_socket_get_name($this->socket, TRUE);
	}

	#[\ReturnTypeWillChange]
	public function current()
	{
		return $this->current;
	}

	#[\ReturnTypeWillChange]
	public function key()   { return $this->count; }
	#[\ReturnTypeWillChange]
	public function valid() { return !$this->done; }
	#[\ReturnTypeWillChange]
	public function next()  { return $this->check(); }
	#[\ReturnTypeWillChange]
	public function rewind(){}
}
