SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;


CREATE TABLE `callback` (
  `taskid` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dateline` int(10) unsigned NOT NULL,
  `cbfunction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `args` text NOT NULL,
  PRIMARY KEY (`taskid`,`cbfunction`),
  KEY `dateline` (`dateline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `cat_setting` (
  `catid` int(10) unsigned NOT NULL,
  `sortval` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`catid`),
  KEY `sortval` (`sortval`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `clientlog` (
  `logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `logtypeid` varchar(30) NOT NULL,
  `clientip` varchar(40) NOT NULL,
  `description` varchar(255) NOT NULL,
  `extra` text NOT NULL,
  PRIMARY KEY (`logid`),
  KEY `dateline` (`dateline`),
  KEY `logtypeid` (`logtypeid`,`dateline`),
  KEY `clientip` (`clientip`,`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `configtgz` (
  `configid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `filepath` varchar(255) NOT NULL,
  `status` enum('OK','OUTDATED','MISSING') NOT NULL DEFAULT 'MISSING',
  PRIMARY KEY (`configid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `configtgz_module` (
  `moduleid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `moduletype` varchar(16) NOT NULL,
  `filepath` varchar(250) NOT NULL,
  `contents` text NOT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT '0',
  `status` enum('OK','MISSING','OUTDATED') NOT NULL DEFAULT 'MISSING',
  PRIMARY KEY (`moduleid`),
  KEY `title` (`title`),
  KEY `moduletype` (`moduletype`,`title`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `configtgz_x_module` (
  `configid` int(10) unsigned NOT NULL,
  `moduleid` int(10) unsigned NOT NULL,
  PRIMARY KEY (`configid`,`moduleid`),
  KEY `moduleid` (`moduleid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `eventlog` (
  `logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `logtypeid` varchar(30) NOT NULL,
  `description` varchar(255) NOT NULL,
  `extra` text NOT NULL,
  PRIMARY KEY (`logid`),
  KEY `dateline` (`dateline`),
  KEY `logtypeid` (`logtypeid`,`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `news` (
  `newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `content` text,
  PRIMARY KEY (`newsid`),
  KEY `dateline` (`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `permission` (
  `mask` int(10) unsigned NOT NULL,
  `identifier` varchar(32) NOT NULL,
  PRIMARY KEY (`mask`),
  UNIQUE KEY `identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `property` (
  `name` varchar(50) NOT NULL,
  `dateline` int(10) unsigned NOT NULL DEFAULT '0',
  `value` text NOT NULL,
  PRIMARY KEY (`name`),
  KEY `dateline` (`dateline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `setting` (
  `setting` varchar(28) NOT NULL,
  `catid` int(10) unsigned NOT NULL,
  `defaultvalue` text NOT NULL,
  `permissions` int(10) unsigned NOT NULL,
  `validator` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`setting`),
  KEY `catid` (`catid`,`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `setting_distro` (
  `distroid` int(10) unsigned NOT NULL,
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  `displayvalue` text NOT NULL,
  PRIMARY KEY (`distroid`,`setting`),
  KEY `setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `setting_global` (
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  `displayvalue` text NOT NULL,
  PRIMARY KEY (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `statistic` (
  `logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `typeid` varchar(30) NOT NULL,
  `clientip` varchar(40) NOT NULL,
  `username` varchar(255) NOT NULL,
  `data` varchar(255) NOT NULL,
  PRIMARY KEY (`logid`),
  KEY `dateline` (`dateline`),
  KEY `logtypeid` (`typeid`,`dateline`),
  KEY `clientip` (`clientip`,`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `user` (
  `userid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(100) NOT NULL,
  `passwd` varchar(150) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `permissions` int(10) unsigned NOT NULL,
  `lasteventid` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`userid`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


ALTER TABLE `configtgz_x_module`
  ADD CONSTRAINT `configtgz_x_module_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `configtgz` (`configid`) ON DELETE CASCADE,
  ADD CONSTRAINT `configtgz_x_module_ibfk_2` FOREIGN KEY (`moduleid`) REFERENCES `configtgz_module` (`moduleid`);

ALTER TABLE `setting`
  ADD CONSTRAINT `setting_ibfk_1` FOREIGN KEY (`catid`) REFERENCES `cat_setting` (`catid`) ON UPDATE CASCADE;

ALTER TABLE `setting_distro`
  ADD CONSTRAINT `setting_distro_ibfk_1` FOREIGN KEY (`setting`) REFERENCES `setting` (`setting`) ON DELETE CASCADE ON UPDATE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
