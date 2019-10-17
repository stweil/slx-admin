<?php

$result[] = tableCreate('minilinux_source', "
  `sourceid` varchar(8) CHARACTER SET ascii NOT NULL,
  `title` varchar(100) NOT NULL,
  `url` varchar(200) NOT NULL,
  `lastupdate` int(10) UNSIGNED NOT NULL,
  `taskid` char(36) CHARACTER SET ascii DEFAULT NULL,
  `pubkey` blob NOT NULL,
  PRIMARY KEY (`sourceid`),
  KEY (`title`, `sourceid`)
");
$result[] = tableCreate('minilinux_branch', "
  `sourceid` varchar(8) CHARACTER SET ascii DEFAULT NULL,
  `branchid` varchar(40) CHARACTER SET ascii NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` blob NOT NULL,
  PRIMARY KEY (`branchid`),
  KEY (`title`)
");
$result[] = tableCreate('minilinux_version', "
  `branchid` varchar(40) CHARACTER SET ascii NOT NULL,
  `versionid` varchar(72) CHARACTER SET ascii NOT NULL,
  `title` varchar(100) NOT NULL,
  `dateline` int(10) UNSIGNED NOT NULL,
  `data` blob NOT NULL,
  `orphan` tinyint(3) UNSIGNED NOT NULL,
  `taskid` char(36) CHARACTER SET ascii DEFAULT NULL,
  `installed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`versionid`),
  KEY (`title`),
  KEY (`branchid`, `dateline`, `versionid`),
  KEY (`branchid`, `installed`, `dateline`)
");

$result[] = tableAddConstraint('minilinux_version', 'branchid', 'minilinux_branch', 'branchid',
	'ON UPDATE CASCADE ON DELETE RESTRICT');

$result[] = tableAddConstraint('minilinux_branch', 'sourceid', 'minilinux_source', 'sourceid',
	'ON UPDATE CASCADE ON DELETE SET NULL');

responseFromArray($result);
