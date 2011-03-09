<?php
	require_once 'odm.php';
	require_once 'util.php';

	/* Model Types (first element of array):
	   integer, number, boolean, string,
	   array, serializable,
	   atomic,
	   index, multi-index, primary, unique, cluster-record,
	   Model Prototype/Defaults (second element of array):
	   anything that is newable with default constructor (e.g. new Point()) as prototype,
	   or any valid input as default,
	   Model Modifier (third element of array):
	   private */

	class Atomic
	{
		private $identifier;

		public function get($pipe = null)
		{
			if (isset($pipe))
				return $pipe->get($this->identifier);
			else {
				global $odm;
				return $odm->get($this->identifier);
			}
		}

		public function set($val, $pipe = null)
		{
			if (isset($pipe))
				return $pipe->set($this->identifier, $val);
			else {
				global $odm;
				return $odm->set($this->identifier, $val);
			}
		}

		public function incrby($val, $pipe = null)
		{
			if (isset($pipe))
				return $pipe->incrby($this->identifier, $val);
			else {
				global $odm;
				return $odm->incrby($this->identifier, $val);
			}
		}

		public function decrby($val, $pipe = null)
		{
			if (isset($pipe))
				return $pipe->decrby($this->identifier, $val);
			else {
				global $odm;
				return $odm->decrby($this->identifier, $val);
			}
		}

		public function __construct($identifier = null)
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
		private $multi_index;
		private $cluster_record;

		public function exists()
		{
			return $this->existence;
		}

		private function toArrayInternal()
		{
			$result = array();
			foreach ($this->field as $k => $v)
				if ($v[0] != 'atomic')
					$result[$k] = $this->$k;
			return $result;
		}

		public function toArray()
		{
			$result = array();
			foreach ($this->field as $k => $v)
				if (!isset($v[2]) || $v[2] != 'private')
				{
					if ($v[0] != 'atomic')
						$result[$k] = $this->$k;
					else
						$result[$k] = $this->$k->get();
				}
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
					case 'multi-index':
						$this->$k = array_unique(edx($array, $k, isset($v[1]) ? $v[1] : array()));
					case 'primary':
					case 'index':
					case 'unique':
					case 'cluster-record':
					default:
						$this->$k = edx($array, $k, $v[1]);
				}
		}

		private function init($uuid)
		{
			foreach ($this->field as $k => $v)
				if ($v[0] ==  'atomic')
					$this->$k = new Atomic("{$this->className}->{$k}({$uuid})");
			if (isset($this->primary))
			{
				$primary = $this->primary;
				$this->$primary = $uuid;
			}
		}

		public static function cast($class, $uuids)
		{
			if (is_array($uuids) && count($uuids) > 0)
			{
				global $odm;
				$keys = array();
				foreach ($uuids as $uuid)
					$keys[] = "{$class}->fetch({$uuid})";
				$values = $odm->mget($keys);
				$all = array();
				foreach ($values as $k => $value)
				{
					$instance = new $class(unserialize($value));
					$instance->init($uuids[$k]);
					$all[] = $instance;
				}
				return $all;
			}
		}

		public static function all($class, $uuid = '')
		{
			global $odm;
			$prefix = "{$class}->fetch(";
			$len = strlen($prefix);
			$keys = $odm->keys("{$prefix}{$uuid}*");
			foreach ($keys as $k => $v)
				$keys[$k] = substr($v, strpos($v, $prefix) + $len, strlen($v) - $len - 1);
			return $keys;
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
						case 'multi-index':
							$replies = $odm->pipeline(function ($pipe) use ($class, $member, $indexes) {
								foreach ($indexes as $v)
									$pipe->smembers("{$class}->multiIndexOf({$member},{$v})");
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
					case 'multi-index':
						return $odm->smembers("{$class}->multiIndexOf({$member},{$indexes})");
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
			$this->init($uuid);
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
				$this->multi_index = self::$scanCache[$this->className]['multi-index'];
				$this->cluster_record = self::$scanCache[$this->className]['cluster-record'];
			} else {
				self::$scanCache[$this->className] = apc_fetch("model.php::scanCache[{$this->className}]", $success);
				if ($success)
				{
					$this->field = self::$scanCache[$this->className]['field'];
					$this->index = self::$scanCache[$this->className]['index'];
					$this->unique = self::$scanCache[$this->className]['unique'];
					$this->primary = self::$scanCache[$this->className]['primary'];
					$this->multi_index = self::$scanCache[$this->className]['multi-index'];
					$this->cluster_record = self::$scanCache[$this->className]['cluster-record'];
				} else {
					$field = array();
					$index = array();
					$unique = array();
					$multi_index = array();
					$cluster_record = array();
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
								case 'multi-index':
									$multi_index[] = $k;
									break;
								case 'cluster-record':
									$cluster_record[] = $k;
							}
						}
					$this->field = $field;
					$this->index = $index;
					$this->unique = $unique;
					$this->multi_index = $multi_index;
					$this->cluster_record = $cluster_record;
					self::$scanCache[$this->className] = array('field' => $field,
															   'index' => $index,
															   'unique' => $unique,
															   'multi-index' => $multi_index,
															   'cluster-record' => $cluster_record,
															   'primary' => $this->primary);
					apc_store("model.php::scanCache[{$this->className}]", self::$scanCache[$this->className]);
				}
			}
		}

		public function __construct($uuid)
		{
			$this->className = get_class($this);
			$this->scan();
			if (is_array($uuid))
				$this->fromArray($uuid);
			else if (isset($uuid))
				$this->fetch($uuid);
		}

		public function save()
		{
			global $odm;
			$primary = $this->primary;
			if (isset($this->primary))
			{
				$primary = $this->primary;
				if (count($this->index) == 0 && count($this->unique) == 0 && count($this->multi_index) == 0 && count($this->cluster_record) == 0)
				{
					$odm->set("{$this->className}->fetch({$this->$primary})", serialize($this->toArrayInternal()));
				} else {
					$uuid = $this->$primary;
					$className = $this->className;
					$kvidx = array();
					foreach ($this->index as $v)
						$kvidx[$v] = $this->$v;
					$kvunq = array();
					foreach ($this->unique as $v)
						$kvunq[$v] = $this->$v;
					$kvmdx = array();
					foreach ($this->multi_index as $v)
					{
						$this->$v = isset($this->$v) ? array_unique($this->$v) : array();
						$kvmdx[$v] = $this->$v;
					}
					$kvcr = array();
					foreach ($this->cluster_record as $v)
						$kvcr[$v] = $this->$v;
					$serialized = serialize($this->toArrayInternal());
					$rawData = $this->rawData;
					$odm->pipeline(function ($pipe) use ($className, $uuid, $serialized, $rawData, $kvidx, $kvunq, $kvmdx, $kvcr) {
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
						foreach ($kvmdx as $k => $v)
						{
							$o = isset($rawData[$k]) ? $rawData[$k] : array();
							$add = array_diff($v, $o);
							$rem = array_diff($o, $v);
							foreach ($add as $av)
								$pipe->sadd("{$className}->multiIndexOf({$k},{$av})", $uuid);
							foreach ($rem as $rv)
								$pipe->srem("{$className}->multiIndexOf({$k},{$rv})", $uuid);
						}
						foreach ($kvcr as $k => $v)
							if ($rawData[$k] !== $v)
							{
								$pipe->incrby("{$className}->recordOf({$k},{$v})", 1);
								if (!empty($rawData[$k]))
									$pipe->decrby("{$className}->recordOf({$k},{$rawData[$k]})", 1);
							}
					});
				}
				$this->existence = true;
			}
		}
	}
