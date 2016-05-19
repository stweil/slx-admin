<?php

class Page_BaseConfig_Partitions_CDN extends Page
{

	protected function doPreprocess()
	{
		User::load();

		$action = Request::post('action');

		if($action == 'new_partition') {
			$this->addPartition();
		}
		if($action == 'reset') {
			$this->resetConfig();
		}

		$deletePartition = Request::get('deletePartition');
		if($deletePartition !== false) { // TODO: CSRF: Actions that change/update/delete anything should be POST
			$this->deletePartition($deletePartition);
		}

		$this->updatePartitions();
	}

	protected function doRender()
	{
		if (!User::hasPermission('baseconfig_local')) {
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
			'user' => User::getId()
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
			if(substr($key,0,9) == 'partition'){
				$id = substr($key,10,1);
				$type = substr($key,12);
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
	}
}