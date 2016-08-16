<?php

class Page_DozMod extends Page
{
	/** @var \Page sub page classes */
	private $subPage = false;

	private function setupSubPage()
	{
		if ($this->subPage !== false)
			return;
		/* different pages for different sections */
		$section = Request::any('section', 'mailconfig', 'string');
		/* instantiate sub pages */
		if ($section === 'templates') {
			$this->subPage = new Page_mail_templates();
		}
		if ($section === 'users') {
			$this->subPage = new Page_dozmod_users();
		}
		if ($section === 'actionlog') {
			$this->subPage = new Page_dozmod_log();
		}
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		/* add sub-menus */
		Dashboard::addSubmenu('?do=dozmod&section=mailconfig', Dictionary::translate('submenu_mailconfig', true));
		Dashboard::addSubmenu('?do=dozmod&section=templates', Dictionary::translate('submenu_templates', true));
		Dashboard::addSubmenu('?do=dozmod&section=runtimeconfig', Dictionary::translate('submenu_runtime', true));
		Dashboard::addSubmenu('?do=dozmod&section=users', Dictionary::translate('submenu_users', true));
		Dashboard::addSubmenu('?do=dozmod&section=actionlog', Dictionary::translate('submenu_actionlog', true));

		$this->setupSubPage();
		if ($this->subPage !== false) {
			$this->subPage->doPreprocess();
			return;
		}

		/* execute actions */
		$action = Request::post('action');

		if ($action === 'mail') {
			$this->mailHandler();
		}
		if ($action === 'runtime') {
			$this->runtimeHandler();
		}
		if ($action === 'delimages') {
			$result = $this->handleDeleteImages();
			if (!empty($result)) {
				Message::addInfo('delete-images', $result);
			}
			Util::redirect('?do=DozMod');
		}
	}

