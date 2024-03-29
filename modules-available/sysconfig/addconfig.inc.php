<?php

/**
 * Addconfig subpage base - makes sure
 * we have the two required methods preprocess and render
 */
abstract class AddConfig_Base
{

	/**
	 * Holds the instance for the currently executing step
	 * @var \AddConfig_Base
	 */
	private static $instance = false;
	
	/**
	 * Config being edited (if any)
	 * @var \ConfigTgz
	 */
	protected $edit = false;

	/**
	 * 
	 * @param string $step
	 */
	public static function setStep($step)
	{
		if (empty($step) || !class_exists($step) || get_parent_class($step) !== 'AddConfig_Base') {
			Message::addError('invalid-action', $step);
			Util::redirect('?do=SysConfig');
		}
		self::$instance = new $step();
		if (Request::any('edit')) {
			self::$instance->edit = ConfigTgz::get(Request::any('edit'));
			if (self::$instance->edit === false)
				Util::traceError('Invalid config id for editing');
			Util::addRedirectParam('edit', self::$instance->edit->id());
		}
	}

	protected function tmError()
	{
		Message::addError('main.taskmanager-error');
		Util::redirect('?do=SysConfig');
	}

	protected function taskError($status)
	{
		if (isset($status['data']['error'])) {
			$error = $status['data']['error'];
		} elseif (isset($status['statusCode'])) {
			$error = $status['statusCode'];
		} else {
			$error = Dictionary::translate('lang_unknwonTaskManager'); // TODO: No text
		}
		Message::addError('main.task-error', $error);
		Util::redirect('?do=SysConfig');
	}

	/**
	 * Called before any HTML rendering happens, so you can
	 * prepare stuff, validate input, and optionally redirect
	 * early if something is wrong, or you received post
	 * data etc.
	 */
	protected function preprocessInternal()
	{
		// void
	}

	/**
	 * Do page rendering.
	 */
	protected function renderInternal()
	{
		// void
	}
	
	/**
	 * Handle ajax stuff
	 */
	protected function ajaxInternal()
	{
		// void
	}
	
	public static function preprocess()
	{
		if (self::$instance === false) {
			Util::traceError('No step instance yet');
		}
		self::$instance->preprocessInternal();
	}
	
	public static function render()
	{
		if (self::$instance === false)
			Util::traceError('No step instance yet');
		if (self::$instance->edit !== false)
			Message::addInfo('replacing-config', self::$instance->edit->title());
		self::$instance->renderInternal();
	}
	
	public static function ajax()
	{
		if (self::$instance === false) {
			Util::traceError('No step instance yet');
		}
		self::$instance->ajaxInternal();
	}

}

/**
 * Start dialog for adding config. Ask for title,
 * show selection of modules.
 */
class AddConfig_Start extends AddConfig_Base
{

	protected function renderInternal()
	{
		$mods = ConfigModule::getList();
		$res = Database::simpleQuery("SELECT moduleid, title, moduletype, filepath FROM configtgz_module"
			. " ORDER BY title ASC"); // Move to ConfigModule
		if ($this->edit === false) {
			$active = array();
		} else {
			$active = $this->edit->getModuleIds();
		}
		$id = 0;
		$modGroups = array();
		foreach ($mods as &$mod) {
			$mod['groupid'] = 'g' . ++$id;
			$modGroups[$mod['group']] =& $mod;
		}
		unset($mod);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($mods[$row['moduletype']])) {
				$mods[$row['moduletype']] = array(
					'unique' => false,
					'group' => 'Undefined moduletype in addconfig.inc.php',
					'groupid' => 'g' . ++$id,
				);
				$modGroups[$mods[$row['moduletype']]['group']] =& $mods[$row['moduletype']];
			}
			unset($group);
			$group =& $modGroups[$mods[$row['moduletype']]['group']];
			if (!isset($group['modules'])) {
				$group['modules'] = array();
			}
			if (empty($row['filepath']) || !file_exists($row['filepath'])) $row['missing'] = true;
			$row['active'] = in_array($row['moduleid'], $active);
			$group['modules'][] = $row;
		}
		if ($this->edit !== false) {
			$title = $this->edit->title();
		} elseif (Request::any('title')) {
			$title = Request::any('title');
		} else {
			$title = '';
		}
		$dummy = 0;
		foreach ($modGroups as &$mod) {
			if (!empty($mod['modules']) && $mod['unique']) {
				array_unshift($mod['modules'], array(
					'moduleid' => 'x' . (++$dummy),
					'title' => Dictionary::translate('lang_noModuleFromThisGroup'),
				));
			}
		}
		unset($mod);
		Render::addDialog(Dictionary::translate("lang_configurationCompilation"), false, 'cfg-start', array(
			'step' => 'AddConfig_Finish',
			'groups' => array_values($modGroups),
			'title' => $title,
			'edit' => ($this->edit !== false ? $this->edit->id() : false)
		));
	}

}

/**
 * Success dialog if adding config worked.
 */
class AddConfig_Finish extends AddConfig_Base
{
	/**
	 * @var ConfigTgz
	 */
	private $config = false;
	
	protected function preprocessInternal()
	{
		$modules = Request::post('module');
		$title = Request::post('title');
		if (!is_array($modules)) {
			Message::addError('missing-file');
			Util::redirect('?do=SysConfig&action=addconfig');
		}
		if (empty($title)) {
			Message::addError('missing-title');
			Util::redirect('?do=SysConfig&action=addconfig');
		}
		if ($this->edit === false) {
			$this->config = ConfigTgz::insert($title, $modules);
		} else {
			$this->edit->update($title, $modules);
			$this->config = $this->edit;
		}
		if ($this->config === false || $this->config->generate(true, 150) === false) {
			Message::addError('unsuccessful-action');
			Util::redirect('?do=SysConfig&action=addconfig');
		}
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translate('lang_configurationCompilation'), false, 'cfg-finish', array(
			'configid' => $this->config->id()
		));
	}
	
}
