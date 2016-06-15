<?php

$res = array();

$res[] = tableCreate('setting_partition', "
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `partition_id` varchar(110) NOT NULL,
  `size` varchar(110) NOT NULL,
  `mount_point` varchar(110) NOT NULL,
  `options` varchar(110) NOT NULL,
  `user` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user` (`user`)
");

if (in_array(UPDATE_DONE, $res)) {
	Database::exec("ALTER TABLE `setting_partition`
		ADD CONSTRAINT `setting_partition_ibfk_1` FOREIGN KEY (`user`) REFERENCES `user` (`userid`)");
}

// Update path

// -- none --

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
