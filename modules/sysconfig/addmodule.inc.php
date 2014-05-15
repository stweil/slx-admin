<?php

/**
 * Addmodule subpage base - makes sure
 * we have the two required methods preprocess and render
 */
abstract class AddModule_Base
{

	/**
	 * 
	 * @param type $step
	 * @return \AddModule_Base
	 */
	public static function get($step)
	{
		switch ($step) {
			case 0: // Upload form
				return new AddModule_UploadForm();
			case 1: // Handle config module uploading
				return new AddModule_ProcessUpload();
			case 2: // ?
				return new AddModule_CompressModule();
		}
		Message::addError('invalid-action', $step);
		Util::redirect('?do=sysconfig');
	}
	
	protected function tmError()
	{
		Message::addError('taskmanager-error');
		Util::redirect('?do=sysconfig');
	}
	
	protected function taskError($status)
	{
		if (isset($status['data']['error'])) {
			$error = $status['data']['error'];
		} elseif (isset($status['statusCode'])) {
			$error = $status['statusCode'];
		} else {
			$error = 'Unbekannter Taskmanager-Fehler'; // TODO: No text
		}
		Message::addError('task-error', $error);
		Util::redirect('?do=sysconfig');
	}

	/**
	 * Called before any HTML rendering happens, so you can
	 * pepare stuff, validate input, and optionally redirect
	 * early if something is wrong, or you received post
	 * data etc.
	 */
	public function preprocess()
	{
		// void
	}

	/**
	 * Do page rendering
	 */
	public function render()
	{
		// void
	}

}

class AddModule_UploadForm extends AddModule_Base
{

	public function render()
	{
		global $nextStep;
		Session::set('mod_temp', false);
		Render::addDialog('Eigenes Modul hinzufügen', false, 'sysconfig/custom-upload', array('step' => $nextStep));
	}

}

/**
 * Some file has just been uploaded. Try to store it, then try to unpack/analyze it.
 * If this succeeds, we proceed to the next step where we present the user our findings
 * and ask what to do with this.
 */
class AddModule_ProcessUpload extends AddModule_Base
{
	
	private $taskId = false;

	public function preprocess()
	{
		if (!isset($_FILES['modulefile'])) {
			Message::addError('missing-file');
			return;
		}
		if ($_FILES['modulefile']['error'] != UPLOAD_ERR_OK) {
			Message::addError('upload-failed', $_FILE['modulefile']['name']);
			return;
		}
		$tempfile = $_FILES['modulefile']['tmp_name'] . '.tmp';
		if (!move_uploaded_file($_FILES['modulefile']['tmp_name'], $tempfile)) {
			Message:addError('error-write', $tempfile);
			return;
		}
		$this->taskId = 'tgzmod' . mt_rand() . '-' . microtime(true);
		Taskmanager::submit('ListArchive', array(
			'id' => $this->taskId,
			'file' => $tempfile
		), true);
		Session::set('mod_temp', $tempfile);
	}
	
	public function render()
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
		global $nextStep;
		Render::addDialog('Eigenes Modul hinzufügen', false, 'sysconfig/custom-fileselect', array(
			'step' => $nextStep,
			'files' => $list,
		));
		Session::save();
	}

}

class AddModule_CompressModule extends AddModule_Base
{
	
	private $taskId = false;
	
	public function preprocess()
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
		Util::redirect('?do=sysconfig');
	}
	
}
