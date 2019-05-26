<?php
namespace SeanMorris\SubSpace;
class EntryRoute implements \SeanMorris\Ids\Routable
{
	/**
	 * Auth via JWT.
	 */
	public function auth($router)
	{
		$args     = $router->path()->consumeNodes();
		$agent    = $router->contextGet('__agent');
		$clientId = $agent->id;

		if(count($args) < 1)
		{
			return [
				'error' => 'Please supply an auth token.'
			];
		}

		$tokenContent = \SeanMorris\SubSpace\JwtToken::verify($args[0]);

		if($tokenContent)
		{
			$tokenContent = json_decode($tokenContent);

			if($tokenContent->uid)
			{
				$user = \SeanMorris\Access\User::loadOneByPublicId(
					$tokenContent->uid
				);

				if($user)
				{
					$router->contextSet('__authed', TRUE);
					$router->contextSet('__persistent', $user);

					$agent->contextSet('__persistent', $user);

					return 'authed & logged in.';
				}
			}

			$router->contextSet('__authed', TRUE);

			return 'authed.';
		}
	}

	/**
	 * Get/Set your nickname.
	 */
	public function nick($router)
	{
		$args   = $router->path()->consumeNodes();

		if(!$router->contextGet('__authed'))
		{
			return [
				'error' => 'You need to auth before you can nick.'
			];
		}

		if(count($args) < 1)
		{
			return [
				'nick' => $router->contextGet('__nickname')
			];
		}

		if(!preg_match('/^[a-z]\w+$/i', $args[0]))
		{
			return [
				'error' => 'Nickname must be alphanumeric.'
			];
		}

		$client = $router->contextSet('__nickname', $args[0]);

		return [
			'yournick' => $args[0]
		];
	}

	/**
	 * Publish a message to a channel individually or by a selector.
	 */
	public function pub($router)
	{
		$args  = $router->path()->consumeNodes();
		$hub   = $router->contextGet('__hub');
		$agent = $router->contextGet('__agent');

		if(!$router->contextGet('__authed'))
		{
			return [
				'error' => 'You need to auth before you can pub.'
			];
		}

		if(count($args) < 1)
		{
			return [
				'error' => 'Please supply a channel selector.'
			];
		}

		$channelName = array_shift($args);
		$message     = implode(' ', $args);

		return $hub->publish($channelName, $message, $agent);
	}

	/**
	 * Subscribe to a channel individually or by a selector.
	 */
	public function sub($router)
	{
		$args  = $router->path()->consumeNodes();
		$hub   = $router->contextGet('__hub');
		$agent = $router->contextGet('__agent');

		if(!$router->contextGet('__authed'))
		{
			return [
				'error' => 'You need to auth before you can sub.'
			];
		}

		if(count($args) < 1)
		{
			return [
				'error' => 'Please supply a channel selector.'
			];
		}

		$hub->subscribe($args[0], $agent);

		return $this->subs($router);
	}

	/**
	 * List your current subscriptions.
	 */
	public function subs($router)
	{
		\SeanMorris\Ids\Log::debug('Listing subscriptions');

		$args  = $router->path()->consumeNodes();
		$hub   = $router->contextGet('__hub');
		$agent = $router->contextGet('__agent');
		
		if(!$router->contextGet('__authed'))
		{
			return [
				'error' => 'You need to auth before you can subs.'
			];
		}

		$channels = array_keys(array_filter($hub->subscriptions($agent)));

		\SeanMorris\Ids\Log::debug($channels);

		$channels = array_map(function($channel){
			if(is_numeric($channel))
			{
				return '0x' . strtoupper(
					str_pad(
						dechex($channel)
						, 4
						, 0
						, STR_PAD_LEFT
					)
				);
			}

			return $channel;
		}, $channels);

		return ['subscriptions' => $channels];
	}
	
	/**
	 * Unsubscribe from a channel individually or by a selector.
	 */
	public function unsub($router)
	{
		if(!$router->contextGet('__authed'))
		{
			return [
				'error' => 'You need to auth before you can unsub.'
			];
		}

		$args  = $router->path()->consumeNodes();
		$hub   = $router->contextGet('__hub');
		$agent = $router->contextGet('__agent');

		if(count($args) < 1)
		{
			return [
				'error' => 'Please supply a channel selector.'
			];
		}

		$channels = $hub->getChannels($args[0]);

		foreach($channels as $channelName => $channelClass)
		{
			$hub->unsubscribe($channelName, $agent);
		}

		return $this->subs($router);
	}

	/**
	 * List available channels on the server.
	 */
	public function channels($router)
	{
		$args = $router->path()->consumeNodes();
		$hub  = $router->contextGet('__hub');
		$args  = $router->path()->consumeNodes();

		// unset($channels['*']);

		return ['channels' => array_map(
			function($channel)
			{
				if(is_numeric($channel))
				{
					return '0x' . strtoupper(
						str_pad(
							dechex($channel)
							, 4
							, 0
							, STR_PAD_LEFT
						)
					);
				}

				return $channel;
			}
			, array_keys($hub->getChannels($args[0] ?? '*'))
		)];
	}

	/**
	 * Lists available commands.
	 */
	public function commands()
	{
		$reflection = new \ReflectionClass(get_class());
		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		$_methods = [];

		foreach($methods as $method)
		{
			if($method->name[0] == '_')
			{
				continue;
			}

			if($comment = $method->getDocComment())
			{
				$comment = substr($comment, 3);
				$comment = trim($comment);
				$comment = substr($comment, 2);
				$comment = substr($comment, 0, strlen($comment)-3);
				$comment = trim($comment);

				$_methods[$method->name] = $comment;

				continue;
			}

			$_methods[$method->name] = '';
		}

		return ['commands' => $_methods];
	}
}
