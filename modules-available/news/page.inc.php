<?php

class Page_News extends Page
{
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
	 * @var string Content as text. (TODO: html-Support?)
	 */
	private $newsContent = false;
	/**
	 * @var int Unix epoch date of the news' creation.
	 */
	private $newsDate = false;
	private $helpContent = '';
	private $editHelp = false;
	private $hasSummernote = false;

    /**
     * Implementation of the abstract doPreprocess function.
     *
     * Checks if the user is logged in and processes any
     * action if one was specified in the request.
     */
    protected function doPreprocess()
    {
        /* load summernote module if available */
        $this->hasSummernote = Module::isAvailable('summernote');

        // load user, we will need it later
        User::load();
        if (!User::isLoggedIn()) {
        		Message::addError('main.no-permission');
        		Util::redirect('?do=Main');
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
            /* load latest things */
            $this->loadLatest('help');
            $this->loadLatest('news');

            /* and also the news (or help) with the given id */
            if (!$this->loadNews(Request::any('newsid'))) {
                Message::addError('news-empty');
            }

            if (Request::any('editHelp')) {
                $this->editHelp = true;
            }
        } elseif ($action === 'save') {
            // save to DB
            /* find out whether it's news or help */
            $pageType = Request::post('news-type');

            if ($pageType == 'news') {
            	if (User::hasPermission("news.save")) {
						if (!$this->saveNews()) {
							// re-set the fields we got
							Request::post('news-title') ? $this->newsTitle = Request::post('news-title') : $this->newsTitle = false;
							Request::post('news-content') ? $this->newsContent = Request::post('news-content') : $this->newsContent = false;
						} else {
							Message::addSuccess('news-save-success');
							$lastId = Database::lastInsertId();
							Util::redirect("?do=News&newsid=$lastId");
						}
					}
            } elseif ($pageType == 'help') {
            	if (User::hasPermission("help.save")) {
						if ($this->saveHelp()) {
							Message::addSuccess('help-save-success');
							$lastId = Database::lastInsertId();
							Util::redirect("?do=News&newsid=$lastId");
						}
					}
            }
        } elseif ($action === 'delete') {
            // delete it
			  $pageType = Request::post('news-type');

			  if ($pageType == 'news') {
			  		if(User::hasPermission("news.delete")) {
						$this->delNews(Request::post('newsid'));
						Util::redirect('?do=News&editHelp='.Request::any('editHelp'));
					}
			  } elseif ($pageType == 'help') {
			  		if(User::hasPermission("help.delete")) {
						$this->delNews(Request::post('newsid'));
						Util::redirect('?do=News&editHelp='.Request::any('editHelp'));
					}
			  }
        } else {
            // unknown action, redirect user
            Message::addError('invalid-action', $action);
        }
    }

    /**
     * Implementation of the abstract doRender function.
     *
     * Fetch the list of news from the database and paginate it.
     */
    protected function doRender()
    {
        // fetch the list of the older news
        $lines = array();
        $paginate = new Paginate("SELECT newsid, dateline, title, content FROM vmchooser_pages WHERE type='news' ORDER BY dateline DESC", 10);
        $res = $paginate->exec();
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $row['date'] = date('d.m.Y H:i', $row['dateline']);

            if ($row['newsid'] == $this->newsId) {
                $row['active'] = 'active';
            }
            $row['content'] = strip_tags(str_replace('>', '> ', $row['content']));
            $lines[] = $row;
        }
        // fetch the list of the older helps
        $linesHelp = array();
        $paginateHelp = new Paginate("SELECT newsid, dateline, content FROM vmchooser_pages WHERE type='help' ORDER BY dateline DESC", 10);
        $resHelp = $paginateHelp->exec();
        while ($row = $resHelp->fetch(PDO::FETCH_ASSOC)) {
            $row['date'] = date('d.m.Y H:i', $row['dateline']);
            if ($row['newsid'] == $this->newsId) {
                $row['active'] = 'active';
            }
			  $row['content'] = strip_tags(str_replace('>', '> ', $row['content']));
            $linesHelp[] = $row;
        }

