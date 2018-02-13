<?php

class Page_DozMod extends Page
{
	/** @var bool true if we have a proper subpage */
	private $haveSubPage = false;

	private $validSections = ['expiredimages', 'mailconfig', 'templates', 'runtimeconfig', 'users', 'actionlog'];

	private $section;

	private function setupSubPage()
	{
		if ($this->haveSubPage !== false)
			return;
		/* different pages for different sections */
		$this->section = Request::any('section', false, 'string');
		if ($this->section === 'blockstats') // HACK HACK
			return;
		if ($this->section === false) {
			foreach ($this->validSections as $this->section) {
				if (User::hasPermission($this->section . '.*'))
					break;
			}
		} elseif (!in_array($this->section, $this->validSections)) {
			Util::traceError('Invalid section: ' . $this->section);
		}
		// Check permissions
		User::assertPermission($this->section . '.*');
		$include = 'modules/' . Page::getModule()->getIdentifier() . '/pages/' . $this->section . '.inc.php';
		if (!file_exists($include))
			return;

		require_once $include;
		$this->haveSubPage = true;
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->setupSubPage();
		if ($this->haveSubPage !== false) {
			SubPage::doPreprocess();
		}
		// Catch unhandled POST redirect
		if (Request::isPost()) {
			Util::redirect('?do=dozmod&section=' . $this->section);
		}

		/* Leave this here for translation module
		Dictionary::translate('submenu_expiredimages', true);
		Dictionary::translate('submenu_mailconfig', true);
		Dictionary::translate('submenu_templates', true);
		Dictionary::translate('submenu_runtimeconfig', true);
		Dictionary::translate('submenu_users', true);
		Dictionary::translate('submenu_actionlog', true);
		*/

		/* add sub-menus */
		foreach ($this->validSections as $section) {
			if (User::hasPermission($section . '.*')) {
				Dashboard::addSubmenu('?do=dozmod&section=' . $section, Dictionary::translate('submenu_' . $section, true));
			}
		}
	}

	protected function doRender()
	{
		/* different pages for different sections */
		if ($this->haveSubPage !== false) {
			SubPage::doRender();
			return;
		}

		if ($this->section === 'blockstats') {
			$this->showBlockStats();
		}

	}

	private function showBlockStats()
	{
		$res = Database::simpleQuery("SELECT blocksha1, blocksize, Count(*) AS blockcount FROM sat.imageblock"
			. " GROUP BY blocksha1, blocksize HAVING blockcount > 1 ORDER BY blockcount DESC, blocksha1 ASC");
		$data = array('hashes' => array());
		$spaceWasted = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['hash_hex'] = bin2hex($row['blocksha1']);
			$row['blocksize_s'] = Util::readableFileSize($row['blocksize']);
			$data['hashes'][] = $row;
			$spaceWasted += $row['blocksize'] * ($row['blockcount'] - 1);
		}
		$data['spacewasted'] = Util::readableFileSize($spaceWasted);
		Render::addTemplate('blockstats', $data);
	}

	protected function doAjax()
	{
		User::load();
		$this->setupSubPage();

		if ($this->haveSubPage !== false) {
			SubPage::doAjax();
			return;
		}

		$action = Request::post('action');

		if ($action === 'getblockinfo') {
			$this->ajaxGetBlockInfo();
		}
	}

	private function ajaxGetBlockInfo()
	{
		$hash = Request::any('hash', false, 'string');
		$size = Request::any('size', false, 'string');
		if ($hash === false || $size === false) {
			die('Missing parameter');
		}
		if (!is_numeric($size) || strlen($hash) !== 40 || !preg_match('/^[a-f0-9]+$/i', $hash)) {
			die('Malformed parameter');
		}
		$res = Database::simpleQuery("SELECT i.displayname, v.createtime, v.filesize, Count(*) AS blockcount FROM sat.imageblock ib"
			. " INNER JOIN sat.imageversion v USING (imageversionid)"
			. " INNER JOIN sat.imagebase i USING (imagebaseid)"
			. " WHERE ib.blocksha1 = :hash AND ib.blocksize = :size"
			. " GROUP BY ib.imageversionid"
			. " ORDER BY i.displayname ASC, v.createtime ASC",
			array('hash' => hex2bin($hash), 'size' => $size), true);
		if ($res === false) {
			die('Database error: ' . Database::lastError());
		}
		$data = array('rows' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['createtime_s'] = date('d.m.Y H:i', $row['createtime']);
			$row['filesize_s'] = Util::readableFileSize($row['filesize']);
			$data['rows'][] = $row;
		}
		die(Render::parse('blockstats-details', $data));
	}

}
