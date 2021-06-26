<?php
namespace SeanMorris\SubSpace;

class MessageProducer implements \Iterator
{
	static $Message = Message::Class;

	protected $id, $count, $socket, $done, $current, $maxMessageSize;

	public function __construct($socket, $id)
	{
		$this->id        = $id;
		$this->socket    = $socket;
		$this->count     = 0;
		$this->done      = false;
		$this->current   = new static::$Message(null, null, $this->count);
		$this->settings  = \SeanMorris\Ids\Settings::read('subspace');
		$this->socketId  = (int) $socket;
		$this->leftovers = '';

		if($this->settings->messageSizeMax)
		{
			$this->maxMessageSize = $this->settings->messageSizeMax;
		}
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

			$this->leftovers = $current->leftover();

			\SeanMorris\Ids\Log::debug(sprintf(
				'Carrying %d leftover bytes...'
				, strlen($this->leftovers))
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

		$wrote = fwrite($this->socket, $raw);

		if($wrote === FALSE)
		{
			$this->done = true;
		}
		else
		{
			fflush($this->socket);
		}
	}

	public function name()
	{
		return stream_socket_get_name($this->socket, TRUE);
	}

	public function current()
	{
		return $this->current;
	}

	public function key()   { return $this->count; }
	public function valid() { return !$this->done; }
	public function next()  { return $this->check(); }
	public function rewind(){}
}
