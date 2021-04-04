<?php
namespace SeanMorris\SubSpace;

class Frame
{
	protected
		$id         = null
		, $leftover = null
		, $decoded  = null
		, $encoded  = null
		, $length   = 0
		, $type     = null
		, $fin      = false
		, $rawFrame = null
		, $masks    = null
		, $typeByte = null
		, $decodeProgress = 0
	;

	const TYPE = [
		'CONTINUE' => 0x0
		, 'TEXT'   => 0x1
		, 'BINARY' => 0x2
		, 'CLOSE'  => 0x8
		, 'PING'   => 0x9
		, 'PONG'   => 0xA
		, 'FIN'    => 0x80
	];

	const SIZE = [
		'SHORT'    => 0x7E
		, 'MEDIUM' => 0x7F
		, 'LONG'   => 0x80
	];

	const FRAME_LENGTH = 0x10000;
	const TYPE_MASK    = 0x80;

	public function __construct($message, $id = NULL)
	{
		$this->message = $message;
		$this->id = $id;
	}

	public static function assemble($origin, $channel, $content, $originalChannel = NULL, $cc = [])
	{
		if(is_numeric($channel->name) || preg_match('/^\d+-\d+$/', $channel->name))
		{
			$typeByte = static::TYPE['BINARY'];

			$static = new static;
			$static->type = $typeByte;

			$header = pack(
				'vvv'
				, $origin ? 1 : 0
				, $origin ? $origin->id : 0
				, $channel->name
			);

			if(is_int($content))
			{
				$content = pack('l', $content);
			}
			else if(is_float($content))
			{
				$content = pack('e', $content);
			}

			$outgoing = $header . $content;
		}
		else
		{
			$typeByte = static::TYPE['TEXT'];

			$static = new static;
			$static->type = $typeByte;

			$originType = NULL;

			if(!$origin)
			{
				$originType = 'server';
				$originId   = NULL;
			}
			else if($origin instanceof \SeanMorris\Kallisti\Agent)
			{
				$originType = 'user';
				$originId   = $origin->id;
			}

			$message = ['message' => $content, 'origin' => $originType];

			if(isset($originId))
			{
				$message['originId'] = $originId;
			}

			if(isset($channel))
			{
				$message['channel'] = $channel->name;

				if(isset($originalChannel) && $channel !== $originalChannel)
				{
					$message['originalChannel'] = $originalChannel;
				}
			}

			if(isset($cc))
			{
				$message['cc'] = $cc;
			}

			$outgoing = json_encode($message);
		}

		$static->decoded = $outgoing;
		$static->fin     = true;

		return $static;
	}

	public static function enc($input, $type = NULL)
	{
		$message = new static;

		$message->encode($input, $type);

		return $message->encoded;
	}

	public static function dec($input)
	{
		$message = new static;

		$message->decode($input);

		return $message->decoded;
	}

	public static function hex($input)
	{
		print join(' ', unpack('C*', $input));
	}

	public function type()
	{
		return $this->type;
	}

	public function decode($rawBytes)
	{
		\SeanMorris\Ids\Log::debug(sprintf('Decoding %d bytes.', strlen($rawBytes)));

		if(strlen($this->rawFrame))
		{
			return $this->continueDecoding($rawBytes);
		}

		$this->type = $this->dataType($rawBytes);
		$this->fin  = $this->fin($rawBytes);

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
					$this->rawFrame = $this->masks . $this->rawFrame;
				}

				for($i = 0; $i < $this->length; ++$i)
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

				if($rawBytes = substr($this->rawFrame, $this->length))
				{
					$this->leftover = $rawBytes;
				}

				\SeanMorris\Ids\Log::debug(sprintf(
					'Stashing %d bytes for next frame.'
					, strlen($this->leftover)
				));

				if(!$this->fin)
				{
					return;
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

		return $this->decoded;
	}

