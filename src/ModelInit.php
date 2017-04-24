<?php
/**
 * Created by IntelliJ IDEA.
 * User: rek
 * Date: 2017/4/24
 * Time: PM2:03
 */

namespace x2ts\utils;

use Exception;
use ReflectionClass;
use x2ts\ComponentFactory as X;
use x2ts\db\orm\HasManyRelation;
use x2ts\db\orm\ManyManyRelation;
use x2ts\db\orm\Model;
use x2ts\db\orm\MySQLTableSchema;
use x2ts\Toolkit;


class ModelInit {
    const READ = 1;

    const WRITE = 2;

    public static function run() {
        $mc = new ModelInit();
        $rfc = new ReflectionClass(Model::class);
        /** @var array $xComps */
        $xComps = X::conf('component');
        foreach ($xComps as $cid => $xComp) {
            if ($xComp['class'] === Model::class) {
                $conf = $rfc->getStaticPropertyValue('_conf');
                Toolkit::override($conf, Model::$_conf);
                Toolkit::override($conf, $xComp['conf']);
                $mc->scanTables($conf['dbId'], $conf['namespace']);
            }
        }
    }

    private function scanTables($dbId = 'db', $namespace = 'model') {
        $dbName = X::$dbId()->getDbName();
        /** @var array $tables */
        $tables = X::$dbId()->query(
            <<<SQL
SELECT
  TABLE_NAME, COUNT(*) C
FROM
  information_schema.KEY_COLUMN_USAGE
WHERE
  TABLE_SCHEMA = :s AND CONSTRAINT_NAME = 'PRIMARY'
GROUP BY TABLE_NAME HAVING C=1;
SQL
            ,
            [':s' => $dbName]
        );
        echo "Scanning $dbName...\n";
        foreach ($tables as $table) {
            echo 'Processing ', $table['TABLE_NAME'], '...';
            $this->createModelClass($table['TABLE_NAME'], $dbId, $namespace);
            echo "OK\n";
        }
        echo "done\n";
    }

    /**
     * @param $tableName
     * @param $dbId
     * @param $namespace
     *
     * @throws Exception
     */
    private function createModelClass($tableName, $dbId, $namespace) {
        /** @var MySQLTableSchema $tableSchema */
        $tableSchema = X::getInstance(
            MySQLTableSchema::class,
            [$tableName, X::$dbId()],
            [],
            Toolkit::randomChars(10)
        );
        $phpDoc = $this->createDocComment($tableSchema);

        $namespacePath = str_replace('\\', DIRECTORY_SEPARATOR, $namespace);
        $modelName = Toolkit::toCamelCase($tableSchema->name, true);
        $modelFile = X_PROJECT_ROOT . "/protected/{$namespacePath}/{$modelName}.php";
        if (is_readable($modelFile)) {
            $phpCode = file_get_contents($modelFile);
            $newPhpCode = preg_replace_callback(
                '#(/\*\*[^/]*\*/)(\s+class\s+)#m',
                function ($m) use ($phpDoc) {
                    return $phpDoc . $m[2];
                },
                $phpCode
            );
        } else {
            $date = date('Y/m/d');
            $time = date('H:i:s');
            $newPhpCode = <<<PHP
<?php
/**
 * Created by x2ts model generator.
 * Date: {$date}
 * Time: {$time}
 */

namespace {$namespace};


use x2ts\db\orm\Model;

{$phpDoc}
class {$modelName} extends Model {}
PHP;
        }
        $dir = dirname($modelFile);
        if (!@mkdir($dir, 0755, true) !== false && !is_dir($dir)) {
            throw new Exception('Cannot create directory');
        }
        file_put_contents($modelFile, $newPhpCode);
    }

