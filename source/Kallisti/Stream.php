<?php
namespace SeanMorris\SubSpace\Kallisti;
class Stream
{
	protected
		$totalSizeMax = 0
		, $settings   = NULL
		, $name       = NULL
		, $stream     = NULL
		, $indexFile  = NULL
		, $sequence   = NULL
		, $index      = []
		, $readOnly   = FALSE
		, $full       = FALSE
		, $first      = FALSE
		, $last       = FALSE;

	public function __construct($name, $sequence, $readOnly = FALSE)
	{
		if($settings = \SeanMorris\Ids\Settings::read('subspace', 'stored'))
		{
			$this->totalSizeMax = $settings->messageTotalMax;
		}

		$this->sequence = $sequence;
		$this->name     = $name;

		$file  = sprintf(
			'/tmp/kallisti/%d-channel-%s'
			, $this->sequence
			, urlencode($name)
		);

		$index = sprintf(
			'/tmp/kallisti/%d-index-%s'
			, $this->sequence
			, urlencode($name)
		);

		if($readOnly && !file_exists($index))
		{
			return FALSE;
		}

		$this->readOnly  = $readOnly;

		$this->indexFile = fopen($index, $readOnly ? 'r' : 'c+');
		$this->stream    = fopen($file,  $readOnly ? 'r' : 'c+');

		stream_set_read_buffer($this->indexFile, 4);
		stream_set_read_buffer($this->stream,    0);

		while($bytes = fread($this->indexFile, 4))
		{
			['start' => $start, 'length' => $length] = unpack('nstart/nlength', $bytes);

			$this->index[] = [$start, $length];
		}

		fseek($this->indexFile, fstat($this->indexFile)['size']);
		fseek($this->stream,    fstat($this->stream)['size']);

		if(!$readOnly)
		{
			stream_set_write_buffer($this->indexFile, 0);
			stream_set_write_buffer($this->stream,    0);
		}
	}

	public function write($content, &$output, $origin, $originalChannel = NULL, $cc = [], $bcc = [])
	{
		if($this->readOnly)
		{
			return;
		}

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

		$message = json_encode([

			'message'    => $content
			, 'time'     => microtime(true)
			, 'origin'   => $originType
			, 'originId' => $originId
			, 'channel'  => $this->name
			, 'originalChannel' => $originalChannel

		]) . PHP_EOL;

		$length = strlen($message);

		fseek($this->indexFile, fstat($this->indexFile)['size']);
		fseek($this->stream,    fstat($this->stream)['size']);

		$start = ftell($this->stream);

		$totalSize = $start + $length;

		\SeanMorris\Ids\Log::debug($start, $this->totalSizeMax);

		if($this->totalSizeMax && $totalSize > $this->totalSizeMax)
		{
			$this->full = TRUE;

			return FALSE;
		}

		$this->index[] = [$start, $length];

		if(fwrite($this->stream, $message))
		{
			$this->last = microtime();

			if(!$this->first)
			{
				$this->first = $this->last;
			}

			fwrite($this->indexFile, pack('nn', $start, $length));

			return TRUE;
		}

		return FALSE;
	}

	public function reader()
	{
		if(1 || $this->readOnly)
		{
			$messages = [];

			foreach($this->index as [$start, $length])
			{
				fseek($this->stream, $start);

				$messages[] = json_decode(fread($this->stream, $length));
			}

			return function() use($messages) { return $messages; };
		}

		return function() {

			static $current = 0;

			if($current >= count($this->index))
			{
				return [];
			}

			[$start, $length] = $this->index[ $current ];

			fseek($this->stream, $start);

			$message = NULL;

			if($length === 0 || $message = fread($this->stream, $length))
			{
				$current++;
			}

			return [json_decode($message)];
		};
	}

	public function truncate()
	{
		ftruncate($this->indexFile, 0);
		ftruncate($this->stream, 0);

		$this->index = [];
		$this->full  = false;
	}

	public function isFull()
	{
		return $this->full;
	}

	public function updateTime()
	{
		return $this->stream ? fstat($this->stream)['mtime'] : 0;
	}
}
