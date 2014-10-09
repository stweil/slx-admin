-- phpMyAdmin SQL Dump
-- version 4.0.8
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Jun 13, 2014 at 05:09 PM
-- Server version: 5.5.35-0ubuntu0.12.04.2
-- PHP Version: 5.3.10-1ubuntu3.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: `openslx`
--

-- --------------------------------------------------------

--
-- Table structure for table `cat_setting`
--

CREATE TABLE IF NOT EXISTS `cat_setting` (
  `catid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(250) NOT NULL,
  `sortval` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`catid`),
  KEY `sortval` (`sortval`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `clientlog`
--

CREATE TABLE IF NOT EXISTS `clientlog` (
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

-- --------------------------------------------------------

--
-- Table structure for table `configtgz`
--

CREATE TABLE IF NOT EXISTS `configtgz` (
  `configid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `filepath` varchar(255) NOT NULL,
  PRIMARY KEY (`configid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `configtgz_module`
--

CREATE TABLE IF NOT EXISTS `configtgz_module` (
  `moduleid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `moduletype` varchar(16) NOT NULL,
  `filepath` varchar(250) NOT NULL,
  `contents` text NOT NULL,
  PRIMARY KEY (`moduleid`),
  KEY `title` (`title`),
  KEY `moduletype` (`moduletype`,`title`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `configtgz_x_module`
--

CREATE TABLE IF NOT EXISTS `configtgz_x_module` (
  `configid` int(10) unsigned NOT NULL,
  `moduleid` int(10) unsigned NOT NULL,
  PRIMARY KEY (`configid`,`moduleid`),
  KEY `moduleid` (`moduleid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `news`
--

CREATE TABLE IF NOT EXISTS `news` (
  `newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `content` text,
  PRIMARY KEY (`newsid`),
  KEY `dateline` (`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `permission`
--

CREATE TABLE IF NOT EXISTS `permission` (
  `mask` int(10) unsigned NOT NULL,
  `identifier` varchar(32) NOT NULL,
  `description` text NOT NULL,
  PRIMARY KEY (`mask`),
  UNIQUE KEY `identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `property`
--

CREATE TABLE IF NOT EXISTS `property` (
  `name` varchar(50) NOT NULL,
  `dateline` int(10) unsigned NOT NULL DEFAULT '0',
  `value` text NOT NULL,
  PRIMARY KEY (`name`),
  KEY `dateline` (`dateline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `setting`
--

CREATE TABLE IF NOT EXISTS `setting` (
  `setting` varchar(28) NOT NULL,
  `catid` int(10) unsigned NOT NULL,
  `defaultvalue` text NOT NULL,
  `permissions` int(10) unsigned NOT NULL,
  `validator` varchar(250) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  PRIMARY KEY (`setting`),
  KEY `catid` (`catid`,`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `setting_distro`
--

CREATE TABLE IF NOT EXISTS `setting_distro` (
  `distroid` int(10) unsigned NOT NULL,
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`distroid`,`setting`),
  KEY `setting` (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `setting_global`
--

CREATE TABLE IF NOT EXISTS `setting_global` (
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE IF NOT EXISTS `user` (
  `userid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(100) NOT NULL,
  `passwd` varchar(150) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `permissions` int(10) unsigned NOT NULL,
  PRIMARY KEY (`userid`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `configtgz_x_module`
--
ALTER TABLE `configtgz_x_module`
  ADD CONSTRAINT `configtgz_x_module_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `configtgz` (`configid`) ON DELETE CASCADE,
  ADD CONSTRAINT `configtgz_x_module_ibfk_2` FOREIGN KEY (`moduleid`) REFERENCES `configtgz_module` (`moduleid`);

--
-- Constraints for table `setting`
--
ALTER TABLE `setting`
  ADD CONSTRAINT `setting_ibfk_1` FOREIGN KEY (`catid`) REFERENCES `cat_setting` (`catid`) ON UPDATE CASCADE;

--
-- Constraints for table `setting_distro`
--
ALTER TABLE `setting_distro`
  ADD CONSTRAINT `setting_distro_ibfk_1` FOREIGN KEY (`setting`) REFERENCES `setting` (`setting`) ON DELETE CASCADE ON UPDATE CASCADE;

