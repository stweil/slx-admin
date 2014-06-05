<?php

class Page_News extends Page
{

	private $latestNews = array();
	
	protected function doPreprocess()
	{
		// load user, we will need it later
		User::load();
		
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
			Util::redirect('?do=News');
		}
		
	}

	protected function doRender()
	{
		// user must be logged in
		if (!User::isLoggedIn()) {
			Render::addTemplate('page-main-guest');
			return;
		}
		
		// only admins should be able to edit news
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			return;
		}
		
		// fetch the latest news
		$res = Database::simpleQuery('SELECT * FROM news ORDER BY dateline DESC LIMIT 1');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$latestTitle = $row['title'];
			$latestContent = $row['content'];
			$latestDate = $row['dateline'];
		}
		// show it to the user
		Render::addDialog('News Verwaltung', false, 'page-news', array(
			'token' => Session::get('token'),
			'latestDate' => DateTime::createFromFormat('U', $latestDate)->format('Y-m-d H:i:s'),
			'latestContent' => $latestContent,
			'latestTitle' => $latestTitle
		));

	}

}
