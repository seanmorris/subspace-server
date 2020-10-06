<?php
namespace SeanMorris\SubSpace;

use \SeanMorris\Subspace\Message;

class MessageProducer implements \Iterator
{
	static $Message = \SeanMorris\Subspace\Message::Class;

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
		while(!feof($this->socket))
		{
			if($this->current->isDone())
			{
				$this->leftovers = $this->current->leftover();

				$this->index++;

				$current = $this->current;

				$this->current = new static::$Message($this->index);

				return $current;
			}

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
		$encoded = Message::enc($raw, $type);

		stream_set_blocking($this->socket, TRUE);
		fwrite($this->socket, $encoded);
		stream_set_blocking($this->socket, FALSE);
	}

	public function name()
	{
		return stream_socket_get_name($this->socket, TRUE);
	}

	public function subscribe($channel)
	{
		stream_set_blocking($client, TRUE);
		fwrite($this->socket, Message::enc('sub ' . $channel));
		stream_set_blocking($client, FALSE);
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
