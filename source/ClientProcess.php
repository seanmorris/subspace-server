<?php
namespace SeanMorris\SubSpace;
class ClientProcess
{
	protected $pid, $done, $remoteSocket;

	public function __construct($stream)
	{
		$this->wrappedStream = $stream;

		$this->sockets = stream_socket_pair(
			STREAM_PF_UNIX
			, STREAM_SOCK_STREAM
			, STREAM_IPPROTO_IP
		);

		$this->proxy = $this->sockets[0];

		stream_set_write_buffer($this->sockets[0], 0);
		stream_set_read_buffer($this->sockets[0], 0);

		stream_set_write_buffer($this->sockets[1], 0);
		stream_set_read_buffer($this->sockets[1], 0);

		stream_set_blocking($this->sockets[1], FALSE);
		stream_set_blocking($this->sockets[0], FALSE);
	}

	public function fork()
	{
		pcntl_async_signals(TRUE);

		$existingHandler = pcntl_signal_get_handler(SIGHUP);

		pcntl_signal(SIGHUP, function() use($existingHandler) {

			if(!$this->pid)
			{
				$this->done = true;
			}

			if(function_exists($existingHandler))
			{
				$existingHandler();
			}

		});

		$this->pid = pcntl_fork();

		if($this->pid === -1)
		{
			throw new \Exception('Fork failed.');
		}
		else if($this->pid === 0)
		{
			while(TRUE)
			{
				if($outgoing = fread($this->sockets[1], 2**16))
				{
					fwrite($this->wrappedStream, $outgoing);

					// fflush($this->wrappedStream);
				}

				if($incoming = fread($this->wrappedStream, 2**16))
				{
					fwrite($this->sockets[1], $incoming);

					// fflush($this->sockets[1]);
				}

				if(!$incoming && !$outgoing && $this->done)
				{
					break;
				}
			}
		}
		else
		{
			fclose($this->wrappedStream);
		}
	}

	public function read($length)
	{
		return fread($this->sockets[0], $length);
	}

	public function write($bytes)
	{
		fwrite($this->sockets[0], $bytes);

		// fflush($this->sockets[0]);
	}

	public function done()
	{
		if($this->pid)
		{
			fclose($this->sockets[0]);
			fclose($this->sockets[1]);

			posix_kill($this->pid, SIGHUP);
		}
	}

	public function wait()
	{
		if($this->pid)
		{
			pcntl_waitpid($this->pid, $status);
			return $status;
		}
	}

	public function __get($name)
	{
		return $this->{ $name };
	}
}
