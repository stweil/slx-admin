<?php

class Page_iPxe extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		if (isset($_POST['action'])) {
			if ($_POST['action'] === 'compile') {
				if (!Util::verifyToken()) {
					Util::redirect('?do=Main');
				}
			}
		}
	}

	protected function doRender()
	{
		$ips = array();
		$current = CONFIG_IPXE_DIR . '/last-ip';
		if (file_exists($current)) $current = file_get_contents($current);
		exec('/bin/ip a', $retval);
		foreach ($retval as $ip) {
			if (preg_match('#inet (\d+\.\d+\.\d+\.\d+)/\d+.*scope#', $ip, $out) && $out[1] !== '127.0.0.1') {
				$ips[] = array(
					'ip'       => $out[1],
					'current'  => ($out[1] == $current)
				);
			}
		}
		Render::addTemplate('page-ipxe', array('ips' => $ips, 'token' => Session::get('token')));
	}
}
