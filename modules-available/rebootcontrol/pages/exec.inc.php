<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'exec') {
			self::execExec();
		}
	}

	private static function execExec()
	{
		$id = Request::post('id', Request::REQUIRED, 'int');
		$machines = Session::get('exec-' . $id);
		if (!is_array($machines)) {
			Message::addError('unknown-exec-job', $id);
			return;
		}
		$script = preg_replace('/\r\n?/', "\n", Request::post('script', Request::REQUIRED, 'string'));
		$task = RebootControl::runScript($machines, $script);
		if (Taskmanager::isTask($task)) {
			Util::redirect("?do=rebootcontrol&show=task&what=task&taskid=" . $task["id"]);
		}
	}

	/*
	 * Render
	 */

	public static function doRender()
	{
		$what = Request::get('what', 'list', 'string');
		if ($what === 'prepare') {
			self::showPrepare();
		}
	}

	private static function showPrepare()
	{
		$id = Request::get('id', Request::REQUIRED, 'int');
		$machines = Session::get('exec-' . $id);
		if (!is_array($machines)) {
			Message::addError('unknown-exec-job', $id);
			return;
		}
		Render::addTemplate('exec-enter-command', ['clients' => $machines, 'id' => $id]);
	}

	public static function doAjax()
	{

	}

}