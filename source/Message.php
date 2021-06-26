<?php
namespace SeanMorris\SubSpace;

class Message
{
	static $Frame = Frame::Class;

	protected
		$id         = null
		, $decoded  = null
	 	, $encoded  = null
	 	, $frames   = []
		, $typeByte = null
		, $maxPubSize = 0
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

	public function __construct($content = NULL, $type = NULL, $id = NULL)
	{
		$this->decoded  = $content;
		$this->typeByte = $type;
		$this->id       = $id;
	}

	public function decode($rawBytes)
	{
		$frame = $this->getFillableFrame();

		if($rawBytes)
		{
			$decoded = $frame->decode($rawBytes);
		}

		if($type = $frame->type())
		{
			$this->type = $type;
		}

		return $decoded;
	}

	public function content()
	{
		if($this->decoded)
		{
			return $this->decoded;
		}

		$contents = array_map(
			function($frame) { return $frame->content(); }
			, $this->frames
		);

		$this->decoded = implode(NULL, $contents);

		return $this->decoded;
	}

	public function leftover()
	{
		if($lastFrame = $this->getLastFrame())
		{
			return $lastFrame->leftover();
		}
	}

	public function isDone()
	{
		if(!$lastFrame = $this->getLastFrame())
		{
			return;
		}

		return $lastFrame->isFinal() && $lastFrame->isDone();
	}

	protected function getFillableFrame()
	{
		$frameId = count($this->frames) - 1;

		if($frameId < 0)
		{
			$frame = new static::$Frame($this);

			$this->frames[] = $frame;

			return $frame;
		}

		$frame = $this->frames[$frameId];

		if($frame->isDone())
		{
			$newFrame = new static::$Frame($this);

			$frame->leftover() && $newFrame->decode( $frame->leftover() );

			$this->frames[] = $newFrame;

			return $newFrame;
		}

		return $frame;
	}

	public function frameCount()
	{
		return count($this->frames);
	}

	protected function getLastFrame()
	{
		$frameId = count($this->frames) - 1;

		if($frameId < 0)
		{
			return;
		}

		$frame   = $this->frames[$frameId];

		if($this->frames[$frameId])
		{
			return $this->frames[$frameId];
		}
	}

	public static function assemble($origin, $channel, $content, $originalChannel = NULL, $cc = [])
	{
		if(is_numeric($channel->name) || preg_match('/^\d+-\d+$/', $channel->name))
		{
			$typeByte = static::TYPE['BINARY'];

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

		$static = new static;
		$static->fin = true;

		$static->encode($outgoing, $typeByte);

		return $static;
	}

	public function type()
	{
		return $this->type;
	}

	public static function enc($input, $type = NULL)
	{
		$message = new static;

		$message->encode($input, $type);

		return $message->encoded;
	}

	public function encode($content = NULL, $typeByte = NULL)
	{
		$content  = $content  ?? $this->decoded  ?? NULL;
		$typeByte = $typeByte ?? $this->typeByte ?? 0x81;

		$this->length = strlen($content);
		$this->type   = $typeByte;

		$typeByte += 0x80;

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

    public function length()
    {
    	$lengths = array_map(
			function($frame) { return $frame->length(); }
			, $this->frames
		);

		return array_sum($lengths);
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

		$info->frames = [];

		foreach ($this->frames as $i => $frame)
		{
			$info->frames[$i] = $frame->{__FUNCTION__}();
		}

        return (array) $info;
    }
}