        $paginate->render('page-news', array(
                'token' => Session::get('token'),
                'latestDate' => ($this->newsDate ? date('d.m.Y H:i', $this->newsDate) : '--'),
                'latestContent' => $this->newsContent,
                'latestTitle' => $this->newsTitle,
                'latestHelp' => $this->helpContent,
                'editHelp' => $this->editHelp,
                'list' => $lines,
                'listHelp' => $linesHelp,
			  		 'allowedNewsSave' => User::hasPermission("news.save"),
			  		 'allowedNewsDelete' => User::hasPermission("news.delete"),
			  		 'allowedHelpSave' => User::hasPermission("help.save"),
			  		 'allowedHelpDelete' => User::hasPermission("help.delete"),
                'hasSummernote' => $this->hasSummernote, ));
    }
    /**
     * Loads the news with the given ID into the form.
     *
     * @param int $newsId ID of the news to be shown.
     *
     * @return bool true if loading that news worked
     */
    private function loadNews($newsId)
    {
        // check to see if we need to request a specific newsid
        if ($newsId !== false) {
            $row = Database::queryFirst('SELECT newsid, title, content, dateline, type FROM vmchooser_pages WHERE newsid = :newsid LIMIT 1', array(
                'newsid' => $newsId,
            ));
        } else {
            $row = Database::queryFirst("SELECT newsid, title, content, dateline, type FROM vmchooser_pages WHERE type='news' ORDER BY dateline DESC LIMIT 1");
        }

        // fetch the news to be shown
        if ($row !== false) {
            if ($row['type'] == 'news') {
                $this->newsId = $row['newsid'];
                $this->newsTitle = $row['title'];
                $this->newsContent = $row['content'];
                $this->newsDate = $row['dateline'];
                $this->editHelp = false;
            } else {
                $this->editHelp = true;
                $this->helpContent = $row['content'];
            }
        }

        return $row !== false;
    }

    private function loadLatest($type)
    {
        $row = Database::queryFirst("SELECT newsid, title, content, dateline, type FROM vmchooser_pages WHERE type=:type ORDER BY dateline DESC LIMIT 1", ['type' => $type]);
        if ($row !== false) {
            if ($row['type'] == 'news') {
                $this->newsId = $row['newsid'];
                $this->newsTitle = $row['title'];
                $this->newsContent = $row['content'];
                $this->newsDate = $row['dateline'];
            } else {
                $this->helpContent = $row['content'];
            }
        }
    }

    /**
     * Save the given $newsTitle and $newsContent as POST'ed into the database.
     */
    private function saveNews()
    {
        // check if news content were set by the user
        $newsTitle = Request::post('news-title');
        $newsContent = Request::post('news-content');
        if ($newsContent !== '' && $newsTitle !== '') {
            // we got title and content, save it to DB
            Database::exec("INSERT INTO vmchooser_pages (dateline, title, content, type) VALUES (:dateline, :title, :content, 'news')", array(
                'dateline' => time(),
                'title' => $newsTitle,
                'content' => $newsContent,
            ));

            return true;
        } else {
            Message::addError('main.empty-field');

            return false;
        }
    }
    private function saveHelp()
    {
        $content = Request::post('help-content');
        if ($content !== '') {
            Database::exec("INSERT INTO vmchooser_pages (dateline, content, type) VALUES (:dateline, :content, 'help')", array(
                'dateline' => time(),
                'content' => $content,
            ));

            return true;
        } else {
            Message::addError('main.empty-field');

            return false;
        }
    }

    /**
     * Delete the news entry with ID $newsId.
     *
     * @param int $newsId ID of the entry to be deleted.
     */
    private function delNews($newsId)
    {
        // sanity check: is newsId even numeric?
        if (!is_numeric($newsId)) {
            Message::addError('main.value-invalid', 'newsid', $newsId);
        } else {
            // check passed - do delete
            Database::exec('DELETE FROM vmchooser_pages WHERE newsid = :newsid LIMIT 1', array(
                'newsid' => $newsId,
            ));
            Message::addSuccess('news-del-success');
        }
    }
}
