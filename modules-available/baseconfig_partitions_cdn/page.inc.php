<?php

class Page_BaseConfig_Partitions_CDN extends Page
{

	protected function doPreprocess()
	{
		User::load();

		$action = Request::post('action');

		if($action == 'new_partition') {
			if (User::hasPermission("partitions.add")) {
				$this->addPartition();
			}
		}
		if($action == 'reset') {
			if (User::hasPermission("partitions.reset")) {
				$this->resetConfig();
			}
		}

		$deletePartition = Request::get('deletePartition');
		if($deletePartition !== false) { // TODO: CSRF: Actions that change/update/delete anything should be POST
			if (User::hasPermission("partitions.delete")) {
				$this->deletePartition($deletePartition);
			}
		}

		if(User::hasPermission("partitions.edit")) {
			$this->updatePartitions();
		}
	}

	protected function doRender()
	{
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$hasAnyRight = User::hasPermission("partitions.add") || User::hasPermission("partitions.delete")
						|| User::hasPermission("partitions.edit") || User::hasPermission("partitions.reset");

		if (!(User::hasPermission("show") || $hasAnyRight)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		//loads partition settings
		$partitions = array();
		$res = Database::simpleQuery('SELECT id, partition_id, size, mount_point, options FROM setting_partition WHERE user=:user',
			array( 'user' => User::getId() ));
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$partition = array(
				'id' => $row['id'],
				'partition_id' => $row['partition_id'],
				'size' => $row['size'],
				'mount_point' => $row['mount_point'],
				'options' => $row['options']
			);
			$partitions[] = $partition;
		}

		Render::addTemplate('_page', array(
			'partitions' => $partitions,
			'user' => User::getId(),
			'allowedToAdd' => User::hasPermission("partitions.add"),
			'allowedToDelete' => User::hasPermission("partitions.delete"),
			'allowedToEdit' => User::hasPermission("partitions.edit"),
			'allowedToReset' => User::hasPermission("partitions.reset")
		));
	}

	private function addPartition() {
		$partId = Request::post('new-partition-id');
		$partSize = Request::post('new-partition-size');
		$partMountPoint = Request::post('new-partition-mount-point');
		$partOptions = Request::post('new-partition-options');

		if(strlen($partId) < 1 || strlen($partSize) < 1){
			Message::addError('main.empty-field');
		}else{
			$data = array(
				'partition_id' => $partId,
				'size' => $partSize,
				'mount_point' => $partMountPoint,
				'options' => $partOptions,
				'user' => User::getId()
			);
			if (Database::exec('INSERT INTO setting_partition SET partition_id = :partition_id, size = :size,
				mount_point = :mount_point, options = :options, user = :user ', $data) != 1) {
				Util::traceError('Could not create new partition in DB');
			}
		}
		Util::redirect('?do=BaseConfig_Partitions_CDN');
	}

	private function deletePartition($id){
		if(is_numeric($id)){
			$data = array(
				'id' => $id,
				'user' => User::getId()
			);
			if (Database::exec('DELETE FROM setting_partition WHERE id = :id AND user = :user', $data) != 1) {
				Util::traceError('Could not delete partition in DB');
			}
		}
		Util::redirect('?do=BaseConfig_Partitions_CDN');
	}

	private function updatePartitions(){
		$partitions = array();
		foreach($_POST as $key => $value){

			if (substr($key, 0, 9) == 'partition') {
				list($key, $id, $type) = explode("-", $key);
				$partitions[$id][$type] = $value;
			}
		}

		foreach($partitions as $key => $data){
			$data = array(
				'id' => $key,
				'partition_id' => $data['partition_id'],
				'size' => $data['size'],
				'mount_point' => $data['mount_point'],
				'options' => $data['options'],
				'user' => User::getId()
			);
			Database::exec('UPDATE setting_partition SET partition_id=:partition_id, size=:size, mount_point=:mount_point,
					options=:options  WHERE id=:id AND user=:user;', $data);
		}


		if (!empty($partitions)) {
			Message::addSuccess('partitions-updated');
			Util::redirect('?do=BaseConfig_Partitions_CDN');
		}
	}

	private function resetConfig(){
		$data = array(
			'user' => User::getId()
		);
		//Delete all config values
		Database::exec('DELETE FROM setting_partition WHERE user = :user', $data);
		//Create default partition values
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '44', size = '5G', mount_point = '/tmp', user = :user", $data );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '43', size = '20G', mount_point = '/boot', options = 'bootable', user = :user", $data );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '40', size = '20G', mount_point = '/cache/export/dnbd3', user = :user", $data );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '41', size = '5G', mount_point = '/home', user = :user", $data );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '82', size = '1G', user = :user", $data );
		Util::redirect('?do=BaseConfig_Partitions_CDN');
	}
}