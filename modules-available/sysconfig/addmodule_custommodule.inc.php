<?php

/*
 * Wizard for adding a custom module. A custom module is a plain archive that gets
 * included into a config.tgz the way itz is. No handling, sanity checks or anything
 * fancy is happening.
 */

class CustomModule_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('mod_temp', false);
		Render::addDialog(Dictionary::translate('config-module', 'custom_title'), false, 'custom-upload', array(
			'step' => 'CustomModule_ProcessUpload',
			'edit' => $this->edit ? $this->edit->id() : false
			));
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
			Message::addError('upload-failed', Util::uploadErrorString($_FILES['modulefile']['error']));
			Util::redirect('?do=SysConfig');
		}
		$tempfile = '/tmp/bwlp-' . mt_rand(1, 100000) . '-' . crc32($_SERVER['REMOTE_ADDR']) . '.tmp';
		if (!move_uploaded_file($_FILES['modulefile']['tmp_name'], $tempfile)) {
			Message::addError('main.error-write', $tempfile);
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
		if ($this->edit !== false)
			$title = $this->edit->title();
		elseif (isset($_FILES['modulefile']['name']))
			$title = basename($_FILES['modulefile']['name']);
		else
			$title = '';
		Render::addDialog(Dictionary::translate('config-module', 'custom_title'), false, 'custom-fileselect', array(
			'step' => 'CustomModule_CompressModule',
			'files' => $list,
			'edit' => $this->edit ? $this->edit->id() : false,
			'title' => $title
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
			Message::addError('main.empty-field');
			Util::redirect('?do=SysConfig&action=addmodule&step=CustomModule_Start');
		}
		// Recompress using task manager
		$this->taskId = 'tgzmod' . mt_rand() . '-' . microtime(true);
		$destFile = tempnam(sys_get_temp_dir(), 'bwlp-') . '.tgz';
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
		// Seems ok, create entry
		if ($this->edit === false)
			$module = ConfigModule::getInstance('CustomModule');
		else
			$module = $this->edit;
		if ($module === false) {
			Message::addError('main.error-read', 'custommodule.inc.php');
			Util::redirect('?do=SysConfig&action=addmodule&step=CustomModule_Start');
		}
		$module->setData('tmpFile', $destFile);
		if ($this->edit !== false)
			$ret = $module->update($title);
		else
			$ret = $module->insert($title);
		if (!$ret)
			Util::redirect('?do=SysConfig&action=addmodule&step=CustomModule_Start');
		elseif (!$module->generate($this->edit === false, NULL, 200))
			Util::redirect('?do=SysConfig&action=addmodule&step=CustomModule_Start');
		Session::set('mod_temp', false);
		Session::save();
		// Yay
		if ($this->edit !== false)
			Message::addSuccess('module-edited');
		else
			Message::addSuccess('module-added');
		Util::redirect('?do=SysConfig');
	}
	
}
