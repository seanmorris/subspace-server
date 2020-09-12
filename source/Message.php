<?php
namespace SeanMorris\SubSpace;

class Message
{
	protected
		$leftover   = null
		, $decoded  = null
		, $encoded  = null
		, $length   = 0
		, $type     = null
		, $fin      = false
		, $rawFrame = null
		, $masks    = null
	;

	const TYPE = [
		'CONTINUE' => 0x0
		, 'TEXT'     => 0x1
		, 'BINARY'   => 0x2
		, 'CLOSE'    => 0x8
		, 'PING'     => 0x9
		, 'PONG'     => 0xA
	];

	const SIZE = [
		'SHORT'    => 0x7E
		, 'MEDIUM' => 0x7F
		, 'LONG'   => 0x80
	];

	const FRAME_LENGTH = 0x10000;
	const TYPE_MASK    = 0x80;

	public static function enc($input)
	{
		$message = new static;

		$message->encode($input);

		print $message->encoded;
	}

	public static function dec($input)
	{
		$message = new static;

		$message->decode($input);

		var_dump($message);

		print $message->decoded;
	}

	public static function hex($input)
	{
		print join(' ', unpack('C*', $input));
	}

	public function decode($rawBytes)
	{
		while($rawBytes)
		{
			\SeanMorris\Ids\Log::debug($rawBytes);

			if(strlen($this->decoded))
			{
				$this->continueDecoding($rawBytes);
				continue;
			}

			$this->fin  = $this->fin($rawBytes);
			$this->type = $this->dataType($rawBytes);

			switch($this->type)
			{
				case(static::TYPE['PING']):

					\SeanMorris\Ids\Log::debug('Type', 'Received a PING!');
					break;

				case(static::TYPE['PONG']):

					\SeanMorris\Ids\Log::debug('Type', 'Received a PONG!');
					break;

				case(static::TYPE['CLOSE']):

					\SeanMorris\Ids\Log::debug('Type', 'Received a CLOSE MESSAGE!');
					break;

				case(static::TYPE['TEXT']):
				case(static::TYPE['BINARY']):
				case(static::TYPE['CONTINUE']):

					$this->masked = ord($rawBytes[1]) & 0b10000000;
					$this->length = ord($rawBytes[1]) & 0b01111111;

					if($this->length == 0x7E)
					{
						$this->length   = unpack('n', substr($rawBytes, 2, 2))[1];
						$this->masks    = substr($rawBytes, 4, 4);
						$this->rawFrame = substr($rawBytes, 8);

						\SeanMorris\Ids\Log::debug(sprintf(
							'Message length %d, got %d bytes.'
							, $this->length
							, strlen($this->rawFrame)
						));
					}
					else if($this->length == 0x7F)
					{
						$this->length   = unpack('J', substr($rawBytes, 2, 8))[1];
						$this->masks    = substr($rawBytes, 10, 4);
						$this->rawFrame = substr($rawBytes, 14);

						\SeanMorris\Ids\Log::debug(sprintf(
							'Message length %d, got %d bytes.'
							, $this->length
							, strlen($this->rawFrame)
						));
					}
					else
					{
						$this->masks    = substr($rawBytes, 2, 4);
						$this->rawFrame = substr($rawBytes, 6);

						\SeanMorris\Ids\Log::debug(sprintf(
							'Message length %d, got %d bytes.'
							, $this->length
							, strlen($this->rawFrame)
						));
					}

					if(!$this->masked)
					{
						$this->decoded .= $this->masks;
					}

					for ($i = 0; $i < $this->length; ++$i)
					{
						if(!isset($this->rawFrame[$i]))
						{
							\SeanMorris\Ids\Log::debug(sprintf(
								'Message length %d, Got %d bytes. Message DEFERRED due to WAITING FOR FRAME END.'
								, $this->length
								, strlen($this->rawFrame)
							));

							return;
						}

						if($this->masked)
						{
							$this->decoded .= $this->rawFrame[$i] ^ $this->masks[$i%4];
						}
						else
						{
							$this->decoded .= $this->rawFrame[$i];
						}
					}

					\SeanMorris\Ids\Log::debug($this->decoded);

					if(!$this->fin)
					{
						return;
					}

					if($rawBytes = substr($this->rawFrame, $length))
					{
						$this->leftover = $rawBytes;
					}

					break;

				default:
					\SeanMorris\Ids\Log::debug('REJECTION...');

					throw new \UnexpectedValueException(sprintf(
						'Unexpected Websocket Frame Type: %d'
						, $this->type
					));

					break;
			}
		}

		return $this->decoded;
	}

	protected function continueDecoding(&$rawBytes)
	{
		\SeanMorris\Ids\Log::debug('Resuming deferred message...');

		$remaining = $this->length - strlen($this->rawFrame);
		$append    = substr($rawBytes, 0, $remaining);
		$rawBytes  = substr($rawBytes, $remaining);

		$this->rawFrame .= $append;

		\SeanMorris\Ids\Log::debug(sprintf(
			'Appending %d bytes, %d/%d remaining...'
			, strlen($append)
			, $remaining
			, $this->length
		));

		if($remaining <= 0)
		{
			for ($i = 0; $i < $this->length; ++$i)
			{
				if(!isset($this->rawFrame[$i]))
				{
					\SeanMorris\Ids\Log::debug(sprintf(
						'Got %d bytes. Message DEFERRED due to WAITING FOR FRAME END.'
						, strlen($this->rawFrame)
					));

					return;
				}

				$this->decoded .= $this->rawFrame[$i] ^ $this->masks[$i%4];
			}

			if(!$this->fin)
			{
				if(!strlen($rawBytes))
				{
					\SeanMorris\Ids\Log::debug(sprintf(
						'Got %d bytes. Message DEFERRED due to WAITING FOR NEXT FRAME.'
						, strlen($this->rawFrame)
					));
				}

				$this->leftover = $rawBytes;

				return;
			}

			\SeanMorris\Ids\Log::debug(sprintf(
				'Message received: Got %d bytes, %d leftover'
				, strlen($this->rawFrame)
				, strlen($this->leftover)
			));
		}
	}

	protected function fin($rawBytes)
	{
		$type = ord($rawBytes[0]);

		if($type >= 0x80)
		{
			return true;
		}
	}

	protected function dataType($rawBytes)
	{
		$type = ord($rawBytes[0]);

		if($type >= 0x80)
		{
			$type -= 0x80;
		}

		return $type;
	}

	public function encode($content, $typeByte = 0x1)
	{
		$length = strlen($content);

		$typeByte += 0x80;

		if($length < 0x7E)
		{
			$this->encoded .= pack('CC', $typeByte, $length) . $content;
		}
		else if($length < 0x10000)
		{
			$this->encoded .= pack('CCn', $typeByte, 0x7E, $length) . $content;
		}
		else
		{
			$this->encoded .= pack('CCNN', $typeByte, 0x7F, 0, $length) . $content;
		}

		return $this->encoded;
	}
}
