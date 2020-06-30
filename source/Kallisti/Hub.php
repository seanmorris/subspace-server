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

		$entryRoute = 'SeanMorris\SubSpace\EntryRoute';

		if($configRoute = \SeanMorris\Ids\Settings::read('socketEntryPoint'))
		{
			$entryRoute = $configRoute;
		}

		$entryRoute = new $entryRoute;

		$entryRoute->_tick($this);
	}

	public function agent($id)
	{
		return $this->agents[$id] ?? FALSE;
	}
}
