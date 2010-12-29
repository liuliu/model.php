<?php

	require_once('Predis.php');

	$odm = new Predis\Client('redis://localhost:6379');
