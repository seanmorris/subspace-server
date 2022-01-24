<?php
namespace SeanMorris\SubSpace;
class JwtToken
{
	protected $content = '', $algorithm = NULL;

	public function __construct($content)
	{
		$this->algorithm = 'sha256rsa';
		$this->content   = $content;
	}

	public static function verify($token, $maxAge = 30)
	{
		if(!preg_match('/.+\..+\..+/', $token))
		{
			return FALSE;
		}

		[$header, $content, $signature] = static::parse($token);

		if($maxAge)
		{
			if(!isset($header->iat))
			{
				return FALSE;
			}

			if(time() - $header->iat > $maxAge)
			{
				return FALSE;
			}
		}

		$publicKeyFile = 'file://' . IDS_ROOT . '/data/local/ssl/localhost.crt';

		if(!file_exists($publicKeyFile))
		{
			throw new \Exception('No key file found.');
		}

		$publicKey = file_get_contents($publicKeyFile);

		return openssl_verify($content, $signature, $publicKey, 'sha256WithRSAEncryption');
	}

	public static function fromString($token)
	{
		[,$body] = static::parse($token);

		return new static(json_decode($body));
	}

	protected static function parse($token)
	{
		list($header,$body,$signature) = explode('.', $token);

		$header    = json_decode(base64_decode($header));
		$content   = base64_decode($body);
		$signature = base64_decode($signature);

		return [$header, $content, $signature];
	}

	public function __toString()
	{
		return sprintf(

			'%s.%s.%s'

			, base64_encode(json_encode([
				'alg'   => $this->algorithm
				, 'typ' => 'JWT'
				, 'iat' => time()
			]))

			, base64_encode(json_encode($this->content))

			, $this->signature()
		);
	}

	public function signature()
	{
		if(!file_exists($privateKeyFile = 'file://' . IDS_ROOT . '/data/local/ssl/localhost.key'))
		{
			throw new \Exception('No key file found.');
		}

		$privateKey = openssl_pkey_get_private($privateKeyFile);

		$jsonContent = json_encode($this->content);

		$signature = '';
		openssl_sign($jsonContent, $signature, $privateKey, 'sha256WithRSAEncryption');

		return base64_encode($signature);
	}
}
