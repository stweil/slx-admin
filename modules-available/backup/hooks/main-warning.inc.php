<?php

$last = Property::get('backup.last-time', 0);
if ($last === 0) {
	Message::addWarning('backup.last-time-unknown', true);
} elseif ($last + (30 * 86400) < time()) {
	Message::addWarning('backup.last-time', true, date('d.m.Y', $last));
}
