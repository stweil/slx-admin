<?php

// VMStore path and type
$vmstore = Property::getVmStoreConfig();
if (is_array($vmstore) && isset($vmstore['storetype'])) {
	switch ($vmstore['storetype']) {
	case 'internal';
		ConfigHolder::add("SLX_VM_NFS", $_SERVER['SERVER_ADDR'] . ":/srv/openslx/nfs");
		break;
	case 'nfs';
		ConfigHolder::add("SLX_VM_NFS", $vmstore['nfsaddr']);
		if (!empty($vmstore['nfsopts'])) {
			ConfigHolder::add("SLX_VM_NFS_OPTS", $vmstore['nfsopts']);
		}
		break;
	case 'cifs';
		ConfigHolder::add("SLX_VM_NFS", $vmstore['cifsaddr']);
		ConfigHolder::add("SLX_VM_NFS_USER", $vmstore['cifsuserro']);
		ConfigHolder::add("SLX_VM_NFS_PASSWD", $vmstore['cifspasswdro']);
		if (!empty($vmstore['cifsopts'])) {
			ConfigHolder::add("SLX_VM_NFS_OPTS", $vmstore['cifsopts']);
		}
		break;
	}
}

// vm list url. doesn't really fit anywhere, seems to be a tie between here and dozmod
ConfigHolder::add("SLX_VMCHOOSER_BASE_URL", 'http://' . $_SERVER['SERVER_ADDR'] . '/vmchooser/');
