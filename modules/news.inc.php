<?php

class Page_News extends Page
{
	/**
	 * Member variables needed to represent a news entry.
	 *
	 * $newsId		int		ID of the news entry attributed by the database.
	 * $newsTitle	string	Title of the entry.
	 * $newsContent	string	Content as text. (TODO: html-Support?)
	 * $newsDate	string	Unix epoch date of the news' creation.
	 */
	private $newsId = false;
	private $newsTitle = false;
	private $newsContent = false;
	private $newsDate = false;
	
	/**
	 * Implementation of the abstract doPreprocess function
	 *
	 * Checks if the user is logged in and processes any
	 * action if one was specified in the request.
	 *
	 */
	protected function doPreprocess()
	{
		// load user, we will need it later
		User::load();
		
		// only admins should be able to edit news
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			return;
		}
		
		// check which action we need to do
		$action = Request::any('action', 'show');
		if ($action === 'clear') {
			// clear news input fields
			// TODO: is this the right way?
			$this->newsId = false;
			$this->newsTitle = false;
			$this->newsContent = false;
			$this->newsDate = false;
		} elseif ($action === 'show') {
			// show news
			if (!$this->loadNews(Request::any('newsid'))) {
				Message::addError('news-empty');
			}
		} elseif ($action === 'save') {
			// save to DB
			if (!$this->saveNews()) {
				// re-set the fields we got
				Request::post('news-title') ? $this->newsTitle = Request::post('news-title') : $this->newsTitle = false;
				Request::post('news-content') ? $this->newsContent = Request::post('news-content') : $this->newsContent = false;
			} else {
				Message::addSuccess('news-save-success');
				Util::redirect('?do=News');
			}
		} elseif ($action === 'delete') {
			// delete it
			$this->delNews(Request::post('newsid'));
		} else {
			// unknown action, redirect user
			Message::addError('invalid-action', $action);
			Util::redirect('?do=News');
		}
	}

	/**
	 * Implementation of the abstract doRender function
	 *
	 * Fetch the list of news from the database and paginate it.
	 *
	 */
	protected function doRender()
	{
		// fetch the list of the older news
		$lines = array();
		$paginate = new Paginate("SELECT newsid, dateline, title, content FROM news ORDER BY dateline DESC", 10);
		$res = $paginate->exec();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['date'] = date('d.m.Y H:i', $row['dateline']);
			
			if ($row['newsid'] == $this->newsId) $row['active'] = "active";
			$lines[] = $row;
		}
		$paginate->render('page-news', array(
				'token' => Session::get('token'),
				'latestDate' => ($this->newsDate ? date('d.m.Y H:i', $this->newsDate) : '--'),
				'latestContent' => $this->newsContent,
				'latestTitle' => $this->newsTitle,
				'list'     => $lines ));

	}
	/**
	 * Loads the news with the given ID into the form.
	 *
	 * @param int $newsId ID of the news to be shown.
	 * @return boolean true if loading that news worked
	 *
	 */
	private function loadNews($newsId)
	{
		// check to see if we need to request a specific newsid
		if ($newsId !== false) {
			$row = Database::queryFirst("SELECT newsid, title, content, dateline FROM news WHERE newsid = :newsid LIMIT 1", array(
				'newsid' => $newsId
			));
		} else {
			$row = Database::queryFirst("SELECT newsid, title, content, dateline FROM news ORDER BY dateline DESC LIMIT 1");
		}
		
		// fetch the news to be shown
		if ($row !== false) {
			$this->newsId = $row['newsid'];
			$this->newsTitle = $row['title'];
			$this->newsContent = $row['content'];
			$this->newsDate = $row['dateline'];
		}
		return $row !== false;
	}

	/**
	 * Save the given $newsTitle and $newsContent as POST'ed into the database.
	 *
	 */
	private function saveNews()
	{
		// check if news content were set by the user
		$newsTitle = Request::post('news-title');
		$newsContent = Request::post('news-content');
		if ($newsContent !== '' && $newsTitle !== '') {
			// we got title and content, save it to DB
			Database::exec("INSERT INTO news (dateline, title, content) VALUES (:dateline, :title, :content)", array(
				'dateline' => time(),
				'title' => $newsTitle,
				'content' => $newsContent
			));
			return true;
		} else {
			Message::addError('empty-field');
			return false;
		}
	}
	
	/**
	 * Delete the news entry with ID $newsId
	 *
	 * @param int $newsId ID of the entry to be deleted.
	 */
	private function delNews($newsId)
	{
		// sanity check: is newsId even numeric?
		if (!is_numeric($newsId)) {
			Message::addError('value-invalid', 'newsid', $newsId);
		} else {
			// check passed - do delete
			Database::exec("DELETE FROM news WHERE newsid = :newsid LIMIT 1", array(
				'newsid' => $newsId
			));
			Message::addSuccess('news-del-success');
		}
		Util::redirect('?do=News');
	}

}
