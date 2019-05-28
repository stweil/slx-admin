<?php

class Page_News extends Page
{

	private $hasSummernote = false;

	const TYPES = [
		// Dictionary::translate('type_news');
		'news' => ['headline' => true],
		// Dictionary::translate('type_help');
		'help' => ['headline' => false],
		// Dictionary::translate('type_login-news');
		'login-news' => ['headline' => false],
	];

	private $pageType = false;
	/*
	 * Member variables needed to represent a news entry.
	 */

	/**
	 * @var int ID of the news entry attributed by the database.
	 */
	private $newsId = false;
	/**
	 * @var string Title of the entry.
	 */
	private $newsTitle = false;
	/**
	 * @var string HTML news content
	 */
	private $newsContent = false;
	/**
	 * @var int Unix epoch date of the news' creation.
	 */
	private $newsDateline = false;
	/**
	 * @var int Unix epoch date when the news expires.
	 */
	private $newsExpires = false;


	/**
	 * Implementation of the abstract doPreprocess function.
	 *
	 * Checks if the user is logged in and processes any
	 * action if one was specified in the request.
	 */
	protected function doPreprocess()
	{

		// load user, we will need it later
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		// check which action we need to do
		if (!Request::isPost()) {

			User::assertPermission('access-page');

			/* and also the news (or help) with the given id */
			$newsId = Request::get('newsid', false, 'int');
			$pageType = Request::get('type', false, 'string');
			if ($pageType === false && $newsId === false) {
				Util::redirect('?do=news&type=news');
			}
			$this->pageType = $pageType === false ? 'news' : $pageType;
			$this->loadNews($newsId, $pageType);

			foreach (self::TYPES as $type => $entry) {
				Dashboard::addSubmenu('?do=news&type=' . $type, Dictionary::translate('type_' . $type, true));
			}

		} else {

			$action = Request::post('action', false, 'string');
			$pageType = Request::post('type', false, 'string');
			if (!array_key_exists($pageType, self::TYPES)) {
				Message::addError('invalid-type', $pageType);
				Util::redirect('?do=news');
			}

			if ($action === 'save') {
				// save to DB
				User::assertPermission("$pageType.save");
				if (!$this->saveNews($pageType)) {
					Message::addError('save-error');
				} else {
					Message::addSuccess('news-save-success');
				}

			} elseif ($action === 'delete') {
				// delete it
				User::assertPermission("$pageType.delete");
				$this->delNews(Request::post('newsid', false, 'int'), $pageType);
			} else {
				// unknown action, redirect user
				Message::addError('invalid-action', $action);
			}

			Util::redirect('?do=news&type=' . $pageType);
		}

		/* load summernote module if available */
		$this->hasSummernote = Module::isAvailable('summernote');
	}

	/**
	 * Implementation of the abstract doRender function.
	 *
	 * Fetch the list of news from the database and paginate it.
	 */
	protected function doRender()
	{
		// fetch the list of the older news
		$NOW = time();
		$lines = array();
		$res = Database::simpleQuery("SELECT newsid, dateline, expires, title, content FROM vmchooser_pages
				WHERE type = :type ORDER BY dateline DESC LIMIT 20", ['type' => $this->pageType]);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['dateline_s'] = Util::prettyTime($row['dateline']);
			$row['expires_s'] = $this->formatExpires($row['expires']);
			if ($row['expires'] < $NOW) {
				$row['muted'] = 'text-muted';
			}

			if ($row['newsid'] == $this->newsId) {
				$row['active'] = 'active';
			}
			$row['content'] = substr(strip_tags(str_replace('>', '> ', $row['content'])), 0, 160);
			$lines[] = $row;
		}

		$validity = ceil(($this->newsExpires - $NOW) / 3600);
		if ($this->newsExpires === false || $validity > 24 * 365 * 5) {
			$validity = '';
		}
		$data = array(
			'withTitle' => self::TYPES[$this->pageType]['headline'],
			'newsTypeName' => Dictionary::translate('type_' . $this->pageType, true),
			'dateline_s' => Util::prettyTime($this->newsDateline),
			'expires_s' => $this->formatExpires($this->newsExpires),
			'currentContent' => $this->newsContent,
			'currentTitle' => $this->newsTitle,
			'type' => $this->pageType,
			'validity' => $validity,
			'list' => $lines,
			'hasSummernote' => $this->hasSummernote,
		);
		if (!User::hasPermission($this->pageType . '.save')) {
			$data['save'] = [
				'readonly' => 'readonly',
				'disabled' => 'disabled',
			];
		}
		if (!User::hasPermission($this->pageType . '.delete')) {
			$data['delete'] = [
				'readonly' => 'readonly',
				'disabled' => 'disabled',
			];
		}
		Render::addTemplate('page-news', $data);
	}

