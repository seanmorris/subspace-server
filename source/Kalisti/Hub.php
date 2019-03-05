<?php
namespace SeanMorris\SubSpace\Kalisti;
class Hub extends \SeanMorris\Kalisti\Hub
{
	public function tick()
	{
		foreach($this->channels as $channelName => $channel)
		{
			if($channel instanceof \SeanMorris\SubSpace\Kalisti\Channel)
			{
				$channel->tick();
			}
		}
	}
}
