<?php
namespace SeanMorris\Subspace\Kallisti;
class Agent extends \SeanMorris\Kallisti\Agent
{
	protected $readers = [];

	public function read($channel)
	{
		if(!$this->hub)
		{
			throw new Exception('No hub registered to Agent!');
		}

		$reader = $this->hub->reader($channel);

		$time = microtime(TRUE);

		$allMessages = [];

		while(microtime(TRUE) - $time < 0.05)
		{
			$messages = $reader();

			if($messages)
			{
				array_push($allMessages, ...array_values($messages));
			}
		}

		return $messages;
	}
}
