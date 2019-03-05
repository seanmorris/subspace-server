<?php
namespace SeanMorris\SubSpace;
class EntryRoute implements \SeanMorris\Ids\Routable
{
	/**
	 * Print the Message of the Day.
	 */
	public function motd($router)
	{
		$clientId = $router->contextGet('__agent')->id;

		$uid  = sprintf('0x%04x', $clientId);
		$name = NULL;

		if($user = $router->contextGet('__persistent'))
		{
			$name = $user->username;
		}

		return new \SeanMorris\SubSpace\Idilic\View\Motd([
			'name'  => $name
			, 'uid' => $uid
		]);

		return sprintf('Welcome to the subspace server, #0x%04x!', $clientId);
	}

	/**
	 * Roll a 64 bit die.
	 */
	public function random()
	{
		return rand(PHP_INT_MIN,PHP_INT_MAX);
	}

	public function seq($router)
	{
		$agent = $router->contextGet('__agent');

		if(!$agent)
		{
			return;
		}

		foreach(range(0,255) as $i)
		{

		}
	}

	/**
	 * Get the current time.
	 */
	public function time($router)
	{
		$args = $router->path()->consumeNodes();

		if($args[0] ?? FALSE)
		{
			return ['time' => microtime(TRUE)];
		}

		return (int) round(microtime(TRUE) * 1000);
	}

	/**
	 * Auth via JWT.
	 */
	public function auth($router)
	{
		$args     = $router->path()->consumeNodes();
		$agent    = $router->contextGet('__agent');
		$clientId = $agent->id;

		// if($router->contextGet('__authed')
		// 	&& $router->contextGet('__persistent')
		// ){
		// 	return [
		// 		'error' => sprintf('0x%04x already authed.', $clientId)
		// 	];
		// }

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

	/**
	 * Print the help page.
	 */
	public function help()
	{
		return new \SeanMorris\SubSpace\Idilic\View\Help;
	}


	/**
	 * Print the Manual.
	 */
	public function manual()
	{
		return new \SeanMorris\SubSpace\Idilic\View\Manual;
	}

	public function _dynamic($router)
	{
		// $args = $router->path()->consumeNodes();

		$command = $router->path()->getNode();

		if($command == '?')
		{
			return $this->help($router);
		}

		return FALSE;
	}

	/**
	 * Use /register instead. Type "manual" for more info.
	 * Create a persistent user account.
	 */
	public function register($router)
	{
		if($user = $router->contextGet('__persistent'))
		{
			return ['error' => sprintf(
				'Already logged in in as %s.'
				, $user->username
			)];
		}

		$args = $router->path()->consumeNodes();

		if(!$router->contextGet('__authed'))
		{
			return ['error' => 'You need to auth before you can register.'];
		}

		if(count($args) < 3)
		{
			return ['error' => 'Usage: register USERNAME PASSWORD EMAIL.'];
		}

		if(!filter_var($args[2], FILTER_VALIDATE_EMAIL))
		{
			return ['error' => 'Please supply a valid email in position 3.'];
		}

		$user = \SeanMorris\Access\User::loadOneByUsername($args[0]);

		if($user)
		{
			return ['error' => 'Username exists.'];
		}

		$user = new \SeanMorris\Access\User;

		$user->consume([
			'username'   => $args[0]
			, 'password' => $args[1]
			, 'email'    => $args[2]
		]);

		if($user->save())
		{
			$router->contextSet('__persistent', $user);

			return ['success' => 'Persistent user account created!'];
		}

		return [
			'error' => 'Unknown.'
		];
	}

	/**
	 * Use /login instead. Type "manual" for more info.
	 * Login to your account.
	 */
	public function login($router)
	{
		if($user = $router->contextGet('__persistent'))
		{
			return ['error' => sprintf(
				'Already logged in as %s.'
				, $user->username
			)];
		}

		$args = $router->path()->consumeNodes();

		if(count($args) < 2)
		{
			return ['error' => 'Usage: register USERNAME PASSWORD.'];
		}

		$user = \SeanMorris\Access\User::loadOneByUsername($args[0]);

		if(!$user)
		{
			return ['error' => 'User not found.'];
		}

		if($user->login($args[1]))
		{
			$router->contextSet('__persistent', $user);

			return ['success' => 'Logged in!'];
		}
		else
		{
			return ['error' => 'Bad password.'];
		}
	}

	public function logout($router)
	{
		$router->contextSet('__persistent', FALSE);

		return 'logged out.';
	}
}
