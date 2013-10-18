<?php

User::load();

function render_module()
{
	// Render::setTitle('abc');
	
	Render::openTag('h1', array('class' => 'wurst kacke'));
	Render::closeTag('h1');
	
	if (!User::isLoggedIn()) {
		Render::addTemplate('page-main-guest');
		return;
	}
	// Logged in here
	Render::addTemplate('page-main', array('user' => User::getName()));
}

