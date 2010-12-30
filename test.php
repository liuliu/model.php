<?php

	require_once 'post.php';

	$posts = Model::all(Post); // Model::find(Post, coauthor, 'li');

	var_dump($posts);
