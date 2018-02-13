<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');

		if ($action === 'delimages') {
			if (User::hasPermission("expiredimages.delete")) {
				$result = self::handleDeleteImages();
				if (!empty($result)) {
					Message::addInfo('delete-images', $result);
				}
				Util::redirect('?do=DozMod');
			}
		}
	}

	private static function handleDeleteImages()
	{
		$images = Request::post('images', false);
		if (is_array($images)) {
			foreach ($images as $image => $val) {
				if (strtolower($val) !== 'on')
					continue;
				Database::exec("UPDATE sat.imageversion SET deletestate = 'WANT_DELETE'"
					. " WHERE deletestate = 'SHOULD_DELETE' AND imageversionid = :imageversionid", array(
					'imageversionid' => $image
				));
			}
			if (!empty($images)) {
				$ret = Download::asStringPost('http://127.0.0.1:9080/do/delete-images', false, 10, $code);
				if ($code == 999) {
					$ret .= "\nConnection to DMSD failed.";
				}
				return $ret;
			}
		}
		return false;
	}

	private static function loadExpiredImages()
	{
		$res = Database::simpleQuery("SELECT b.displayname,"
			. " own.firstname, own.lastname, own.email,"
			. " v.imageversionid, v.createtime, v.filesize, v.deletestate,"
			. " lat.expiretime AS latexptime, lat.deletestate AS latdelstate"
			. " FROM sat.imageversion v"
			. " INNER JOIN sat.imagebase b ON (b.imagebaseid = v.imagebaseid)"
			. " INNER JOIN sat.user own ON (b.ownerid = own.userid)"
			. " LEFT JOIN sat.imageversion lat ON (b.latestversionid = lat.imageversionid)"
			. " WHERE v.deletestate <> 'KEEP'"
			. " ORDER BY b.displayname ASC, v.createtime ASC");
		$NOW = time();
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['latexptime'] > $NOW && $row['latdelstate'] === 'KEEP') {
				$row['hasNewerClass'] = 'glyphicon-ok green';
				$row['checked'] = 'checked';
			} else {
				$row['hasNewerClass'] = 'glyphicon-remove red';
			}
			if ($row['deletestate'] === 'WANT_DELETE') {
				$row['name_extra_class'] = 'slx-strike';
			}
			$row['version'] = date('d.m.Y H:i:s', $row['createtime']);
			$row['rawfilesize'] = $row['filesize'];
			$row['filesize'] = Util::readableFileSize($row['filesize']);
			$rows[] = $row;
		}
		return $rows;
	}

	public static function doRender()
	{
		$expiredImages = self::loadExpiredImages();

		if (empty($expiredImages)) {
			Message::addSuccess('no-expired-images');
		} else {
			Render::addTemplate('images-delete', array('images' => $expiredImages, 'allowedDelete' => User::hasPermission("expiredimages.delete")));
		}
	}

	public static function doAjax()
	{
		$action = Request::post('action');
		if ($action === 'delimages') {
			User::assertPermission("expiredimages.delete");
			die(self::handleDeleteImages());
		}
		die('Huh?');
	}

}
