<?php

/*
 * Show notification in main window if there are images that should be deleted and are waiting for confirmation
 */

$res = Database::queryFirst("SELECT Count(*) AS cnt FROM sat.imageversion WHERE deletestate = 'SHOULD_DELETE'", array(), true);
if (isset($res['cnt']) && $res['cnt'] > 0) {
	Message::addInfo('dozmod.images-pending-delete-exist', true, $res['cnt']);
}
unset($res);
