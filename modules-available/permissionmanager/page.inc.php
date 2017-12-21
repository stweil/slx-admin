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
			$rolename = Request::post("rolename");
			$locations = self::processLocations(Request::post("locations"));
			$permissions = self::processPermissions(Request::post("permissions"));
			PermissionDbUpdate::saveRole($rolename, $locations, $permissions, $roleID);
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
			$buttonColors = array();
			$buttonColors['rolesButtonClass'] = $show === 'roles' ? 'active' : '';
			$buttonColors['usersButtonClass'] = $show === 'users' ? 'active' : '';
			$buttonColors['locationsButtonClass'] = $show === 'locations' ? 'active' : '';

			Render::addtemplate('_page', $buttonColors);

			if ($show === "roles") {
				$data = array("roles" => GetPermissionData::getRoles());
				Render::addTemplate('rolestable', $data);
			} elseif ($show === "users") {
				$data = array("user" => GetPermissionData::getUserData(), "roles" => GetPermissionData::getRoles());
				Render::addTemplate('userstable', $data);
			} elseif ($show === "locations") {
				$data = array("location" => GetPermissionData::getLocationData(), "allroles" => GetPermissionData::getRoles());
				Render::addTemplate('locationstable', $data);
			}
		} elseif ($show === "roleEditor") {
			$data = array("cancelShow" => Request::get("cancel", "roles"));

			$selectedPermissions = array();
			$selectedLocations = array();
			$roleid = Request::get("roleid", false);
			if ($roleid) {
				$roleData = GetPermissionData::getRoleData($roleid);
				$data["roleid"] = $roleid;
				$data["rolename"] = $roleData["rolename"];
				$selectedPermissions = $roleData["permissions"];
				$selectedLocations = $roleData["locations"];
			}

			$data["permissionHTML"] = self::generatePermissionHTML(PermissionUtil::getPermissions(), $selectedPermissions);
			$data["locationHTML"] = self::generateLocationHTML(Location::getTree(), $selectedLocations);

			Render::addTemplate('roleeditor', $data);

		}
	}

	/**
	 * Recursively generate HTML code for the permission selection tree.
	 *
	 * @param array $permissions the permission tree
	 * @param array $selectedPermissions permissions that should be preselected
	 * @param array $selectAll true if all pemrissions should be preselected, false if only those in $selectedPermissions
	 * @param array $permString the prefix permission string with which all permissions in the permission tree should start
	 * @return string generated html code
	 */
	private static function generatePermissionHTML($permissions, $selectedPermissions = array(), $selectAll = false, $permString = "")
	{
		$res = "";
		$toplevel = $permString == "";
		if ($toplevel && in_array("*", $selectedPermissions)) $selectAll = true;
		foreach ($permissions as $k => $v) {
			$leaf = !is_array($v);
			$nextPermString = $permString ? $permString.".".$k : $k;
			$id = $leaf ? $nextPermString : $nextPermString.".*";
			$selected = $selectAll || in_array($id, $selectedPermissions);
			$res .= Render::parse("treenode",
				array("id" =>  $id,
						"name" => $toplevel ? Module::get($k)->getDisplayName() : $k,
						"toplevel" => $toplevel,
						"checkboxname" => "permissions",
						"selected" => $selected,
						"HTML" => $leaf ? "" : self::generatePermissionHTML($v, $selectedPermissions, $selected, $nextPermString),
						"description" => $leaf ? $v : ""));
		}
		if ($toplevel) {
			$res = Render::parse("treepanel",
				array("id" => "*",
						"name" => Dictionary::translateFile("template-tags", "lang_Permissions"),
						"checkboxname" => "permissions",
						"selected" => $selectAll,
						"HTML" => $res));
		}
		return $res;
	}

	/**
	 * Recursively generate HTML code for the location selection tree.
	 *
	 * @param array $locations the location tree
	 * @param array $selectedLocations locations that should be preselected
	 * @param array $selectAll true if all locations should be preselected, false if only those in $selectedLocations
	 * @param array $toplevel true if the location tree are the children of the root location, false if not
	 * @return string generated html code
	 */
	private static function generateLocationHTML($locations, $selectedLocations = array(), $selectAll = false, $toplevel = true)
	{
		$res = "";
		if ($toplevel && in_array(0, $selectedLocations)) $selectAll = true;
		foreach ($locations as $location) {
			$selected = $selectAll || in_array($location["locationid"], $selectedLocations);
			$res .= Render::parse("treenode",
				array("id" =>  $location["locationid"],
						"name" => $location["locationname"],
						"toplevel" => $toplevel,
						"checkboxname" => "locations",
						"selected" => $selected,
						"HTML" => array_key_exists("children", $location) ?
							self::generateLocationHTML($location["children"], $selectedLocations, $selected, false) : ""));
		}
		if ($toplevel) {
			$res = Render::parse("treepanel",
				array("id" => 0,
						"name" => Dictionary::translateFile("template-tags", "lang_Locations"),
						"checkboxname" => "locations",
						"selected" => $selectAll,
						"HTML" => $res));
		}
		return $res;
	}

	/**
	 * Remove locations that are already covered by parent locations from the array.
	 *
	 * @param array $locations the locationid array
	 * @return array the locationid array without redundant locationids
	 */
	private static function processLocations($locations)
	{
		if (in_array(0, $locations)) return array(NULL);
		$result = array();
		foreach ($locations as $location) {
			$rootchain = array_reverse(Location::getLocationRootChain($location));
			foreach ($rootchain as $l) {
				if (in_array($l, $result)) break;
				if (in_array($l, $locations)) {
					$result[] = $l;
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * Remove permissions that are already covered by parent permissions from the array.
	 *
	 * @param array $permissions the permissionid array
	 * @return array the permissionid array without redundant permissionids
	 */
	private static function processPermissions($permissions)
	{
		if (in_array("*", $permissions)) return array("*");
		$result = array();
		foreach ($permissions as $permission) {
			$x =& $result;
			foreach (explode(".", $permission) as $p) {
				$x =& $x[$p];
			}
		}
		return self::extractPermissions($result);
	}

	/**
	 * Convert a multidimensional array of permissions to a flat array of permissions.
	 *
	 * @param array $permissions multidimensional array of permissionids
	 * @return array flat array of permissionids
	 */
	private static function extractPermissions($permissions)
	{
		$result = array();
		foreach ($permissions as $permission => $a) {
			if (is_array($a)) {
				if (array_key_exists("*", $a)) {
					$result[] = $permission.".*";
				} else {
					foreach (self::extractPermissions($a) as $subPermission) {
						$result[] = $permission.".".$subPermission;
					}
				}
			} else {
				$result[] = $permission;
			}
		}
		return $result;
	}

}
