<?php

Trigger::ldadp();

// Cleanup orphaned config<->location where the location has been deleted
Database::exec("DELETE c FROM configtgz_location c
			LEFT JOIN location l USING (locationid)
			WHERE l.locationid IS NULL");
