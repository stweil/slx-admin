<?php

// VMStore path and type
$vmstore = Property::getVmStoreConfig();
if (is_array($vmstore) && isset($vmstore['storetype'])) {
	switch ($vmstore['storetype']) {
	case 'internal';
		$configVars["SLX_VM_NFS"] = $_SERVER['SERVER_ADDR'] . ":/srv/openslx/nfs";
		break;
	case 'nfs';
		$configVars["SLX_VM_NFS"] = $vmstore['nfsaddr'];
		break;
	case 'cifs';
		$configVars["SLX_VM_NFS"] = $vmstore['cifsaddr'];
		$configVars["SLX_VM_NFS_USER"] = $vmstore['cifsuserro'];
		$configVars["SLX_VM_NFS_PASSWD"] = $vmstore['cifspasswdro'];
		break;
	}
}

// vm list url. doesn't really fit anywhere, seems to be a tie between here and dozmod
$configVars["SLX_VMCHOOSER_BASE_URL"] = 'http://' . $_SERVER['SERVER_ADDR'] . '/vmchooser/';
