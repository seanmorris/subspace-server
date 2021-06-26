<?php
namespace SeanMorris\SubSpace\Kallisti;
class Hub extends \SeanMorris\Kallisti\Hub
{
	protected static $defaultChannel = \SeanMorris\SubSpace\Kallisti\Channel::class;

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

	public function write($channelName, $content, $origin = NULL, $cc = [], $bcc = [])
	{
		$channels = $this->getChannels($channelName, 'write');

		if(!$channels)
		{
			return;
		}

		$output = NULL;

		foreach($channels as $channel)
		{
			$channel->write(
				$content
				, $output
				, $origin
				, $channelName
			);
		}

		return $output;
	}

	public function reader($channelName, $readOnly = FALSE)
	{
		$channels = $this->getChannels($channelName, 'read');

		if(!$channels)
		{
			return function() { return []; };
		}

		$readers = [];

		foreach($channels as $channel)
		{
			$readers[$channel->name] = $channel->reader($readOnly);
		}

		return function() use($readers) {

			$output = [];

			foreach($readers as $channelName => $reader)
			{
				array_push($output, ...$reader());
			}

			return array_filter($output);
		};
	}
}
