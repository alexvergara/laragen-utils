<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function generate()
    {
        $tables = [];
        foreach (\DB::table('schema_tables')->orderBy('name')->get() as $table) {
            $columns = \DB::table('schema_columns')->where('table_id', $table->id)->get();
            $tables[$table->name] = $columns;

            $schema = [ $this->generateColumn('id', 'id') ];
            foreach ($columns as $column) {
                $schema[] = $this->generateColumn($column->name, $column->type, $column->type_details, $column->options);
            }
            $schema[] = $this->generateColumn('created_at', 'timestamp');
            $schema[] = $this->generateColumn('updated_at', 'timestamp');

            //$schema = json_encode($schema, JSON_PRETTY_PRINT);

            \Storage::disk('public')->put($table->name . '.json', json_encode($schema, JSON_PRETTY_PRINT));

            //return response()->json($schema);
        }

        return response()->json($tables);
    }

    public function isHidden($name, $type)
    {        
        return in_array($name, [ 'id', 'created_at', 'updated_at', 'deleted_at' ]); // || $type === 'id';
    }

    public function generateColumn($name, $type, $type_details = '', $options = '')
    {
        $plural = $name !== 'id' && $type === 'id' ? \Str::plural(str_replace('_id', '', $name)) : '';

        return [
            'name' => $name,
            'dbType' => $this->getDbType($name, $type, $type_details, $plural),
            'htmlType' => $this->isHidden($name, $type) ? '' : $this->getHtmlType($type, $plural),
            'validations' => $this->isHidden($name, $type) ? '' : $this->getValidations($name, $type, $type_details, $options, $plural),
            //'relation' => $this->getRelation($name, $type),
            'searchable' => $this->getOthers($name, $type),
            'fillable' => $this->getOthers($name, $type),
            'primary' => $this->getOthers($name, $type, true),
            'inForm' => $this->getOthers($name, $type),
            'inIndex' => $this->getOthers($name, $type),
            'inView' => $this->getOthers($name, $type),
        ];
    }

    public function getDbType($name, $type, $type_details = '', $plural = '')
    {
        //if ($name !== 'id' && $type === 'id') return 'integer'; // 'foreignId';
        //if ($name !== 'id' && $type === 'select') return 'string'; // 'foreignId';
        if ($name !== 'id' && $type === 'id') return "integer:unsigned:foreign,${plural},id";

        return $type . ($type_details ? ',' . $type_details : '');
    }

    public function getRelation($name, $type)
    {
        if ($name !== 'id' && $type === 'id') {
            return 'mt1:' . ucfirst(str_replace('_id', '', $name)) . ",${name},id";
        }

        return '';
    }

    public function getHtmlType($type, $plural = '')
    {
        switch ($type) {
            case 'id':
                //return "select:$${plural}"; // Does not work on v8
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

    public function getValidations($name, $type, $type_details = '', $options = '', $plural = '')
    {
        $validations = [ strpos($options, 'nullable') !== false ? 'nullable' : 'required' ];
        $options = str_replace('nullable', '', $options);
        if ($options) $validations[] = $options;

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
                $validations[] = 'date';
                break;
        }

        if ($type === 'id') {
            $validations[] = "exists:${plural},id";
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
