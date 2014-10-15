<?php

if (!isLocalExecution())
	die('Nope');

if ($argc < 3)
	die("Not enough parameters");

switch ($argc[1]) {
case 'info':
	EventLog::info($argc[2]);
	break;
case 'warning':
	EventLog::warning($argc[2]);
	break;
case 'failure':
	EventLog::failure($argc[2]);
	break;
}

