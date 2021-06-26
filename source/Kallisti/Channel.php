<?php
namespace SeanMorris\SubSpace\Kallisti;
class Channel extends \SeanMorris\Kallisti\Channel
{
	protected $streams = [];

	public function tick(){}

	public function reader($readOnly = FALSE)
	{
		if(!$this->streams)
		{
			$this->streams[0] = new Stream($this->name, 0, $readOnly);
			$this->streams[1] = new Stream($this->name, 1, $readOnly);
		}

		$streams = $this->streams;

		if($readOnly)
		{
			usort($streams, function($a, $b) {
				return $a->updateTime() <=> $b->updateTime();
			});
		}

		return function () use($streams) { return [
			...$streams[0]->reader()()
			, ...$streams[1]->reader()()
		];};
	}

	public function write($content, &$output, $origin, $originalChannel = NULL, $cc = [], $bcc = [])
	{
		if(!$this->streams)
		{
			$this->streams[0] = new Stream($this->name, 0);
			$this->streams[1] = new Stream($this->name, 1);
		}

		\SeanMorris\Ids\Log::debug($this->streams);

		$stream = $this->streams[0];

		$writeResult = $stream->write(
			$content
			, $output
			, $origin
			, $originalChannel
			, $cc
			, $bcc
		);

		if(!$writeResult)
		{
			if($stream->isFull())
			{
				$this->streams = array_reverse($this->streams);

				$this->streams[0]->truncate();

				$writeResult = $this->streams[0]->write(
					$content
					, $output
					, $origin
					, $originalChannel
					, $cc
					, $bcc
				);
			}
		}

		return $writeResult;
	}
}