	protected function continueDecoding($rawBytes)
	{
		\SeanMorris\Ids\Log::debug(sprintf(
			'%d bytes in queue. Resuming deferred message...'
			, strlen($rawBytes)
		));

		$remaining = $this->length - strlen($this->rawFrame);

		if($remaining <= 0)
		{
			$this->leftover .= $rawBytes;
			return;
		}

		$this->rawFrame .= $rawBytes;

		$this->leftover = substr($this->rawFrame, $this->length);
		$this->rawFrame = substr($this->rawFrame, 0, $this->length);

		$remaining = $this->length - strlen($this->rawFrame);

		\SeanMorris\Ids\Log::debug(sprintf(
			'Got %d bytes, %d/%d remaining, %d leftover.'
			, strlen($rawBytes)
			, $remaining
			, $this->length
			, strlen($this->leftover)
		));

		if($remaining <= 0)
		{
			if($this->length - strlen($this->rawFrame) > 0)
			{
				if(!strlen($rawBytes))
				{
					\SeanMorris\Ids\Log::debug(sprintf(
						'Got %d bytes. Message DEFERRED due to WAITING FOR NEXT FRAME.'
						, strlen($this->rawFrame)
					));
				}

				return;
			}

			\SeanMorris\Ids\Log::debug(sprintf(
				'Message received: Got %d bytes, %d leftover. Decoding...'
				, strlen($this->rawFrame)
				, strlen($this->leftover)
			));

			$this->decoded = null;

			for ($i = 0; $i < strlen($this->rawFrame); ++$i)
			{
				if(!isset($this->rawFrame[$i]))
				{
					\SeanMorris\Ids\Log::debug(sprintf(
						'Got %d bytes. Message DEFERRED due to WAITING FOR FRAME END.'
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

			\SeanMorris\Ids\Log::debug(sprintf(
				'Decoded %d bytes.'
				, strlen($this->decoded)
			));

			\SeanMorris\Ids\Log::debug(
				'currentLength', strlen($this->rawFrame)
				, 'decodedLength', strlen($this->decoded)
				, 'fullLength', $this->length
				, 'fin', $this->fin
			);
		}
	}

	public function __debugInfo()
	{

		$info = (object) [];

		foreach($this as $prop => $val)
		{
			$info->$prop = $val;

			if(is_scalar($val) && strlen($val) > 256)
			{
				$info->$prop = '[ ... ' . strlen($val) . ' bytes ... ]';
			}
		}

		unset($info->message);

        return $info;
    }

	public function isDone()
	{
		$currentLength = strlen($this->decoded);
		$fullLength    = $this->length;

		return $currentLength >= $fullLength;
	}

	public function isFinal()
	{
		return $this->fin;
	}

	public function content()
	{
		return $this->decoded;
	}

	protected function fin($rawBytes)
	{
		$type = ord($rawBytes[0]);

		if($type >= 0x80)
		{
			\SeanMorris\Ids\Log::debug(sprintf('Frame is final!', $type));
			return true;
		}

		\SeanMorris\Ids\Log::debug(sprintf('Frame is NOT final!', $type));
	}

	protected function dataType($rawBytes)
	{
		$type = ord($rawBytes[0]);


		if($type >= 0x80)
		{
			$type -= 0x80;
		}

		\SeanMorris\Ids\Log::debug(sprintf('Frame type is %b!', $type));

		return $type;
	}

	public function encode($content = NULL, $typeByte = NULL)
	{
		$content  = $content  ?? $this->decoded  ?? NULL;
		$typeByte = $typeByte ?? $this->typeByte ?? 0x1;

		$this->length = strlen($content);

		$this->type = $typeByte;
		$this->fin  = TRUE;

		// $typeByte += 0x80;

		if($this->length < 0x7E)
		{
			$this->encoded .= pack('CC', $typeByte, $this->length) . $content;
		}
		else if($this->length < 0x10000)
		{
			$this->encoded .= pack('CCn', $typeByte, 0x7E, $this->length) . $content;
		}
		else
		{
			$this->encoded .= pack('CCNN', $typeByte, 0x7F, 0, $this->length) . $content;
		}

		return $this->encoded;
	}

	public function encoded()
	{
		return $this->encoded;
	}

	public function leftover()
	{
		return $this->leftover;
	}
}
