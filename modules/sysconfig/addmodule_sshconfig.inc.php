<?php

/*
 * Wizard for configuring the sshd (client side).
 */

class SshConfig_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		if ($this->edit !== false) {
			$data = $this->edit->getData(false) + array(
				'title' => $this->edit->title(),
				'edit' => $this->edit->id(),
				'apl' => $this->edit->getData('allowPasswordLogin') === 'yes'
			);
		} else {
			$data = array();
		}
		Render::addDialog(Dictionary::translate('lang_clientSshConfig'), false, 'sysconfig/sshconfig-start', $data + array(
			'step' => 'SshConfig_Finish',
		));
	}

}

class SshConfig_Finish extends AddModule_Base
{
	
	protected function preprocessInternal()
	{
		$title = Request::post('title');
		if (empty($title)) {
			Message::addError('missing-title');
			return;
		}
		// Seems ok, create entry
		if ($this->edit === false)
			$module = ConfigModule::getInstance('SshConfig');
		else
			$module = $this->edit;
		if ($module === false) {
			Message::addError('error-read', 'sshconfig.inc.php');
			Util::redirect('?do=SysConfig&action=addmodule&step=SshConfig_Start');
		}
		$module->setData('allowPasswordLogin', Request::post('allowPasswordLogin') === 'yes');
		if (!$module->setData('listenPort', Request::post('listenPort'))) {
			Message::addError('value-invalid', 'port', Request::post('listenPort'));
			Util::redirect('?do=SysConfig&action=addmodule&step=SshConfig_Start');
		}
		if (!$module->setData('publicKey', Request::post('publicKey'))) {
			Message::addError('value-invalid', 'pubkey', Request::post('publicKey'));
			Util::redirect('?do=SysConfig&action=addmodule&step=SshConfig_Start');
		}
		if ($this->edit !== false)
			$ret = $module->update($title);
		else
			$ret = $module->insert($title);
		if (!$ret)
			Util::redirect('?do=SysConfig&action=addmodule&step=SshConfig_Start');
		elseif (!$module->generate($this->edit === false, NULL, 200))
			Util::redirect('?do=SysConfig&action=addmodule&step=SshConfig_Start');
		// Yay
		if ($this->edit !== false)
			Message::addSuccess('module-edited');
		else
			Message::addSuccess('module-added');
		Util::redirect('?do=SysConfig');
	}

}
