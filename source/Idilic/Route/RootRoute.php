<?php
namespace SeanMorris\SubSpace\Idilic\Route;
class RootRoute implements \SeanMorris\Ids\Routable
{
	const FREQUENCY = 120;

	public function compa($router)
	{
		$args = $router->path()->consumeNodes();

		var_dump(\SeanMorris\Kalisti\Channel::compareNames(...$args));
	}

	public function server()
	{
		$socket = new \SeanMorris\SubSpace\Socket;

		while(true)
		{
			usleep( 1000000 / static::FREQUENCY );

			$socket->tick();
		}
	}
}
