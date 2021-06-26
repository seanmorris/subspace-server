<?php
namespace SeanMorris\SubSpace;
class WebRoute implements \SeanMorris\Ids\Routable
{
	public function auth()
	{
		$user = \SeanMorris\Access\Route\AccessRoute::_currentUser();
		$uid  = NULL;

		if($user)
		{
			$uid =  $user->publicId;
		}

		return new \SeanMorris\SubSpace\JwtToken([
			'time'  => microtime(TRUE)
			, 'uid' => $uid
		]);
	}

	public function _dynamic($router)
	{
		$channel = $router->path()->getNode();

		$hub = new \SeanMorris\SubSpace\Kallisti\Hub;

		$reader = $hub->reader($channel, TRUE);

		$time = microtime(TRUE);

		$allMessages = [];

		if(microtime(TRUE) - $time < 0.05)
		{
			$messages = $reader();

			if($messages)
			{
				array_push($allMessages, ...array_values($messages));
			}
		}

		return json_encode($allMessages);
	}
}
