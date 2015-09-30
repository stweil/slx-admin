<?php

class Page_Backup extends Page
{

	private $action = false;
	private $templateData = array();

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		$this->action = Request::post('action');
		if ($this->action === 'backup') {
			$this->backup();
		} elseif ($this->action === 'restore') {
			$this->restore();
		}
	}

	protected function doRender()
	{
		Render::setTitle(Dictionary::translate('lang_titleBackup'));
		if ($this->action === 'restore') {
			Render::addTemplate('backup/restore', $this->templateData);
		} else {
			Render::addScriptBottom('fileselect');
			Render::addTemplate('backup/_page');
		}
	}

	private function backup()
	{
		$task = Taskmanager::submit('BackupRestore', array('mode' => 'backup'));
		if (!isset($task['id'])) {
			Message::addError('backup-failed');
			Util::redirect('?do=Backup');
		}
		$task = Taskmanager::waitComplete($task, 3000);
		if (!Taskmanager::isFinished($task) || !isset($task['data']['backupFile'])) {
			Taskmanager::addErrorMessage($task);
			Util::redirect('?do=Backup');
		}
		while ((@ob_get_level()) > 0)
			@ob_end_clean();
		$fh = @fopen($task['data']['backupFile'], 'rb');
		if ($fh === false) {
			Message::addError('error-read', $task['data']['backupFile']);
			Util::redirect('?do=Backup');
		}
		Header('Content-Type: application/octet-stream', true);
		Header('Content-Disposition: attachment; filename=' . 'satellite-backup_v' . Database::getExpectedSchemaVersion() . '_' . date('Y.m.d-H.i.s') . '.tgz');
		Header('Content-Length: ' . @filesize($task['data']['backupFile']));
		while (!feof($fh)) {
			$data = fread($fh, 16000);
			if ($data === false) {
				EventLog::failure('Could not stream system backup to browser - backup corrupted!');
				die("\r\n\nDOWNLOAD INTERRUPTED!\n");
			}
			echo $data;
			@ob_flush();
			@flush();
		}
		@fclose($fh);
		@unlink($task['data']['backupFile']);
		die();
	}

	private function restore()
	{
		if (!isset($_FILES['backupfile'])) {
			Message::addError('missing-file');
			Util::redirect('?do=Backup');
		}
		if ($_FILES['backupfile']['error'] != UPLOAD_ERR_OK) {
			Message::addError('upload-failed', Util::uploadErrorString($_FILES['backupfile']['error']));
			Util::redirect('?do=Backup');
		}
		$tempfile = '/tmp/bwlp-' . mt_rand(1, 100000) . '-' . crc32($_SERVER['REMOTE_ADDR']) . '.tgz';
		if (!move_uploaded_file($_FILES['backupfile']['tmp_name'], $tempfile)) {
			Message::addError('error-write', $tempfile);
			Util::redirect('?do=Backup');
		}
		// Got uploaded file, now shut down all the daemons etc.
		$parent = Trigger::stopDaemons(null, $this->templateData);
		// Unmount store
		$task = Taskmanager::submit('MountVmStore', array(
				'address' => 'null',
				'type' => 'images',
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		if (isset($task['id'])) {
			$this->templateData['mountid'] = $task['id'];
			$parent = $task['id'];
		}
		EventLog::info('Creating backup, v' . Database::getExpectedSchemaVersion() . ' on ' . Property::getServerIp());
		// Finally run restore
		$task = Taskmanager::submit('BackupRestore', array(
				'mode' => 'restore',
				'backupFile' => $tempfile,
				'parentTask' => $parent,
				'failOnParentFail' => false,
				'restoreOpenslx' => Request::post('restore_openslx', 'off') === 'on',
				'restoreDozmod' => Request::post('restore_dozmod', 'off') === 'on',
		));
		if (isset($task['id'])) {
			$this->templateData['restoreid'] = $task['id'];
			$parent = $task['id'];
			TaskmanagerCallback::addCallback($task, 'dbRestored');
		}
		// Wait a bit
		$task = Taskmanager::submit('SleepTask', array(
				'seconds' => 3,
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		if (isset($task['id']))
			$parent = $task['id'];
		// Reboot
		$task = Taskmanager::submit('Reboot', array(
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		// Leave this comment so the i18n scanner finds it:
		// Message::addSuccess('restore-done');
		if (isset($task['id']))
			$this->templateData['rebootid'] = $task['id'];
	}

}
