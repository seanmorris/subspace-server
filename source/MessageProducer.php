<?php
namespace SeanMorris\SubSpace;

class MessageProducer implements \Iterator
{
	static $Message = Message::Class;

	public function __construct($socket)
	{
		$this->index   = 0;
		$this->current = new static::$Message($this->index);
		$this->socket  = $socket;
		$this->done    = false;

		$this->leftovers = '';
	}

	public function check()
	{
		if($this->current->content() && $this->current->isDone())
		{
			$current = $this->current;

			$this->leftovers = $current->leftover();

			\SeanMorris\Ids\Log::debug(sprintf(
				'Carrying %d leftover bytes...'
				, strlen($this->leftovers))
			);

			$this->index++;

			$this->current = new static::$Message($this->index);

			return $current;
		}

		while(!feof($this->socket))
		{
			$chunk = fread($this->socket, 2**16);

			if($this->leftovers)
			{
				$chunk = $this->leftovers . $chunk;

				$this->leftovers = NULL;
			}

			if(!strlen($chunk))
			{
				return NULL;
			}

			$this->current->decode($chunk);
		}
	}

	public function done()
	{
		return $this->done;
	}


	public function send($raw, $type = NULL)
	{
		stream_set_blocking($this->socket, TRUE);
		fwrite($this->socket, $raw);
		stream_set_blocking($this->socket, FALSE);
	}

	public function name()
	{
		return stream_socket_get_name($this->socket, TRUE);
	}

	public function current()
	{
		return $this->check();
	}
	public function key()   { return $this->index; }
	public function valid() { return $this->done; }
	public function rewind(){}
	public function next()  {}
}