	protected function doRender()
	{
		$this->listDeletePendingImages();

		/* different pages for different sections */
		if ($this->subPage !== false) {
			$this->subPage->doRender();
			return;
		}

		$section = Request::get('section', 'mailconfig', 'string');

		if ($section === 'mailconfig') {
			// Mail config
			$mailConf = Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'mailconfig'));
			if ($mailConf != null) {
				$mailConf = @json_decode($mailConf['value'], true);
				if (is_array($mailConf)) {
					$mailConf['set_' . $mailConf['ssl']] = 'selected="selected"';
				}
			}
			Render::addTemplate('mailconfig', $mailConf);
		}
		if ($section === 'runtimeconfig') {
			// Runtime config
			$runtimeConf = Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'runtimelimits'));
			if ($runtimeConf != null) {
				$runtimeConf = json_decode($runtimeConf['value'], true);

				/* convert some value to corresponding "selected" texts */
				if ($runtimeConf['defaultLecturePermissions']['edit']) {
					$runtimeConf['defaultLecturePermissions']['edit'] = 'checked="checked"';
				}
				if ($runtimeConf['defaultLecturePermissions']['admin']) {
					$runtimeConf['defaultLecturePermissions']['admin'] = 'checked="checked"';
				}
				if ($runtimeConf['defaultImagePermissions']['edit']) {
					$runtimeConf['defaultImagePermissions']['edit'] = 'checked="checked"';
				}
				if ($runtimeConf['defaultImagePermissions']['admin']) {
					$runtimeConf['defaultImagePermissions']['admin'] = 'checked="checked"';
				}
				if ($runtimeConf['defaultImagePermissions']['link']) {
					$runtimeConf['defaultImagePermissions']['link'] = 'checked="checked"';
				}
				if ($runtimeConf['defaultImagePermissions']['download']) {
					$runtimeConf['defaultImagePermissions']['download'] = 'checked="checked"';
				}

			}
			Render::addTemplate('runtimeconfig', $runtimeConf);
		}

	}

	private function listDeletePendingImages()
	{
		$res = Database::simpleQuery("SELECT b.displayname,"
				. " own.firstname, own.lastname, own.email,"
				. " v.imageversionid, v.createtime, v.filesize, v.deletestate,"
				. " lat.expiretime AS latexptime, lat.deletestate AS latdelstate"
				. " FROM sat.imageversion v"
				. " INNER JOIN sat.imagebase b ON (b.imagebaseid = v.imagebaseid)"
				. " INNER JOIN sat.user own ON (b.ownerid = own.userid)"
				. " LEFT JOIN sat.imageversion lat ON (b.latestversionid = lat.imageversionid)"
				. " WHERE v.deletestate <> 'KEEP'"
				. " ORDER BY b.displayname ASC, v.createtime ASC");
		$NOW = time();
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['latexptime'] > $NOW && $row['latdelstate'] === 'KEEP') {
				$row['hasNewerClass'] = 'glyphicon-ok green';
				$row['checked'] = 'checked';
			} else {
				$row['hasNewerClass'] = 'glyphicon-remove red';
			}
			if ($row['deletestate'] === 'WANT_DELETE') {
				$row['name_extra_class'] = 'slx-strike';
			}
			$row['version'] = date('d.m.Y H:i:s', $row['createtime']);
			$row['filesize'] = Util::readableFileSize($row['filesize']);
			$rows[] = $row;
		}
		if (empty($rows))
			return;
		Render::addTemplate('images-delete', array('images' => $rows));
	}

	private function cleanMailArray()
	{
		$keys = array('host', 'port', 'ssl', 'senderAddress', 'replyTo', 'username', 'password', 'serverName');
		$data = array();
		foreach ($keys as $key) {
			$data[$key] = Request::post($key, '');
			settype($data[$key], 'string');
			if (is_numeric($data[$key])) {
				settype($data[$key], 'int');
			}
		}
		return $data;
	}

	protected function doAjax()
	{
		User::load();
		if (!User::hasPermission('superadmin'))
			return;

		$this->setupSubPage();
		if ($this->subPage !== false) {
			$this->subPage->doAjax();
			return;
		}

		$action = Request::post('action');
		if ($action === 'mail') {
			$this->handleTestMail();
		} elseif ($action === 'delimages') {
			die($this->handleDeleteImages());
		}
	}

	private function handleDeleteImages()
	{
		$images = Request::post('images', false);
		if (is_array($images)) {
			foreach ($images as $image => $val) {
				if (strtolower($val) !== 'on')
					continue;
				Database::exec("UPDATE sat.imageversion SET deletestate = 'WANT_DELETE'"
					. " WHERE deletestate = 'SHOULD_DELETE' AND imageversionid = :imageversionid", array(
					'imageversionid' => $image
				));
			}
			if (!empty($images)) {
				$ret = Download::asStringPost('http://127.0.0.1:9080/do/delete-images', false, 10, $code);
				if ($code == 999) {
					$ret .= "\nConnection to DMSD failed.";
				}
				return $ret;
			}
		}
		return false;
	}

	private function handleTestMail()
	{
		$do = Request::post('button');
		if ($do === 'test') {
			// Prepare array
			$data = $this->cleanMailArray();
			Header('Content-Type: text/plain; charset=utf-8');
			$data['recipient'] = Request::post('recipient', '');
			if (!preg_match('/.+@.+\..+/', $data['recipient'])) {
				$result = 'No recipient given!';
			} else {
				$result = Download::asStringPost('http://127.0.0.1:9080/do/mailtest', $data, 10, $code);
				if ($code == 999) {
					$result .= "\nTimeout.";
				} elseif ($code != 200) {
					$result .= "\nReturn code $code";
				}
			}
			die($result);
		}
	}

	private function mailHandler()
	{
		// Check action
		$do = Request::post('button');
		if ($do === 'save') {
			// Prepare array
			$data = $this->cleanMailArray();
			$data = json_encode($data);
			Database::exec('INSERT INTO sat.configuration (parameter, value)'
				. ' VALUES (:param, :value)'
				. ' ON DUPLICATE KEY UPDATE value = VALUES(value)', array(
				'param' => 'mailconfig',
				'value' => $data
			));
			Message::addSuccess('mail-config-saved');
		} else {
			Message::addError('main.invalid-action', $do);
		}
		Util::redirect('?do=DozMod&section=mailconfig');
	}

	private function runtimeHandler()
	{
		// Check action
		$do = Request::post('button');
		if ($do === 'save') {
			$data = [];
			$data['defaultLecturePermissions'] = Request::post('defaultLecturePermissions', NULL, "array");
			$data['defaultImagePermissions'] = Request::post('defaultImagePermissions', NULL, "array");

			$intParams = [
				'maxImageValidityDays' => array('min' => 7, 'max' => 999),
				'maxLectureValidityDays' => array('min' => 7, 'max' => 999),
				'maxTransfers' => array('min' => 1, 'max' => 10),
			];
			foreach($intParams as $field => $limits) {
				$value = Request::post($field, 0, 'int');
				$data[$field] = max(min($value, $limits['max']), $limits['min']);
			}

			/* ensure types */
			settype($data['defaultLecturePermissions']['edit'], 'boolean');
			settype($data['defaultLecturePermissions']['admin'], 'boolean');
			settype($data['defaultImagePermissions']['edit'], 'boolean');
			settype($data['defaultImagePermissions']['admin'], 'boolean');
			settype($data['defaultImagePermissions']['link'], 'boolean');
			settype($data['defaultImagePermissions']['download'], 'boolean');
			settype($data['maxImageValidityDays'], 'int');
			settype($data['maxLectureValidityDays'], 'int');
			settype($data['maxTransfers'], 'int');

			$data = json_encode($data);
			Database::exec('INSERT INTO sat.configuration (parameter, value)'
				. ' VALUES (:param, :value)'
				. ' ON DUPLICATE KEY UPDATE value = VALUES(value)', array(
				'param' => 'runtimelimits',
				'value' => $data
			));
			Message::addSuccess('runtimelimits-config-saved');
		}
		Util::redirect('?do=DozMod&section=runtimeconfig');
	}

}
