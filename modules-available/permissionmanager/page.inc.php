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
		} elseif ($action === 'removeRoleFromUser') {
			$users = Request::post('users', '');
			$roles = Request::post('roles', '');
			DbUpdate::removeRoleFromUser($users, $roles);
		} elseif ($action === 'deleteRole') {
			$id = Request::post('deleteId', false, 'string');
			DbUpdate::deleteRole($id);
		} elseif ($action === 'saveRole') {
			$roleID = Request::post("roleid", false);
			$roleName = Request::post("roleName");
			$locations = Request::post("allLocations", "off") == "on" ? array(0) : Request::post("locations");
			$permissions = Request::post("permissions");
			DbUpdate::saveRole($roleName, $locations, $permissions, $roleID);
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		$show = Request::get("show", "roles");

		// switch between tables, but always show menu to switch tables
		if ( $show === 'roles' || $show === 'users' || $show === 'locations' ) {
			// get menu button colors
			$buttonColors = self::setButtonColors($show);

			$data = array();

			Render::openTag('div', array('class' => 'row'));
			Render::addtemplate('_page', $buttonColors);
			Render::closeTag('div');

			if ($show === "roles") {
				$data = array("roles" => GetData::getRoles());
				Render::addTemplate('rolesTable', $data);
			} elseif ($show === "users") {
				$data = array("user" => GetData::getUserData(), "roles" => GetData::getRoles());
				Render::addTemplate('usersTable', $data);
			} elseif ($show === "locations") {
				$data = array("location" => GetData::getLocationData());
				Render::addTemplate('locationsTable', $data);
			}
		} elseif ($show === "roleEditor") {
			$data = array();

			$roleID = Request::get("roleid", false);
			$selectedLocations = array();
			if ($roleID) {
				$roleData = GetData::getRoleData($roleID);
				$data["roleid"] = $roleID;
				$data["roleName"] = $roleData["name"];
				if (count($roleData["locations"]) == 1 && $roleData["locations"][0] == 0) {
					$data["allLocChecked"] = "checked";
					$data["selectizeClass"] = "disabled";
				} else {
					$data["allLocChecked"] = "";
					$data["selectizeClass"] = "";
					$selectedLocations = $roleData["locations"];
				}
				$data["selectedPermissions"] = implode(" ", $roleData["permissions"]);
			} else {
				$data["allLocChecked"] = "checked";
				$data["selectizeClass"] = "disabled";
			}

			$permissions = PermissionUtil::getPermissions();
			$permissionHTML = "";
			foreach ($permissions as $k => $v) {
				$name = Dictionary::translateFileModule($k, "module", "module_name");
				$permissionHTML .= "
				<div id='$k' class='panel panel-primary module-box' style='display: none;'>
					<div class='panel-heading'>
						<div class='checkbox'>
							<input name='permissions[]' value='$k.*' type='checkbox' class='form-control'>
							<label>$name</label>
						</div>
					</div>
					<div class='panel-body'>
			";
				$permissionHTML .= self::generateSubPermissionHTML($v, $k);
				$permissionHTML .= "</div></div>";
			}

			$data["locations"] = GetData::getLocations($selectedLocations);
			$data["moduleNames"] = array();
			foreach (array_keys($permissions) as $moduleid) {
				$data["moduleNames"][] = array("id" => $moduleid,
					"name" => Dictionary::translateFileModule($moduleid, "module", "module_name"));
			}

			$data["permissionHTML"] = $permissionHTML;
			Render::addTemplate('roleEditor', $data);
		}
	}

	// Menu: Selected table is shown in blue (btn-primary)
	private function setButtonColors($show) {
		if ($show === 'roles') {
			$buttonColors['rolesButtonClass'] = 'btn-primary';
			$buttonColors['usersButtonClass'] = 'btn-default';
			$buttonColors['locationsButtonClass'] = 'btn-default';
		} elseif ($show === 'users') {
			$buttonColors['rolesButtonClass'] = 'btn-default';
			$buttonColors['usersButtonClass'] = 'btn-primary';
			$buttonColors['locationsButtonClass'] = 'btn-default';
		} elseif ($show === 'locations') {
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

	private static function generateSubPermissionHTML($subPermissions, $permissionString)
	{
		$html = "<ul class='list-group'>";
		foreach ($subPermissions as $k => $v) {
			$tmpPermString = $permissionString.".".$k;
			$checkBoxValue  = $tmpPermString;
			if (is_array($v)) {
				$checkBoxValue .= ".*";
			} else {
				$k .= " - ".$v;
			}
			$html .= "
				<li class='list-group-item'>
					<div class='checkbox'>
						<input name='permissions[]' value='$checkBoxValue' type='checkbox' class='form-control'>
						<label>$k</label>
					</div>
			";
			if (is_array($v)) {
				$html .= self::generateSubPermissionHTML($v, $tmpPermString);
			}
			$html .= "</li>";
		}
		$html .= "</ul>";
		return $html;
	}

}
