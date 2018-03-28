<?php

class SubPage
{

	private static $action;
	private static $uuid;

	public static function doPreprocess()
	{
		User::assertPermission("actionlog.view");
		self::$action = Request::get('action', '', 'string');
		if (self::$action !== '' && self::$action !== 'showtarget' && self::$action !== 'showuser') {
			Util::traceError('Invalid action for actionlog: "' . self::$action . '"');
		}
		self::$uuid = Request::get('uuid', '', 'string');
	}

	public static function doRender()
	{
		Render::addTemplate('actionlog-header');
		if (self::$action === '') {
			self::generateLog("SELECT al.dateline, al.targetid, al.description,"
				. " img.displayname AS imgname, tu.firstname AS tfirstname, tu.lastname AS tlastname, l.displayname AS lecturename,"
				. " al.userid AS uuserid, usr.firstname AS ufirstname, usr.lastname AS ulastname"
				. " FROM sat.actionlog al"
				. " LEFT JOIN sat.imagebase img ON (img.imagebaseid = targetid)"
				. " LEFT JOIN sat.user usr ON (usr.userid = al.userid)"
				. " LEFT JOIN sat.user tu ON (tu.userid = al.targetid)"
				. " LEFT JOIN sat.lecture l ON (l.lectureid = targetid)"
				. " ORDER BY al.dateline DESC LIMIT 500", array(), true, true);
		} elseif (self::$action === 'showuser') {
			self::listUser();
		} else {
			self::listTarget();
		}
	}

	private static function listUser()
	{
		// Query user
		$user = Database::queryFirst('SELECT userid, firstname, lastname, email, lastlogin,'
			. ' organization.displayname AS orgname FROM sat.user'
			. ' LEFT JOIN sat.organization USING (organizationid)'
			. ' WHERE userid = :uuid'
			. ' LIMIT 1', array('uuid' => self::$uuid));
		if ($user === false) {
			Message::addError('unknown-userid', self::$uuid);
			Util::redirect('?do=dozmod&section=actionlog');
		}
		// Mangle date and render
		$user['lastlogin_s'] = date('d.m.Y H:i', $user['lastlogin']);
		Render::addTemplate('actionlog-user', $user);
		// Finally add the actionlog
		self::generateLog("SELECT al.dateline, al.targetid, al.description,"
			. " img.displayname AS imgname, usr.firstname AS tfirstname, usr.lastname AS tlastname, l.displayname AS lecturename"
			. " FROM sat.actionlog al"
			. " LEFT JOIN sat.imagebase img ON (img.imagebaseid = targetid)"
			. " LEFT JOIN sat.user usr ON (usr.userid = targetid)"
			. " LEFT JOIN sat.lecture l ON (l.lectureid = targetid)"
			. " WHERE al.userid = :uuid"
			. " ORDER BY al.dateline DESC LIMIT 500", array('uuid' => self::$uuid), false, true);
	}

	private static function listTarget()
	{
		// We have to guess what kind of target it is
		if (!self::addImageHeader()
				&& !self::addLectureHeader()) {
			Message::addError('unknown-targetid', self::$uuid);
			// Keep going, there might still be log entries for a deleted uuid
		}

		// Finally add the actionlog
		self::generateLog("SELECT al.dateline, al.userid AS uuserid, al.description,"
			. " usr.firstname AS ufirstname, usr.lastname AS ulastname"
			. " FROM sat.actionlog al"
			. " LEFT JOIN sat.user usr ON (usr.userid = al.userid)"
			. " WHERE al.targetid = :uuid"
			. " ORDER BY al.dateline DESC LIMIT 500", array('uuid' => self::$uuid), true, false);
	}

	private static function addImageHeader()
	{
		$image = Database::queryFirst('SELECT o.userid AS ouserid, o.firstname AS ofirstname, o.lastname AS olastname,'
			. ' u.userid AS uuserid, u.firstname AS ufirstname, u.lastname AS ulastname,'
			. ' img.displayname, img.description, img.createtime, img.updatetime,'
			. ' os.displayname AS osname'
			. ' FROM sat.imagebase img'
			. ' LEFT JOIN sat.user o ON (img.ownerid = o.userid)'
			. ' LEFT JOIN sat.user u ON (img.updaterid = u.userid)'
			. ' LEFT JOIN sat.operatingsystem os ON (img.osid = os.osid)'
			. ' WHERE img.imagebaseid = :uuid'
			. ' LIMIT 1', array('uuid' => self::$uuid));
		if ($image !== false) {
			// Mangle date and render
			$image['createtime_s'] = date('d.m.Y H:i', $image['createtime']);
			$image['updatetime_s'] = date('d.m.Y H:i', $image['updatetime']);
			$image['descriptionHtml'] = nl2br(htmlspecialchars($image['description']));
			Render::addTemplate('actionlog-image', $image);
		}
		return $image !== false;
	}

	private static function addLectureHeader()
	{
		$lecture = Database::queryFirst('SELECT o.userid AS ouserid, o.firstname AS ofirstname, o.lastname AS olastname,'
			. ' u.userid AS uuserid, u.firstname AS ufirstname, u.lastname AS ulastname,'
			. ' l.displayname, l.description, l.createtime, l.updatetime,'
			. ' img.displayname AS imgname, img.imagebaseid'
			. ' FROM sat.lecture l'
			. ' LEFT JOIN sat.user o ON (l.ownerid = o.userid)'
			. ' LEFT JOIN sat.user u ON (l.updaterid = u.userid)'
			. ' LEFT JOIN sat.imageversion ver ON (ver.imageversionid = l.imageversionid)'
			. ' LEFT JOIN sat.imagebase img ON (img.imagebaseid = ver.imagebaseid)'
			. ' WHERE l.lectureid = :uuid'
			. ' LIMIT 1', array('uuid' => self::$uuid));
		if ($lecture !== false) {
			// Mangle date and render
			$lecture['createtime_s'] = date('d.m.Y H:i', $lecture['createtime']);
			$lecture['updatetime_s'] = date('d.m.Y H:i', $lecture['updatetime']);
			$lecture['descriptionHtml'] = nl2br(htmlspecialchars($lecture['description']));
			Render::addTemplate('actionlog-lecture', $lecture);
		}
		return $lecture !== false;
	}

	private static function generateLog($query, $params, $showActor, $showTarget)
	{
		// query action log
		$res = Database::simpleQuery($query, $params);
		$events = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['dateline_s'] = date('d.m.Y H:i', $row['dateline']);
			if (isset($row['imgname'])) {
				$row['targeturl'] = '?do=dozmod&section=actionlog&action=showtarget&uuid=' . $row['targetid'];
				$row['targetname'] = $row['imgname'];
			} elseif (isset($row['tlastname'])) {
				$row['targeturl'] = '?do=dozmod&section=actionlog&action=showuser&uuid=' . $row['targetid'];
				$row['targetname'] = $row['tlastname'] . ', ' . $row['tfirstname'];
			} elseif (isset($row['lecturename'])) {
				$row['targeturl'] = '?do=dozmod&section=actionlog&action=showtarget&uuid=' . $row['targetid'];
				$row['targetname'] = $row['lecturename'];
			}
			$events[] = $row;
		}
		$data = array('events' => $events);
		if ($showActor) {
			$data['showActor'] = true;
		}
		if ($showTarget) {
			$data['showTarget'] = true;
		}

		Render::addTemplate('actionlog-log', $data);
	}

	public static function doAjax()
	{

	}

}