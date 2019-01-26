<?php
namespace SeanMorris\SubSpace;
class WebRoute implements \SeanMorris\Ids\Routable
//extends \SeanMorris\PressKit\Controller
{
	public $routes = [
		'user' => 'SeanMorris\Access\Route\AccessRoute'
	];

	public function __construct()
	{
		if(!isset($_GET['api']) && !($_POST ?? FALSE) && php_sapi_name() !== 'cli')
		{
			\SeanMorris\Ids\Log::debug($_SERVER);

			$public = \SeanMorris\Ids\Settings::read('public');

			$page   = '/index.html';
			$uiPath = realpath($public . $page);

			if(file_exists($uiPath))
			{
				print file_get_contents($uiPath);
			}
			else
			{
				printf(
					'Cannot locate "%s".'
					, ($public . $page)
				);
			}

			die;
		}

		if (session_status() === PHP_SESSION_NONE)
		{
			session_start();
		}
	}

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
}
