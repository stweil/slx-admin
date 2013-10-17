<?php

User::load();

function render_module()
{
	Render::setTitle('Wurstgesicht');
	
	Render::openTag('h1', array('class' => 'wurst kacke'));
	Render::closeTag('h1');
	
	Render::addTemplate('helloworld', array('wurst' => 'käse & bier'));
}

