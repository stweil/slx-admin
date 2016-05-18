<?php

/*
 * Hook for main page: Show warning if vmstore not configured yet; set "warning" flag if so
 */

if (!is_array(Property::getVmStoreConfig())) {
	Message::addError('vmstore.vmstore-not-configured', true); // Always specify module prefix since this is running in main
	$needSetup = true; // Set $needSetup to true if you want a warning badge to appear in the menu
}
