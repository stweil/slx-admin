<?php

class Page_Locations extends Page
{

	private static function loadPage()
	{
		$page = Request::any('page', 'locations', 'string');
		if (!in_array($page, ['locations', 'subnets', 'details', 'cleanup'])) {
			Message::addError('main.invalid-action', $page);
			Util::redirect('?do=Main');
		}
		require_once Page::getModule()->getDir() . '/pages/' . $page . '.inc.php';
	}

	/*
	 * Action handling
	 */

	protected function doPreprocess()
	{
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		self::loadPage();
		if (Request::isPost()) {
			$action = Request::post('action');
			if (!SubPage::doPreprocess($action)) {
				Message::addError('main.invalid-action', $action);
			}
			Util::redirect('?do=locations');
		}
	}

	/*
	 * Rendering normal pages
	 */

	protected function doRender()
	{
		$getAction = Request::get('action', false, 'string');
		if (!SubPage::doRender($getAction)) {
			Message::addError('main.invalid-action', $getAction);
			Util::redirect('?do=locations');
		}
	}

	/*
	 * Ajax
	 */

	protected function doAjax()
	{
		User::load();
		if (!User::isLoggedIn()) {
			die('Unauthorized');
		}
		self::loadPage();
		$action = Request::any('action');
		if (!SubPage::doAjax($action)) {
			die('Invalid action ' . $action);
		}
	}

}
