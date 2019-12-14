<?php

$result = array();

$result[] = tableCreate('serversetup_bootentry', "
  `entryid` varchar(16) CHARACTER SET ascii NOT NULL,
  `hotkey` varchar(8) CHARACTER SET ascii NOT NULL,
  `title` varchar(100) NOT NULL,
  `builtin` tinyint(1) NOT NULL,
  `module` varchar(30) DEFAULT NULL,
  `data` blob NOT NULL,
  PRIMARY KEY (`entryid`)
");

$result[] = tableCreate('serversetup_menu', "
  `menuid` int(11) NOT NULL AUTO_INCREMENT,
  `timeoutms` int(10) unsigned NOT NULL,
  `title` varchar(100) NOT NULL COMMENT 'Escaped/Sanitized for iPXE!',
  `defaultentryid` int(11) DEFAULT NULL,
  `isdefault` tinyint(1) NOT NULL,
  PRIMARY KEY (`menuid`),
  KEY `defaultentryid` (`defaultentryid`),
  KEY `isdefault` (`isdefault`)
");

$result[] = tableCreate('serversetup_menuentry', "
  `menuentryid` int(11) NOT NULL AUTO_INCREMENT,
  `menuid` int(11) NOT NULL,
  `entryid` varchar(16) CHARACTER SET ascii NULL COMMENT 'If NULL, entry is gap or another menu',
  `refmenuid` int(11) DEFAULT NULL COMMENT 'If entryid is NULL this can be a ref to another menu',
  `hotkey` varchar(8) CHARACTER SET ascii NOT NULL,
  `title` varchar(100) NOT NULL COMMENT 'Sanitize this before insert',
  `hidden` tinyint(1) NOT NULL,
  `sortval` int(11) NOT NULL,
  `plainpass` varchar(80) NOT NULL,
  `md5pass` char(32) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (`menuentryid`),
  KEY `menuid` (`menuid`,`sortval`),
  KEY `entryid` (`entryid`)
");

$result[] = tableCreate('serversetup_menu_location', '
  `menuid` int(11) NOT NULL,
  `locationid` int(11) NOT NULL,
  `defaultentryid` int(11) DEFAULT NULL,
  PRIMARY KEY (`menuid`,`locationid`),
  UNIQUE `locationid` (`locationid`),
  KEY `defaultentryid` (`defaultentryid`)
');

$result[] = tableCreate('serversetup_localboot', "
  `systemmodel` varchar(120) NOT NULL,
  `pcbios` varchar(16) CHARACTER SET ascii DEFAULT NULL,
  `efi` varchar(16) CHARACTER SET ascii DEFAULT NULL,
  PRIMARY KEY (`systemmodel`)
");

// Add defaultentry override column
if (!tableHasColumn('serversetup_menu_location', 'defaultentryid')) {
	if (Database::exec('ALTER TABLE serversetup_menu_location ADD COLUMN `defaultentryid` int(11) DEFAULT NULL,
		ADD KEY `defaultentryid` (`defaultentryid`)') !== false) {
		$result[] = UPDATE_DONE;
	} else {
		$result[] = UPDATE_FAILED;
	}
}

$result[] = tableAddConstraint('serversetup_menu', 'defaultentryid', 'serversetup_menuentry', 'menuentryid',
	'ON DELETE SET NULL');

$result[] = tableAddConstraint('serversetup_menuentry', 'entryid', 'serversetup_bootentry', 'entryid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

$result[] = tableAddConstraint('serversetup_menuentry', 'menuid', 'serversetup_menu', 'menuid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

$result[] = tableAddConstraint('serversetup_menu_location', 'menuid', 'serversetup_menu', 'menuid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

$result[] = tableAddConstraint('serversetup_menu_location', 'defaultentryid', 'serversetup_menuentry', 'menuentryid',
	'ON UPDATE CASCADE ON DELETE SET NULL');

// 2019-03-19 Add refmenuid to have cascaded menus
if (!tableHasColumn('serversetup_menuentry', 'refmenuid')) {
	if (Database::exec("ALTER TABLE serversetup_menuentry ADD COLUMN `refmenuid` int(11) DEFAULT NULL COMMENT 'If entryid is NULL this can be a ref to another menu'") !== false) {
		$result[] = UPDATE_DONE;
	} else {
		$result[] = UPDATE_FAILED;
	}
}

// 2019-03-26 Make localboot config distinct for efi and bios
if (!tableHasColumn('serversetup_localboot', 'pcbios')) {
	if (Database::exec("ALTER TABLE serversetup_localboot DROP COLUMN `bootmethod`,
	ADD COLUMN `pcbios` varchar(16) CHARACTER SET ascii DEFAULT NULL, ADD COLUMN `efi` varchar(16) CHARACTER SET ascii DEFAULT NULL") !== false) {
		$result[] = UPDATE_DONE;
	} else {
		$result[] = UPDATE_FAILED;
	}
}

$result[] = tableAddConstraint('serversetup_menuentry', 'refmenuid', 'serversetup_menu', 'menuid',
	'ON UPDATE CASCADE ON DELETE SET NULL');

if (Module::get('location') !== false) {
	if (!tableExists('location')) {
		$result[] = UPDATE_RETRY;
	} else {
		$result[] = tableAddConstraint('serversetup_menu_location', 'locationid', 'location', 'locationid',
			'ON UPDATE CASCADE ON DELETE CASCADE');
	}
}

// 2019-09-21 Add module column to bootentry
if (!tableHasColumn('serversetup_bootentry', 'module')) {
	if (Database::exec("ALTER TABLE serversetup_bootentry
	ADD COLUMN `module` varchar(30) CHARACTER SET ascii DEFAULT NULL AFTER `builtin`") !== false) {
		$result[] = UPDATE_DONE;
		$res = Database::simpleQuery('SELECT entryid, data FROM serversetup_bootentry WHERE module IS NULL');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$json = json_decode($row['data'], true);
			if (isset($json['script'])) {
				Database::exec("UPDATE serversetup_bootentry SET module = '.script' WHERE entryid = :id", ['id' => $row['entryid']]);
			} else {
				Database::exec("UPDATE serversetup_bootentry SET module = '.exec' WHERE entryid = :id", ['id' => $row['entryid']]);
			}
		}
	} else {
		$result[] = UPDATE_FAILED;
	}
}

if (Module::isAvailable('serversetup')) {
	IPxe::createDefaultEntries();
}

responseFromArray($result);
