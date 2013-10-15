<?php

require_once('inc/session.inc.php');
require_once('inc/render.inc.php');

Render::setTitle('Wurstgesicht');

Render::parse('main-menu', false);

Render::openTag('h1', array('class' => 'wurst kacke'));
Render::closeTag('h1');

Render::parse('helloworld', array('wurst' => 'käse & bier'));

Render::output();

