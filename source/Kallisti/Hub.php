<?php
namespace SeanMorris\SubSpace\Kallisti;
class Hub extends \SeanMorris\Kallisti\Hub
{
	public function tick()
	{
		foreach($this->channels as $channelName => $channel)
		{
			if($channel instanceof \SeanMorris\SubSpace\Kallisti\Channel)
			{
				$channel->tick();
			}
		}
	}
}
