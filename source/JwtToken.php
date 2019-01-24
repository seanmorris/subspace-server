<?php
namespace SeanMorris\SubSpace;
class JwtToken
{
	protected static $algorithm = 'HS512';

	protected static $algorithmMap = [
		'HS512' => 'sha512'
	];

	protected static function secret()
	{
		return 'override me.';
	}

	public function __construct($content)
	{
		$this->content = $content;
	}

	public static function verify($token)
	{
		if(!preg_match('/.+\..+\..+/', $token))
		{
			return FALSE;
		}

		list($header,$body,$signature) = explode('.', $token);

		$content = base64_decode($body);

		$expected = hash_hmac(
			static::$algorithmMap[static::$algorithm]
			, $content
			, static::secret()
		);

		if(hash_equals($expected, $signature))
		{
			return $content;
		}

		return FALSE;
	}

	public static function fromString($token)
	{
		list($header,$body,$signature) = explode('.', $token);

		return new static(json_decode(base64_decode($body)));
	}

	public function __toString()
	{
		return sprintf(
			'%s.%s.%s'
			, base64_encode(json_encode([
				'alg'   => static::$algorithm
				, 'typ' => 'JWT'
			]))
			, base64_encode(json_encode($this->content))
			, $this->signature()
		);
	}

	public function signature()
	{
		return hash_hmac(
			static::$algorithmMap[static::$algorithm]
			, json_encode($this->content)
			, static::secret()
		);
	}
}