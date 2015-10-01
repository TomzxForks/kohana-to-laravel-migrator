<?php

namespace Migrator\Transform;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Model extends Transform
{
	public function transform()
	{
		$this->migrateModels();
	}

	private function migrateModels()
	{
		$finder = (new Finder)
			->files()
			->in($this->kohanaPath.'/application/classes/model');

		/** @var \Symfony\Component\Finder\SplFileInfo $file */
		foreach ($finder as $file) {
			$this->migrateModel($file);
		}
	}

	private function migrateModel(SplFileInfo $file)
	{
		$content = $file->getContents();

		$isModelOrm = strpos($content, 'extends ORM') !== false;

		if ($isModelOrm) {
			$this->migrateModelOrm($file);
		} else {
			$this->migrateModelGeneric($file);
		}
	}

	private function migrateModelGeneric(SplFileInfo $file)
	{
		$targetPath = $this->laravelPath.'/app/models/'. $file->getFilename();

		//file_put_contents($targetPath, $file->getContents());
	}

	private function migrateModelOrm(SplFileInfo $file)
	{
		$content = $file->getContents();

		// Change class signature
		if ( ! preg_match('/\$_table_name = \'(.+?)\';/', $content, $match)) {
			$this->migrateModelGeneric($file);
			return;
		}

		$tableName = $match[1];
		$className = Str::studly($tableName);

		$content = preg_replace('/class\s(\S+)\sextends\s+ORM/i', 'class '.$className.' extends \\Eloquent', $content);

		$content = strtr($content, [
			'<?php defined(\'SYSPATH\') or die(\'No direct script access.\');' => '<?php',
			'$_table_name'  => '$table',
			'$_primary_key' => '$primaryKey',
			'$_safe_attributes' => '$fillable',
			'->loaded()' => '->exists',
			'->find()' => '->first()',
			'->find_all()' => '->all()',
			'->pk()' => '->getKey()',
			'->execute()' => '->get()',
			'order_by' => 'orderBy',
			'group_by' => 'groupBy',
			'DB::expr' => 'DB::raw',
			'as_array()' => 'toArray()',
			'assemble(' => 'map(',
			'protected $_created_on' => 'const CREATED_AT',
			'protected $_updated_on' => 'const UPDATED_AT',
			'Model_' => '',
		]);

		$content = preg_replace_callback('/ORM::factory\([\'"](\w+)[\'"]\)/i', function ($matches) {
			return '(new ' . $matches[1] . ')';
		}, $content);

		// Update rules
		// Update relations

		$content = $this->convertRelations($content);

		// Save new model
		$targetPath = $this->laravelPath.'/app/models/'. $className .'.php';

		$this->write($targetPath, $content);
	}

	protected function convertRelations($content)
	{
		$relations = [
			'has_one' => 'hasOne',
			'has_many' => 'hasMany',
			'belongs_to' => 'belongsTo',
		];
		$prefixLength = strlen('	protected ');
		foreach ($relations as $relation => $relationFunction) {
			$begin = strpos($content, '$_'.$relation);
			$end = strpos($content, ');', $begin);

			if ($begin !== false && $end !== false) {
				$length = $end-$begin+2;
				$declaration = substr($content, $begin, $length);
				eval('$_relations = '.$declaration);

				$newContent = [];
				foreach ($_relations as $relationName => $relationProperties) {
					$newContent[] = <<<EOF
	function $relationName()
	{
		return \$this->{$relationFunction}('{$relationProperties['model']}');
	}
EOF;
				}
				$content = substr_replace($content, implode(PHP_EOL.PHP_EOL, $newContent), $begin-$prefixLength, $length+$prefixLength);
			}
		}

		return $content;
	}
}
