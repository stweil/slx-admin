<?php

class Page_InternetAccess extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::any('action', 'show');

		if ($action == 'save') {
			if (User::hasPermission("configuration.safe")) {
				if (isset($_POST['PROXY_CONF'])) {
					$data = array();
					foreach (array('PROXY_CONF', 'PROXY_ADDR', 'PROXY_PORT', 'PROXY_USERNAME', 'PROXY_PASSWORD') as $key) {
						$data[$key] = Request::post($key, '');
					}
					if (!FileUtil::arrayToFile(CONFIG_PROXY_CONF, $data)) {
						Message::addError('main.error-write', CONFIG_PROXY_CONF);
						Util::redirect();
					} else {
						Message::addSuccess('settings-updated');
						Taskmanager::release(Taskmanager::submit('ReloadProxy'));
						$taskids = array();
						Trigger::stopDaemons(null, $taskids);
						$taskids = array();
						Trigger::startDaemons(null, $taskids);
						Session::set('ia-restart', $taskids);
						Util::redirect('?do=InternetAccess&show=update');
					}
				}
			}
		}
	}

	protected function doRender()
	{
		if (Request::any('show') === 'update') {
			$taskids = Session::get('ia-restart');
			if (is_array($taskids)) {
				Render::addTemplate('restart', $taskids);
			} else {
				Message::addError('invalid-action', 'Restart');
			}
		}
		$data = FileUtil::fileToArray(CONFIG_PROXY_CONF);
		if (!isset($data['PROXY_CONF']))
			$data['PROXY_CONF'] = 'AUTO';
		$data['selected_' . $data['PROXY_CONF']] = 'selected';

		$data['allowedSave'] = User::hasPermission("configuration.safe");

		Render::addTemplate('_page', $data);
	}

}
