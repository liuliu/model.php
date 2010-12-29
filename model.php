<?php
	require_once 'odm.php';
	require_once 'util.php';

	class Atomic
	{
		private $identifier;

		public function get($pipe)
		{
			if (isset($pipe))
				return $pipe->get($this->identifier);
			else {
				global $odm;
				return $odm->get($this->identifier);
			}
		}

		public function set($val, $pipe)
		{
			if (isset($pipe))
				return $pipe->set($this->identifier, $val);
			else {
				global $odm;
				return $odm->set($this->identifier, $val);
			}
		}

		public function incrby($val, $pipe)
		{
			if (isset($pipe))
				return $pipe->incrby($this->identifier, $val);
			else {
				global $odm;
				return $odm->incrby($this->identifier, $val);
			}
		}

		public function decrby($val, $pipe)
		{
			if (isset($pipe))
				return $pipe->decrby($this->identifier, $val);
			else {
				global $odm;
				return $odm->decrby($this->identifier, $val);
			}
		}

		public function __construct($identifier)
		{
			$this->identifier = $identifier;
		}
	}

	class Model
	{
		public $rawData;

		private $existence;
		private $field;
		private $className;
		private $primary;
		private $index;
		private $unique;

		public function exists()
		{
			return $this->existence;
		}

		public function toArray()
		{
			$result = array();
			foreach ($this->field as $k => $v)
				$result[$k] = $this->$k;
			return $result;
		}

		public function fromArray($array)
		{
			foreach ($this->field as $k => $v)
				switch ($v[0])
				{
					case 'integer':
						$this->$k = intval(edx($array, $k, $v[1]));
						break;
					case 'number':
						$this->$k = floatval(edx($array, $k, $v[1]));
						break;
					case 'boolean':
						$this->$k = edx($array, $k, $v[1]) ? true : false;
						break;
					case 'string':
						$this->$k = strval(edx($array, $k, $v[1]));
						break;
					case 'array':
						$this->$k = edx($array, $k, isset($v[1]) ? $v[1] : array());
						break;
					case 'serializable':
						$this->$k = edx($array, $k, new $v[1]());
						break;
					case 'atomic':
						// escape if atomic
						break;
					case 'primary':
						// escape if primary key
						break;
					case 'index':
					case 'unique':
					default:
						$this->$k = edx($array, $k, $v[1]);
				}
		}

		public static function all($class, $uuids)
		{
			if (is_array($uuids) && count($uuids) > 0)
			{
				global $odm;
				$keys = array();
				foreach ($uuids as $uuid)
					$keys[] = "{$class}->fetch({$uuid})";
				$values = $odm->mget($keys);
				$all = array();
				foreach ($values as $value)
					$all[] = new $class(unserialize($value));
				return $all;
			}
		}

		// cache a specific instance of subclass s.t. some static
		// functions can derive property type from instance directly
		// Q: why not use $scanCache?
		// A: $scanCache is not always available, if it doesn't,
		//    requires one to initiate an instance to fill up, thus,
		//    has no difference from create $instanceCache
		private static $instanceCache = array();

		public static function find($class, $member, $indexes)
		{
			global $odm;
			if (!isset(self::$instanceCache[$class]))
				self::$instanceCache[$class] = new $class();
			$type = self::$instanceCache[$class]->field[$member][0];
			if (is_array($indexes))
			{
				if (count($indexes) > 0)
				{
					switch ($type)
					{
						case 'index':
							$replies = $odm->pipeline(function ($pipe) use ($class, $member, $indexes) {
								foreach ($indexes as $v)
									$pipe->smembers("{$class}->indexOf({$member},{$v})");
							});
							break;
						case 'unique':
							$keys = array();
							foreach ($indexes as $v)
								$keys[] = "{$class}->uniqueOf({$member},{$v})";
							$replies = $odm->mget($keys);
							break;
					}
					return $replies;
				}
			} else {
				switch ($type)
				{
					case 'index':
						return $odm->smembers("{$class}->indexOf({$member},{$indexes})");
					case 'unique':
						return $odm->get("{$class}->uniqueOf({$member},{$indexes})");
				}
			}
		}

		public function fetch($uuid)
		{
			global $odm;
			$rawData = $odm->get("{$this->className}->fetch({$uuid})");
			if (isset($rawData))
			{
				$this->rawData = unserialize($rawData);
				$this->existence = true;
			} else {
				$this->rawData = unserialize($odm->get("{$this->className}->fetch(0)"));
				$this->existence = false;
			}
			$this->fromArray($this->rawData);
			foreach ($this->field as $k => $v)
				if ($v[0] ==  'atomic')
					$this->$k = new Atomic("{$this->className}->{$k}({$uuid})");
			if (isset($this->primary))
			{
				$primary = $this->primary;
				$this->$primary = $uuid;
			}
		}

		// cache scan result so that following operation can be quicker
		private static $scanCache = array();

		private function scan()
		{
			if (isset(self::$scanCache[$this->className]))
			{
				$this->field = self::$scanCache[$this->className]['field'];
				$this->index = self::$scanCache[$this->className]['index'];
				$this->unique = self::$scanCache[$this->className]['unique'];
				$this->primary = self::$scanCache[$this->className]['primary'];
			} else {
				$field = array();
				$index = array();
				$unique = array();
				foreach ($this as $k => $v)
					if (is_array($v))
					{
						$field[$k] = $v;
						switch ($v[0])
						{
							case 'primary':
								$this->primary = $k;
								break;
							case 'index':
								$index[] = $k;
								break;
							case 'unique':
								$unique[] = $k;
								break;
						}
					}
				$this->field = $field;
				$this->index = $index;
				$this->unique = $unique;
				self::$scanCache[$this->className] = array('field' => $field,
														   'index' => $index,
														   'unique' => $unique,
														   'primary' => $this->primary);
			}
		}

		public function __construct($uuid)
		{
			$this->className = get_class($this);
			$this->scan();
			if (is_array($uuid))
				$this->fromArray($uuid);
			else
				$this->fetch($uuid);
		}

		public function save()
		{
			global $odm;
			$primary = $this->primary;
			if (isset($this->primary))
			{
				$primary = $this->primary;
				if (count($this->index) == 0 && count($this->unique) == 0)
				{
					$odm->set("{$this->className}->fetch({$this->$primary})", serialize($this->toArray()));
				} else {
					$uuid = $this->$primary;
					$className = $this->className;
					$serialized = serialize($this->toArray());
					$kvidx = array();
					foreach ($this->index as $v)
						$kvidx[$v] = $this->$v;
					$kvunq = array();
					foreach ($this->unique as $v)
						$kvunq[$v] = $this->$v;
					$rawData = $this->rawData;
					$odm->pipeline(function ($pipe) use ($className, $uuid, $serialized, $rawData, $kvidx, $kvunq) {
						$pipe->set("{$className}->fetch({$uuid})", $serialized);
						foreach ($kvidx as $k => $v)
							if ($rawData[$k] !== $v)
							{
								$pipe->sadd("{$className}->indexOf({$k},{$v})", $uuid);
								if (!empty($rawData[$k]))
									$pipe->srem("{$className}->indexOf({$k},{$rawData[$k]})", $uuid);
							}
						foreach ($kvunq as $k => $v)
							if ($rawData[$k] !== $v)
							{
								$pipe->set("{$className}->uniqueOf({$k},{$v})", $uuid);
								if (!empty($rawData[$k]))
									$pipe->del("{$className}->uniqueOf({$k},{$rawData[$k]})");
							}
					});
				}
				$this->existence = true;
			}
		}
	}
