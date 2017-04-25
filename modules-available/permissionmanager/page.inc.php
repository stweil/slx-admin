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
			PermissionDbUpdate::addRoleToUser($users, $roles);
		} elseif ($action === 'removeRoleFromUser') {
			$users = Request::post('users', '');
			$roles = Request::post('roles', '');
			PermissionDbUpdate::removeRoleFromUser($users, $roles);
		} elseif ($action === 'deleteRole') {
			$id = Request::post('deleteId', false, 'string');
			PermissionDbUpdate::deleteRole($id);
		} elseif ($action === 'saveRole') {
			$roleID = Request::post("roleid", false);
			$roleName = Request::post("roleName");
			$locations = Request::post("allLocations", "off") == "on" ? array(0) : Request::post("locations");
			$permissions = Request::post("allPermissions", "off") == "on" ? array("*") : Request::post("permissions");;
			PermissionDbUpdate::saveRole($roleName, $locations, $permissions, $roleID);
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
				$data = array("roles" => GetPermissionData::getRoles());
				Render::addTemplate('rolestable', $data);
			} elseif ($show === "users") {
				$data = array("user" => GetPermissionData::getUserData(), "roles" => GetPermissionData::getRoles());
				Render::addTemplate('userstable', $data);
			} elseif ($show === "locations") {
				$data = array("location" => GetPermissionData::getLocationData(), "roles" => GetPermissionData::getRoles());
				Render::addTemplate('locationstable', $data);
			}
		} elseif ($show === "roleEditor") {
			$data = array();

			$roleID = Request::get("roleid", false);
			$selectedLocations = array();
			if ($roleID) {
				$roleData = GetPermissionData::getRoleData($roleID);
				$data["roleid"] = $roleID;
				$data["roleName"] = $roleData["name"];
				if (count($roleData["locations"]) == 1 && $roleData["locations"][0] == 0) {
					$data["allLocChecked"] = "checked";
					$data["selectizeClass"] = "faded unclickable";
				} else {
					$data["allLocChecked"] = "";
					$data["selectizeClass"] = "";
					$selectedLocations = $roleData["locations"];
				}
				if (count($roleData["permissions"]) == 1 && $roleData["permissions"][0] == "*") {
					$data["allPermChecked"] = "checked";
					$data["permissionsClass"] = "faded unclickable";
				} else {
					$data["allPermChecked"] = "";
					$data["permissionsClass"] = "";
					$data["selectedPermissions"] = implode(" ", $roleData["permissions"]);
				}
			}

			$permissions = PermissionUtil::getPermissions();

			$data["locations"] = GetPermissionData::getLocations($selectedLocations);
			$data["moduleNames"] = array();
			foreach (array_keys($permissions) as $moduleid) {
				$data["moduleNames"][] = array("id" => $moduleid, "name" => Module::get($moduleid)->getDisplayName());
			}
			$data["permissionHTML"] = self::generatePermissionHTML($permissions, "*");
			Render::addTemplate('roleeditor', $data);

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

	private static function generatePermissionHTML($subPermissions, $permString)
	{
		$genModuleBox = $permString == "*";
		$res = "";
		foreach ($subPermissions as $k => $v) {
			$res .= Render::parse($genModuleBox ? "modulepermissionbox" : (is_array($v) ? "permissiontreenode" : "permission"),
				array("id" =>  $genModuleBox ? $k : $permString.".".$k,
						"name" => $genModuleBox ? Module::get($k)->getDisplayName(): $k,
						"HTML" => is_array($v) ? self::generatePermissionHTML($v, $genModuleBox ? $k : $permString.".".$k) : "",
						"description" => $v));
		}
		return $res;
	}

}
