<?php

ConfigModules::registerModule(
	ConfigModule_Branding::MODID, // ID
	Dictionary::translate('config-module', 'branding_title'), // Title
	Dictionary::translate('config-module', 'branding_description'), // Description
	Dictionary::translate('config-module', 'group_branding'), // Group
	true // Only one per config?
);

class ConfigModule_Branding extends ConfigModule
{
	const MODID = 'Branding';

	public static function insert($title, $archive)
	{
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filepath, contents) "
			. " VALUES (:title, :modid, '', '')", array('title' => $title, 'modid' => self::MODID));
		$id = Database::lastInsertId();
		if (!is_numeric($id))
			Util::traceError('Inserting new Branding Module into DB did not yield a numeric insert id');
		// Move tgz
		$moduleTgz = CONFIG_TGZ_LIST_DIR . '/modules/BRANDING_id_' . $id . '.' . mt_rand() . '.tgz';
		$task = Taskmanager::submit('MoveFile', array(
				'source' => $archive,
				'destination' => $moduleTgz
		));
		$task = Taskmanager::waitComplete($task, 3000);
		if (Taskmanager::isFailed($task) || $task['statusCode'] !== TASK_FINISHED) {
			Taskmanager::addErrorMessage($task);
			Database::exec("DELETE FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array(
				'moduleid' => $id
			));
			return false;
		}
		// Update with path
		Database::exec("UPDATE configtgz_module SET filepath = :filename WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $moduleTgz
		));
		return true;
	}
	
}
