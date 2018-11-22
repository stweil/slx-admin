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
			$ldapfilters = Database::simpleQuery("SELECT * FROM sat.ldapfilter");

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
				$ldapfilter = Database::queryFirst("SELECT * FROM sat.ldapfilter WHERE filterid=:id", array( 'id' => $filterid));

				$data = array(
					'filterid' => $filterid,
					'filtername' => $ldapfilter['filtername'],
					'attribute' => $ldapfilter['attribute'],
					'value' => $ldapfilter['value']
				);
				Render::addTemplate('ldapfilter-add', $data);
			}
		}
	}

	private function deleteLdapFilter() {
		User::assertPermission('ldapfilters.save');
		$filterid = Request::post('filterid', false, 'int');
		if ($filterid === false) {
			Message::addError('ldap-filter-id-missing');
			return;
		}
		$res = Database::exec("DELETE FROM sat.ldapfilter WHERE filterid=:id", array('id' => $filterid));
		if ($res !== 1) {
			Message::addWarning('ldap-invalid-filter-id', $filterid);
		} else {
			Message::addSuccess('ldap-filter-deleted');
		}
	}

	private function saveLdapFilter() {
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
			$res = Database::exec("INSERT INTO sat.ldapfilter (filtername, attribute, value) VALUES (:filtername, :attribute, :value)", array(
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
			$res = Database::exec("UPDATE sat.ldapfilter SET filtername=:filtername, attribute=:attribute, value=:value WHERE filterid=:filterid", array(
				'filterid' => $filterid,
				'filtername' => $filtername,
				'attribute' => $filterattribute,
				'value' => $filtervalue
			));

			if ($res !== 1) {
				Message::addError('ldap-filter-insert-failed');
			} else {
				Message::addSuccess('ldap-filter-created');
			}

		}
		Util::redirect("?do=dozmod&section=ldapfilters");
	}

	public static function doAjax()
	{

	}

}