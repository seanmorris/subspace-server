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
}
