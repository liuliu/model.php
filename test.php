<?php

	require_once 'post.php';

	$posts = Model::find(Post, author, array('liu', 'li'));

	var_dump($posts);
