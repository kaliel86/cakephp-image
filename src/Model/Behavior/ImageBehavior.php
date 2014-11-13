<?php
namespace Image\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\Event\Event;
use Cake\Filesystem\Folder;
use Cake\Filesystem\File;
use Cake\ORM\TableRegistry;
use Cake\ORM\Query;
use Cake\Collection\Collection;
use WideImage\WideImage;
use ArrayObject;

class ImageBehavior extends Behavior {

/**
 * [$_imagesTable description]
 * @var [type]
 */
	protected $_imagesTable;

/**
 * [$_defaultConfig description]
 * @var [type]
 */
	public $_defaultConfig = [
		//'implementedFinders' => ['images' => 'findImages'],
		'fields' => [],
		'presets' => [],
		'path' => null,
		'table' => 'images'
	];

/**
 * [initialize description]
 * @param  array  $config [description]
 * @return [type]         [description]
 */
	public function initialize(array $config) {
		$this->_imagesTable = TableRegistry::get($this->config('table'));

		$this->setupAssociations(
			$this->_config['table'],
			$this->_config['fields']
		);
	}

/**
 * [setupAssociations description]
 * @param  [type] $table  [description]
 * @param  [type] $fields [description]
 * @return [type]         [description]
 */
	protected function setupAssociations($table, $fields) {
		$alias = $this->_table->alias();

		foreach ($fields as $field => $type) {
			$assocType = $type == 'many' ? 'hasMany' : 'hasOne';
			$name = $this->fieldName($field);
			$target = TableRegistry::get($name);
			$target->table($table);

			$this->_table->{$assocType}($name, [
				'targetTable' => $target,
				'foreignKey' => 'foreign_key',
				'joinType' => 'LEFT',
				'propertyName' => $this->fieldName($field, false),
				'conditions' => [
					$name . '.model' => $alias,
					$name . '.field' => $field,
				]
			]);
		}

		$this->_table->hasMany($table, [
			'foreignKey' => 'foreign_key',
			'strategy' => 'subquery',
			'propertyName' => '_images',
			'dependent' => true,
			'conditions' => [
				$table .'.model' => $alias
			]
		]);
	}

/**
 * [beforeFind description]
 * @param  Event  $event [description]
 * @param  Query  $query [description]
 * @return [type]        [description]
 */
	public function beforeFind(Event $event, Query $query) {
		$fields = $this->config('fields');
		$alias = $this->_table->alias();
		$contain = $conditions = [];

		foreach ($fields as $field => $type) {
			$field = $this->fieldName($field);
			$contain[$field] = $conditions;
		}

		return $query
			->contain($contain)
			->formatResults(function($results) {
				return $this->_mapResults($results);
			}, $query::PREPEND);
	}

/**
 * [_upload description]
 * @param  Entity  $entity    [description]
 * @param  string  $fieldName [description]
 * @param  string  $fileName  [description]
 * @param  string  $filePath  [description]
 * @param  boolean $copy      [description]
 * @return \Cake\ORM\Entity             [description]
 */
	protected function _upload(Entity $entity, $fieldName, $fileName, $filePath, $copy = false) {
		$data = [];

		if (!file_exists($filePath)) {
			return $data;
		}

		$alias = $this->_table->alias();
		$basePath = $this->config('path') . DS . $alias;
		$pathinfo = pathinfo($fileName);
		$fileName = md5_file($filePath) .'.'. $pathinfo['extension'];
		$fullPath = $basePath . DS . $fileName;
		$folder = new Folder($basePath, true, 0777);
		$transferFn = $copy ? 'copy' : 'move_uploaded_file';
		$existing = file_exists($fullPath);

		if ($existing || call_user_func_array($transferFn, [ $filePath, $fullPath ])) {
			$file = new File($fullPath);
			$data = [
				'model' => $alias,
				'field' => $fieldName,
				'filename' => $fileName,
				'size' => $file->size(),
				'mime' => $file->mime()
			];
		}

		return $data;
	}

/**
 * Generate all presets for given image entity
 * @param  [type] $image [description]
 * @return [type]        [description]
 */
	public function generatePresets($image) {
		$basePath = $this->config('path') . DS . $image->model . DS;
		$imagePath = $basePath . $image->filename;

		foreach($this->config('presets') as $preset => $options) {
			$wImage = WideImage::load($imagePath);
			$destination = $basePath . $preset .'_'. $image->filename;

			foreach ($options as $action => $actionOptions) {
				$wImage = call_user_func_array([ $wImage, $action ], $actionOptions);
			}

			$wImage->saveToFile($destination);
		}

		return true;
	}

/**
 * [fieldName description]
 * @param  [type]  $field        [description]
 * @param  boolean $includeAlias [description]
 * @return [type]                [description]
 */
	protected function fieldName($field, $includeAlias = true) {
		$alias = $this->_table->alias();
		$name = $field . '_image';

		if ($includeAlias) {
			$name = $alias . '_' . $name;
		}

		return $name;
	}

/**
 * [_mapResults description]
 * @param  [type] $results [description]
 * @return [type]          [description]
 */
	protected function _mapResults($results) {
		$fields = $this->config('fields');

		return $results->map(function ($row) use($fields) {

			foreach ($fields as $field => $type) {
				$name = $this->fieldName($field, false);
				$image = isset($row[$name]) ? $row[$name] : null;

				if ($image === null) {
					unset($row[$name]);
					continue;
				}

				$row[$field] = $image;

				unset($row[$name]);
			}
			$row->clean();

			return $row;
		});
	}

/**
 * [beforeSave description]
 * @param  Event       $event   [description]
 * @param  Entity      $entity  [description]
 * @param  ArrayObject $options [description]
 * @return [type]               [description]
 */
	public function beforeSave(Event $event, Entity $entity, ArrayObject $options) {
		$fields = $this->config('fields');
		$alias = $this->_table->alias();

		foreach ($fields as $fieldName => $fieldType) {
			$uploadedImages = $entities = [];
			$field = $entity->{$fieldName};
			$field = $fieldType == 'one' ? [ $field ] : $field;

			foreach ($field as $image) {
				if (isset($image['tmp_name'])) { // server based file uploads
					$uploadedImages[] = $this->_upload($entity, $fieldName, $image['name'], $image['tmp_name'], false);
				} else { // any other 'path' based uploads
					$uploadedImages[] = $this->_upload($entity, $fieldName, $image, $image, true);
				}
			}

			$uploadedImages = array_filter($uploadedImages);

			if (!empty($uploadedImages)) {
				$preexisting = $this->_imagesTable->find()
					->where(['model' => $alias, 'field' => $fieldName, 'foreign_key' => $entity->id ])
					->bufferResults(false);

				foreach ($preexisting as $index => $image) {
					if (isset($uploadedImages[$index])) {
						$entities[$index] = $this->_imagesTable->patchEntity($image, $uploadedImages[$index]);
					} else if ($fieldType == 'one') {
						$this->_imagesTable->delete($image);
					}
				}

				$new = array_diff_key($uploadedImages, $entities);
				foreach ($new as $image) {
					$entities[] = $this->_imagesTable->newEntity($image);
				}
			}

			$entity->set('_images', $entities);
			$entity->dirty($fieldName, false);
		}
	}

/**
 * [afterSave description]
 * @param  Event       $event   [description]
 * @param  Entity      $entity  [description]
 * @param  ArrayObject $options [description]
 * @return [type]               [description]
 */
	public function afterSave(Event $event, Entity $entity, ArrayObject $options) {
		foreach ($entity->_images as $imageEntity) {
			$this->generatePresets($imageEntity);
		}

		$entity->unsetProperty('_images');
	}

/**
 * Return Images table object attached to current table
 * @return Cake\ORM\Table Images table object
 */
	public function imagesTable() {
		return $this->_imagesTable;
	}
}