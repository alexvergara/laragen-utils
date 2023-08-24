<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected $references = [];

    public function generate()
    {
        $tables = [];
        foreach (\DB::table('schema_tables')->where('project', request('project'))->where('active', 1)->orderBy('name')->get() as $table) {
            $columns = \DB::table('schema_columns')->where('table_id', $table->id)->get();
            $tables[$table->name] = $columns;

            $schema = [ $this->generateColumn($table->name, 'id', 'id') ];
            foreach ($columns as $column) {
                $schema[] = $this->generateColumn($table->name, $column->name, $column->type, $column->type_details, $column->options);
            }
            $schema[] = $this->generateColumn($table->name, 'created_at', 'timestamp');
            $schema[] = $this->generateColumn($table->name, 'updated_at', 'timestamp');

            \Storage::disk('public')->put($table->name . '.json', json_encode($schema, JSON_PRETTY_PRINT));
        }

        $this->generateReferences();

        //return response()->json($this->references);
        return response()->json($tables);
    }

    public function generateReferences()
    {
        if (count($this->references) && !request()->has('no-references')) {
            $migration_stub = file_get_contents(base_path() . '/resources/files/migration.stub');

            foreach ($this->references as $table_name => $references) {
                $model = \Str::plural($table_name);
                $table = \Str::snake($model);
                $migration_up = "Schema::table('${table}', function (Blueprint \$table) {";
                $migration_down = "Schema::table('${table}', function (Blueprint \$table) {";
                foreach ($references as $reference) {
                    $name = $reference['name'];
                    $referenced = (strpos($reference['options'], 'users') !== false) ? 'users' : $reference['referenced'];
                    $migration_up .= PHP_EOL . "            \$table->foreign('${name}')->references('id')->on('${referenced}')->onDelete('cascade);";
                    $migration_down .= PHP_EOL . "            \$table->dropForeign(['${name}']);";
                }
                $migration_up .= PHP_EOL . "        });";
                $migration_down .= PHP_EOL . "        });";

                $migration_file = str_replace([ 'XXX-CLASS-XXX', 'XXXX-UP-XXXX', 'XXXX-DOWN-XXXX' ], [ "AddReferencesTo${model}Table", $migration_up, $migration_down ], $migration_stub);

                \Storage::disk('public')->put("/migrations/references/2023_01_01_000000_add_references_to_${table}_table.php", $migration_file);
            }
        }
    }

    public function isHidden($name, $type)
    {
        return in_array($name, [ 'id', 'created_at', 'updated_at', 'deleted_at' ]); // || $type === 'id';
    }

    public function generateColumn($table_name, $name, $type, $type_details = '', $options = '')
    {
        $referenced = $name !== 'id' && $type === 'id' ? \Str::plural(str_replace('_id', '', $name)) : '';

        return [
            'name' => $name,
            'dbType' => $this->getDbType($table_name, $name, $type, $type_details, $options, $referenced),
            'htmlType' => $this->isHidden($name, $type) ? '' : $this->getHtmlType($type, $referenced),
            'validations' => $this->isHidden($name, $type) ? '' : $this->getValidations($name, $type, $type_details, $options, $referenced),
            //'relation' => $this->getRelation($name, $type),
            'searchable' => $this->getOthers($name, $type),
            'fillable' => $this->getOthers($name, $type),
            'primary' => $this->getOthers($name, $type, true),
            'inForm' => $this->getOthers($name, $type),
            'inIndex' => $this->getOthers($name, $type),
            'inView' => $this->getOthers($name, $type),
        ];
    }

    public function getDbType($table_name, $name, $type, $type_details = '', $options = '', $referenced = '')
    {
        $dbType = $type;
        $nullable = strpos($options, 'nullable') !== false ? ':nullable' : '';
        if ($name !== 'id' && $type === 'id') {
            $this->references[$table_name] = [ ...($this->references[$table_name] ?? []), [ 'name' => $name, 'referenced' => $referenced, 'options' => $options ] ];

            return 'foreignId' . $nullable; // 'integer'; // 'foreignId';
        }
        //if ($name !== 'id' && $type === 'select') return 'string' . $nullable; // 'foreignId';
        //if ($name !== 'id' && $type === 'id') return "integer:unsigned:foreign,${referenced},id"  . $nullable;

        return $dbType . ($type_details ? ',' . $type_details : '')  . $nullable;
    }

    public function getRelation($name, $type)
    {
        if ($name !== 'id' && $type === 'id') {
            return 'mt1:' . ucfirst(str_replace('_id', '', $name)) . ",${name},id";
        }

        return '';
    }

    public function getHtmlType($type, $referenced = '')
    {
        switch ($type) {
            case 'id':
                //return "select:$${referenced}"; // Does not work on v8
                return "select";
            case 'string':
                return 'text';
            case 'text':
                return 'textarea';
            case 'integer':
            case 'double':
            case 'float':
                return 'number';
            case 'boolean':
                return 'checkbox';
            case 'date':
            case 'datetime':
            case 'timestamp':
                return 'date';
            case 'select':
                return "select";
            default:
                return '';
        }
    }

    public function getValidations($name, $type, $type_details = '', $options = '', $referenced = '')
    {
        $referenced = (strpos($options, 'users') !== false) ? 'users' : $referenced;
        $validations = [ strpos($options, 'nullable') !== false ? 'nullable' : 'required' ];
        $real_options = str_replace(['users', 'nullable'], '', $options);
        if ($real_options) $validations = array_filter(explode('|', $real_options));

        $type_details = explode(',', $type_details);

        switch ($type) {
            case 'string':
                $validations[] = 'min:3';
                if (isset($type_details[0])) $validations[] = 'max:' . $type_details[0];
                break;
            case 'float':
            case 'double':
                $int = isset($type_details[0]) ? intval($type_details[0]) : 8;
                $dec = isset($type_details[1]) ? intval($type_details[1]) : 0;

                $mask = [ str_repeat('9', $int - $dec - 1), str_repeat('9', $dec), ];

                $validations[] = 'min:0';
                $validations[] = 'max:' . implode('.', $mask);
                break;
            case 'date':
            case 'datetime':
            case 'timestamp':
                $validations[] = 'date';
                break;
        }

        if ($type === 'id') {
            $validations[] = "exists:${referenced},id";
        }

        return implode('|', $validations);
    }

    public function getOthers($name, $type, $primary = false)
    {
        $hidden = $this->isHidden($name, $type);

        if ($primary) return $name === 'id' ? true : false;

        return $hidden ? false : true;
    }
}
