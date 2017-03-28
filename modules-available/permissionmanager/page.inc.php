<?php

class Page_PermissionManager extends Page
{

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}

		$action = Request::any('action', 'show', 'string');
		if ($action === 'addRoleToUser') {
			$users = Request::post('users', '');
			$roles = Request::post('roles', '');
			DbUpdate::addRoleToUser($users, $roles);
		} else if ($action === 'removeRoleFromUser') {
			$users = Request::post('users', '');
			$roles = Request::post('roles', '');
			DbUpdate::removeRoleFromUser($users, $roles);
		} else if ($action === 'deleteRole') {
			$id = Request::post('deleteId', false, 'string');
			DbUpdate::deleteRole($id);
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		$show = Request::get("show", false);
		// get menu button colors
		$buttonColors = self::setButtonColors($show);

		$data = array();

		// switch between tables, but always show menu to switch tables
		if (!$show || $show === 'roles' || $show === 'users' || $show === 'locations') {
			Render::openTag('div', array('class' => 'row'));
			Render::addtemplate('_page', $buttonColors);
			Render::closeTag('div');

			if ($show === "roles") {
				$data = array("roles" => GetData::getRoles());
				Render::addTemplate('rolesTable', $data);
			} else if ($show === "users") {
				$data = array("user" => GetData::getUserData(), "roles" => GetData::getRoles());
				Render::addTemplate('usersTable', $data);
			} else if ($show === "locations") {
				Render::addTemplate('locationsTable', $data);
			}
		}
	}

	// Menu: Selected table is shown in blue (btn-primary)
	private function setButtonColors($show) {
		if ($show === 'roles') {
			$buttonColors['rolesButtonClass'] = 'btn-primary';
			$buttonColors['usersButtonClass'] = 'btn-default';
			$buttonColors['locationsButtonClass'] = 'btn-default';
		} else if ($show === 'users') {
			$buttonColors['rolesButtonClass'] = 'btn-default';
			$buttonColors['usersButtonClass'] = 'btn-primary';
			$buttonColors['locationsButtonClass'] = 'btn-default';
		} else if ($show === 'locations') {
			$buttonColors['rolesButtonClass'] = 'btn-default';
			$buttonColors['usersButtonClass'] = 'btn-default';
			$buttonColors['locationsButtonClass'] = 'btn-primary';
		}  else {
			$buttonColors['rolesButtonClass'] = 'btn-default';
			$buttonColors['usersButtonClass'] = 'btn-default';
			$buttonColors['locationsButtonClass'] = 'btn-default';
		}

		return $buttonColors;
	}

}
