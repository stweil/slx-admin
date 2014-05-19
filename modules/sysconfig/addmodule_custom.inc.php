<?php

/*
 * Wizard for adding a custom module. A custom module is a plain archive that gets
 * included into a config.tgz the way itz is. No handling, sanity checks or anything
 * fancy is happening.
 */

AddModule_Base::addModule('custom', 'CustomModule_UploadForm', 'Erweitertes Modul',
	'Mit einem Erweiterten Modul ist es möglich, beliebige Dateien zum Grundsystem hinzuzufügen.'
	. ' Nutzen Sie dieses Modul, um z.B. spezielle Konfigurationsdateien auf den Client PCs zu'
	. ' verwenden, die sich nicht mit einem der anderen Wizards erstellen lässt.'
	. ' Das Hinzufügen eines Erweiterten Moduls erfordert in der Regel zumindest grundlegende'
	. ' Systemkenntnisse im Linuxbereich.', 100
);

class CustomModule_UploadForm extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('mod_temp', false);
		Render::addDialog('Eigenes Modul hinzufügen', false, 'sysconfig/custom-upload', array('step' => 'CustomModule_ProcessUpload'));
	}

}

/**
 * Some file has just been uploaded. Try to store it, then try to unpack/analyze it.
 * If this succeeds, we proceed to the next step where we present the user our findings
 * and ask what to do with this.
 */
class CustomModule_ProcessUpload extends AddModule_Base
{
	
	private $taskId = false;

	protected function preprocessInternal()
	{
		if (!isset($_FILES['modulefile'])) {
			Message::addError('missing-file');
			Util::redirect('?do=SysConfig');
		}
		if ($_FILES['modulefile']['error'] != UPLOAD_ERR_OK) {
			Message::addError('upload-failed', $_FILES['modulefile']['name']);
			Util::redirect('?do=SysConfig');
		}
		$tempfile = $_FILES['modulefile']['tmp_name'] . '.tmp';
		if (!move_uploaded_file($_FILES['modulefile']['tmp_name'], $tempfile)) {
			Message:addError('error-write', $tempfile);
			Util::redirect('?do=SysConfig');
		}
		$this->taskId = 'tgzmod' . mt_rand() . '-' . microtime(true);
		Taskmanager::submit('ListArchive', array(
			'id' => $this->taskId,
			'file' => $tempfile
		), true);
		Session::set('mod_temp', $tempfile);
	}
	
	protected function renderInternal()
	{
		$status = Taskmanager::waitComplete($this->taskId);
		Taskmanager::release($this->taskId);
		$tempfile = Session::get('mod_temp');
		if (!isset($status['statusCode'])) {
			unlink($tempfile);
			$this->tmError();
		}
		if ($status['statusCode'] != TASK_FINISHED) {
			unlink($tempfile);
			$this->taskError($status);
		}
		// Sort files for better display
		$dirs = array();
		foreach ($status['data']['entries'] as $file) {
			if ($file['isdir']) continue;
			$dirs[dirname($file['name'])][] = $file;
		}
		ksort($dirs);
		$list = array();
		foreach ($dirs as $dir => $files) {
			$list[] = array(
				'name' => $dir,
				'isdir' => true
			);
			sort($files);
			foreach ($files as $file) {
				$file['size'] = Util::readableFileSize($file['size']);
				$list[] = $file;
			}
		}
		Render::addDialog('Eigenes Modul hinzufügen', false, 'sysconfig/custom-fileselect', array(
			'step' => 'CustomModule_CompressModule',
			'files' => $list,
		));
		Session::save();
	}

}

class CustomModule_CompressModule extends AddModule_Base
{
	
	private $taskId = false;
	
	protected function preprocessInternal()
	{
		$title = Request::post('title');
		$tempfile = Session::get('mod_temp');
		if (empty($title) || empty($tempfile) || !file_exists($tempfile)) {
			Message::addError('empty-field');
			return;
		}
		// Recompress using task manager
		$this->taskId = 'tgzmod' . mt_rand() . '-' . microtime(true);
		$destFile = CONFIG_TGZ_LIST_DIR . '/modules/mod-' . preg_replace('/[^a-z0-9_\-]+/is', '_', $title) . '-' . microtime(true) . '.tgz';
		Taskmanager::submit('RecompressArchive', array(
			'id' => $this->taskId,
			'inputFiles' => array($tempfile),
			'outputFile' => $destFile
		), true);
		$status = Taskmanager::waitComplete($this->taskId);
		unlink($tempfile);
		if (!isset($status['statusCode'])) {
			$this->tmError();
		}
		if ($status['statusCode'] != TASK_FINISHED) {
			$this->taskError($status);
		}
		// Seems ok, create entry in DB
		$ret = Database::exec("INSERT INTO configtgz_module (title, moduletype, filename, contents) VALUES (:title, 'custom', :file, '')",
			array('title' => $title, 'file' => $destFile));
		if ($ret === false) {
			unlink($destFile);
			Util::traceError("Could not insert module into Database");
		}
		Message::addSuccess('module-added');
		Util::redirect('?do=SysConfig');
	}
	
}
