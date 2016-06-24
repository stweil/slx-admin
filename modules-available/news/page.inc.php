<?php

class Page_News extends Page
{
    /**
     * Member variables needed to represent a news entry.
     *
     * @var newsId int		ID of the news entry attributed by the database.
     * @var string Title of the entry.
     *             $newsContent	string	Content as text. (TODO: html-Support?)
     *             $newsDate	string	Unix epoch date of the news' creation.
     */
    private $newsId = false;
    private $newsTitle = false;
    private $newsContent = false;
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

        // only admins should be able to edit news
        if (!User::hasPermission('superadmin')) {
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
            /* load latest help */
            $this->loadLatestHelp(Request::any('newsid'));

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
            $x = Request::post('news-type');

            if ($x == 'news') {
                if (!$this->saveNews()) {
                    // re-set the fields we got
                    Request::post('news-title') ? $this->newsTitle = Request::post('news-title') : $this->newsTitle = false;
                    Request::post('news-content') ? $this->newsContent = Request::post('news-content') : $this->newsContent = false;
                } else {
                    Message::addSuccess('news-save-success');
                    $lastId = Database::lastInsertId();
                    Util::redirect("?do=News&newsid=$lastId");
                }
            } elseif ($x == 'help') {
                if ($this->saveHelp()) {
                    Message::addSuccess('help-save-success');
                    $lastId = Database::lastInsertId();
                    Util::redirect("?do=News&newsid=$lastId");
                }
            }
        } elseif ($action === 'delete') {
            // delete it
            $this->delNews(Request::post('newsid'));
            $x = Request::any('editHelp');
            Util::redirect("?do=News&editHelp=$x");
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
            $linesHelp[] = $row;
        }
        // print_r($llist);
        // die();

        $paginate->render('page-news', array(
                'token' => Session::get('token'),
                'latestDate' => ($this->newsDate ? date('d.m.Y H:i', $this->newsDate) : '--'),
                'latestContent' => $this->newsContent,
                'latestTitle' => $this->newsTitle,
                'latestHelp' => $this->helpContent,
                'editHelp' => $this->editHelp,
                'list' => $lines,
                'listHelp' => $linesHelp,
                'hasSummernote' => $this->hasSummernote ));
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
            } else {
                $this->editHelp = true;
                $this->helpContent = $row['content'];
            }
        }

        return $row !== false;
    }

    private function loadLatestHelp()
    {
        $row = Database::queryFirst("SELECT newsid, content, dateline, type FROM vmchooser_pages WHERE type='help' ORDER BY dateline DESC LIMIT 1", []);
        if ($row !== false) {
            $this->helpContent = $row['content'];
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