    private function createDocComment(MySQLTableSchema $tableSchema): string {
        $properties = [];
        $methods = [];
        $columns = $tableSchema->getColumns();
        $relations = $tableSchema->getRelations();

        foreach ($relations as $name => $relation) {
            $type =
                $relation instanceof HasManyRelation ||
                $relation instanceof ManyManyRelation ?
                    ($relation->foreignModelName . '[]') : $relation->foreignModelName;
            $properties[$name] = [
                'type'     => $type,
                'access'   => self::READ,
                'category' => 'relations',
                'position' => $name,
            ];
        }

        foreach ($columns as $column) {
            $properties[$column->name] = [
                'type'     => $column->phpType,
                'access'   => self::READ | self::WRITE,
                'category' => 'columns',
                'position' => str_pad($column->position, 4, '0', STR_PAD_LEFT),
            ];
        }

        foreach ($relations as $name => $relation) {
            if (
                $relation instanceof HasManyRelation ||
                $relation instanceof ManyManyRelation
            ) {
                $methods[$name] = [
                    'type'   => $relation->foreignModelName . '[]',
                    'params' => '$condition = \'\', $params = [], $offset = null, $limit = null',
                ];
            }
        }

        $modelName = Toolkit::toCamelCase($tableSchema->name, true);
        $class = '\\model\\' . $modelName;
        if (!class_exists($class)) {
            goto setup_result;
        }

        $rfc = new ReflectionClass($class);
        $allMethods = $rfc->getMethods();
        foreach ($allMethods as $method) {
            if ($method->getDeclaringClass()->getName() !== $rfc->getName()) {
                continue;
            }
            $snakeMethodName = Toolkit::to_snake_case($method->getName());
            $propName = substr($snakeMethodName, 4);
            if (
                strpos($snakeMethodName, 'get_') === 0 &&
                $method->isStatic() === false &&
                $method->getNumberOfParameters() === 0
            ) {
                $type = (string) $method->getReturnType();
                if (array_key_exists($propName, $properties)) {
                    $properties[$propName]['type'] = $type ?? $properties[$propName]['type'];
                    $properties[$propName]['access'] |= self::READ;
                } else {
                    $properties[$propName] = [
                        'type'   => $type,
                        'access' => self::READ,
                    ];
                }
                $properties[$propName]['category'] = 'getters';
                $properties[$propName]['position'] = $propName;
            }
            if (
                strpos($snakeMethodName, 'set_') === 0 &&
                $method->isStatic() === false &&
                $method->getNumberOfParameters() === 1
            ) {
                $type = (string) $method->getParameters()[0]->getType();
                if (array_key_exists($propName, $properties)) {
                    $properties[$propName]['type'] = $properties[$propName]['type'] ?? $type;
                    $properties[$propName]['access'] |= self::WRITE;
                } else {
                    $properties[$propName] = [
                        'type'   => $type,
                        'access' => self::WRITE,
                    ];
                }
                $properties[$propName]['category'] = 'getters';
                $properties[$propName]['position'] = $propName;
            }
        }

        uksort($properties, function ($a, $b) use ($properties) {
            $l = ['getters' => 0, 'columns' => 10, 'relations' => 20, 'methods' => 30];
            $r = $l[$properties[$a]['category']] - $l[$properties[$b]['category']];
            if ($r === 0) {
                $r = strcmp($properties[$a]['position'], $properties[$b]['position']);
            }
            return $r;
        });


        setup_result:
        $result = "/**\n * Class {$modelName}\n *\n * @package model\n";
        $lines = [];
        $paddingLength = 0;
        foreach ($properties as $name => $property) {

            $property['access'] &= 3;
            $annotation = 'something error';
            if ($property['access'] === 3) {
                $annotation = '@property';
            } else if ($property['access'] === self::READ) {
                $annotation = '@property-read';
            } else if ($property['access'] === self::WRITE) {
                $annotation = '@property-write';
            }
            $line = [
                'category'   => $property['category'],
                'annotation' => " * $annotation {$property['type']}",
                'name'       => "\${$name}\n",
            ];
            $paddingLength = max($paddingLength, strlen($line['annotation']));
            $lines[] = $line;
        }
        $paddingLength++;
        $lastCategory = '';
        foreach ($lines as $i => $line) {
            if ($line['category'] !== $lastCategory) {
                $lastCategory = $line['category'];
                $result .= " *\n * $lastCategory\n";
            }
            $result .= str_pad(
                    $line['annotation'],
                    $paddingLength,
                    ' ',
                    STR_PAD_RIGHT
                ) . $line['name'];
        }
        if (count($methods)) {
            $result .= " *\n * methods\n";
            foreach ($methods as $name => $method) {
                $result .= " * @method {$method['type']} {$name}({$method['params']})\n";
            }
        }

        $result .= ' */';
        return $result;
    }
}
