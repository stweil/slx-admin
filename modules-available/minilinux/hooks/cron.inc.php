<?php

$ret = explode(':', date('G:i'));
if ((int)$ret[0] === 6 && (int)$ret[1] < 5) {
	MiniLinux::updateList();
}