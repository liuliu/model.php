<?php

	require_once 'model.php';

	class Post extends Model
	{
		public $uuid = array('primary');
		public $title = array('string');
		public $date = array('integer');
		public $author = array('index');
		public $key = array('unique');

		public function __construct($uuid)
		{
			parent::__construct($uuid);
		}
	}