	private function formatExpires($ts)
	{
		if ($ts - 86400 * 365 * 5 > time())
			return '-';
		return Util::prettyTime($ts);
	}

	/**
	 * Loads the news with the given ID into the form.
	 *
	 * @param int $newsId ID of the news to be shown.
	 * @param string $pageType type if news id is not given.
	 *
	 * @return bool true if loading that news worked
	 */
	private function loadNews($newsId, $pageType)
	{
		// check to see if we need to request a specific newsid
		if ($newsId !== false) {
			$row = Database::queryFirst('SELECT newsid, title, content, dateline, expires, type FROM vmchooser_pages
					WHERE newsid = :newsid LIMIT 1', [
				'newsid' => $newsId,
			]);
			if ($row === false) {
				Message::addError('news-empty');
			}
		} else {
			$row = Database::queryFirst("SELECT newsid, title, content, dateline, expires, type FROM vmchooser_pages
					WHERE type = :type AND expires > UNIX_TIMESTAMP() ORDER BY dateline DESC LIMIT 1", [
				'type' => $pageType,
			]);
		}
		if ($row === false)
			return false;

		// fetch the news to be shown
		if ($row !== false) {
			$this->newsId = $row['newsid'];
			$this->newsTitle = $row['title'];
			$this->newsContent = $row['content'];
			$this->newsDateline = (int)$row['dateline'];
			$this->newsExpires = (int)$row['expires'];
			$this->pageType = $row['type'];
		}
		return true;
	}

	/**
	 * Save the given $newsTitle and $newsContent as POST'ed into the database.
	 */
	private function saveNews($pageType)
	{
		// check if news content were set by the user
		$newsTitle = Request::post('news-title', '', 'string');
		$newsContent = Request::post('news-content', false, 'string');
		$validity = Request::post('validity', false, 'string');
		if ($validity === false || $validity === '') {
			$validity = 86400 * 3650; // 10 Years
		} else {
			$validity *= 3600; // Hours to seconds
		}
		if (!empty($newsContent)) {
			// we got title and content, save it to DB
			// dup check first
			$row = Database::queryFirst('SELECT newsid FROM vmchooser_pages
					WHERE content = :content AND type = :type LIMIT 1', [
				'content' => $newsContent,
				'type' => $pageType,
			]);
			if ($row !== false) {
				Database::exec('UPDATE vmchooser_pages SET dateline = :dateline, expires = :expires, title = :title
						WHERE newsid = :newsid LIMIT 1', [
					'newsid' => $row['newsid'],
					'dateline' => time(),
					'expires' => time() + $validity,
					'title' => $newsTitle,
				]);
				return true;
			}
			// new one
			Database::exec("INSERT INTO vmchooser_pages (dateline, expires, title, content, type)
					VALUES (:dateline, :expires, :title, :content, :type)", array(
				'dateline' => time(),
				'expires' => time() + $validity,
				'title' => $newsTitle,
				'content' => $newsContent,
				'type' => $pageType,
			));

			return true;
		}

		Message::addError('main.empty-field');
		return false;
	}

	/**
	 * Delete the news entry with ID $newsId.
	 *
	 * @param int $newsId ID of the entry to be deleted.
	 * @param string $pageType type of news to be deleted. Must match the ID, otherwise do nothing.
	 */
	private function delNews($newsId, $pageType)
	{
		// sanity check: is newsId even numeric?
		if (!is_numeric($newsId)) {
			Message::addError('main.value-invalid', 'newsid', $newsId);
		} else {
			// check passed - do delete
			Database::exec('DELETE FROM vmchooser_pages WHERE newsid = :newsid AND type = :type LIMIT 1', array(
				'newsid' => $newsId,
				'type' => $pageType,
			));
			Message::addSuccess('news-del-success');
		}
	}
}
