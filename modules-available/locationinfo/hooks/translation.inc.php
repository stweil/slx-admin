<?php

$HANDLER = array();

if (Module::isAvailable('locationinfo')) {
	$HANDLER['subsections'] = array();
	foreach (CourseBackend::getList() as $backend) {
		// Define subsections
		$HANDLER['subsections'][] = 'backend-' . $backend;
		// Grep handlers to detect tags
		$HANDLER['grep_backend-' . $backend] = function($module) use ($backend) {
			$b = CourseBackend::getInstance($backend);
			if ($b === false)
				return array();
			$props = $b->getCredentialDefinitions();
			$return = array();
			foreach ($props as $prop) {
				$return[$prop->property] = true;
				$return[$prop->property . '_helptext'] = true;
			}
			return $return;
		};
	}
}
