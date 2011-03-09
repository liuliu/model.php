<?php

	require_once 'post.php';

	$posts = Model::all(Post); // Model::find(Post, coauthor, 'li');
	var_dump($posts);

	$post = new Post('12345');
	$post->author = 'liu';
	$post->page = 'two';
	$post->save();
