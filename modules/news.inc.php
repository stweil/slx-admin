<?php

class Page_News extends Page
{
	private $newsId = false;
	private $newsTitle = false;
	private $newsContent = false;
	private $newsDate = false;
	
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
		if ($action === 'show') {
			// show news
			if (!$this->loadNews(Request::any('newsid'))) {
				Message::addError('news-empty');
			}
		} elseif ($action === 'save') {
			// save to DB
			$this->saveNews();
		} elseif ($action === 'delete') {
			// delete it
			$this->delNews(Request::post('newsid'));
		} else {
			Message::addError('invalid-action', $action);
			Util::redirect('?do=News');
		}
	}

	protected function doRender()
	{
		// prepare the list of the older news
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

	private function saveNews()
	{
		// check if news content were set by the user
		$newsTitle = Request::post('news-title');
		$newsContent = Request::post('news-content');
		if ($newsContent !== false && $newsTitle !== false) {	
			// we got title and content, save it to DB
			Database::exec("INSERT INTO news (dateline, title, content) VALUES (:dateline, :title, :content)", array(
				'dateline' => time(),
				'title' => $newsTitle,
				'content' => $newsContent
			));
			// all done, redirect to main news page
			Message::addSuccess('news-set-success');
			Util::redirect('?do=News');
		}
	}
	
	private function delNews($newsId)
	{
		if (!is_numeric($newsId)) {
			Message::addError('value-invalid', 'newsid', $newsId);
		} else {
			Database::exec("DELETE FROM news WHERE newsid = :newsid LIMIT 1", array(
				'newsid' => $newsId
			));
			Message::addSuccess('news-del-success');
		}
		Util::redirect('?do=News');
	}

}
