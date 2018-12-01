<?php

class SubPage
{
	private static $show;

	public static function doPreprocess()
	{
		self::$show = Request::any('show', false, 'string');
		$action = Request::post('action');

		if ($action === 'deleteFilter') {
			User::assertPermission("ldapfilters.save");
			self::deleteLdapFilter();
		} else if ($action === 'saveFilter') {
			User::assertPermission("ldapfilters.save");
			self::saveLdapFilter();
		}
		User::assertPermission("ldapfilters.view");
	}

	public static function doRender()
	{
		if (self::$show === false) {
			// Get all ldapfilters from the sat db.
			$ldapfilters = Database::queryAll("SELECT filterid, filtername, filterkey, filtervalue FROM sat.presetlecturefilter
					WHERE filtertype ='LDAP' ORDER BY filtername ASC");

			$data = array(
				'ldapfilters' => $ldapfilters,
				'hasEditPermission' => User::hasPermission('ldapfilters.save')
			);

			Render::addTemplate('ldapfilters', $data);
		} else if (self::$show === 'edit') {
			$filterid = Request::get('filterid', false, 'int');

			if ($filterid === false) {
				Render::addTemplate('ldapfilter-add', array(
					'filterid' => 0
				));
			} else {
				$ldapfilter = Database::queryFirst("SELECT filterid, filtername, filterkey, filtervalue FROM sat.presetlecturefilter
						WHERE filterid = :id AND filtertype = 'LDAP'", array( 'id' => $filterid));
				// TODO: Show error if not exists

				Render::addTemplate('ldapfilter-add', $ldapfilter);
			}
		}
	}

	private static function deleteLdapFilter() {
		User::assertPermission('ldapfilters.save');
		$filterid = Request::post('filterid', false, 'int');
		if ($filterid === false) {
			Message::addError('ldap-filter-id-missing');
			return;
		}
		$res = Database::exec("DELETE FROM sat.presetlecturefilter WHERE filterid = :id AND filtertype = 'LDAP'", array('id' => $filterid));
		if ($res !== 1) {
			Message::addWarning('ldap-invalid-filter-id', $filterid);
		} else {
			Message::addSuccess('ldap-filter-deleted');
		}
	}

	private static function saveLdapFilter() {
		$filterid = Request::post('filterid', '', 'int');
		$filtername = Request::post('filtername', false, 'string');
		$filterattribute = Request::post('attribute', false, 'string');
		$filtervalue = Request::post('value', false, 'string');

		if ($filtername === false || $filterattribute === false || $filtervalue === false) {
			Message::addError('ldap-filter-save-missing-information');
			return;
		}

		if ($filterid === 0) {
			// Insert filter in the db.
			$res = Database::exec("INSERT INTO sat.presetlecturefilter (filtertype, filtername, filterkey, filtervalue)
					VALUES ('LDAP', :filtername, :attribute, :value)", array(
				'filtername' => $filtername,
				'attribute' => $filterattribute,
				'value' => $filtervalue
			));

			if ($res !== 1) {
				Message::addError('ldap-filter-insert-failed');
			} else {
				Message::addSuccess('ldap-filter-created');
			}

		} else {
			// Update filter in the db.
			$res = Database::exec("UPDATE sat.presetlecturefilter SET
					filtername = :filtername, filterkey = :attribute, filtervalue = :value
					WHERE filterid = :filterid AND filtertype = 'LDAP'", array(
				'filterid' => $filterid,
				'filtername' => $filtername,
				'attribute' => $filterattribute,
				'value' => $filtervalue
			));

			if ($res !== 1) {
				Message::addError('ldap-filter-insert-failed');
			} else {
				Message::addSuccess('ldap-filter-saved');
			}

		}
		Util::redirect("?do=dozmod&section=ldapfilters");
	}

	public static function doAjax()
	{

	}

}