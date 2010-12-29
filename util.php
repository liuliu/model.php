<?php

	function idx($array, $idx)
	{
		return (isset($array[$idx])) ? $array[$idx] : null;
	}

	function edx($array, $idx, $val)
	{
		return (isset($array[$idx])) ? $array[$idx] : $val;
	}

	function unique($len, $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')
	{
		$ret = '';
		for ($i = 0; $i < $len; $i++)
			$ret .= $pool[mt_rand(0, strlen($pool) - 1)];
		return $ret;
	}

	function array_flatten($a)
	{
	    foreach ($a as $k => $v)
	    	$a[$k] = (array)$v;
    	return call_user_func_array(array_merge, $a);
	}