<?php
/**
 * Copyright (c) NMS PRIME GmbH ("NMS PRIME Community Version")
 * and others – powered by CableLabs. All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App;

use App\Observers\BaseObserver;
use DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Log;
use Module;
use Schema;
use Session;
use Str;

/**
 *	Class to add functionality – use instead of Eloquent for your models
 */
class BaseModel extends Eloquent
{
    use SoftDeletes;

    // use to enable force delete for inherit models
    protected $force_delete = 0;

    // flag showing if children also shall be deleted on Model::delete()
    protected $delete_children = true;

    protected $fillable = [];

    /**
     * @var bool
     *
     * @TODO: In future we should use this: https://stackoverflow.com/questions/29407818/is-it-possible-to-temporarily-disable-event-in-laravel/51301753#51301753
     * or with laravel 8 this: https://laravel.com/docs/8.x/eloquent#saving-a-single-model-without-events
     */
    public $observer_enabled = true;

    protected $connection = 'pgsql';
    protected $dateFormat = 'Y-m-d H:i:sO';

    /**
     * View specific stuff
     */
    // set this variable in a function model to true and implement into view_index_label() if it shall not be deletable on index page
    public $index_delete_disabled = false;

    // Add Comment here. ..
    protected $guarded = ['id', 'cachedIndexTableCount'];

    public const ABOVE_MESSAGES_ALLOWED_TYPES = [
        'info',    // Blue
        'success', // Green
        'warning', // Orange
        'error',   // Red
    ];

    public const ABOVE_MESSAGES_ALLOWED_PLACES = [
        'index_list',
        'form',
        'relations',
    ];

    /**
     * Contains all implemented index filters and is also used as whitelist.
     *
     * @var array
     */
    public const AVAILABLE_FILTERS = [];

    /**
     * Helper to get the model name.
     *
     * @author Patrick Reichel
     */
    public function get_model_name()
    {
        $model_name = get_class($this);
        $model_name = explode('\\', $model_name);

        return array_pop($model_name);
    }

    /**
     * Init Observer
     */
    public static function boot()
    {
        parent::boot();

        $model_name = static::class;

        // GuiLog has to be excluded to prevent an infinite loop log entry creation
        if ($model_name == GuiLog::class) {
            return;
        }

        // we simply add BaseObserver to each model
        // the real database writing part is in singleton that prevents duplicat log entries
        $model_name::observe(new BaseObserver);
    }

    /**
     * Placeholder if specific Model does not have any rules
     */
    public function rules()
    {
        return [];
    }

    public function set_index_delete_disabled()
    {
        $this->index_delete_disabled = true;
    }

    /**
     * Basefunction for generic use - is needed to place the related html links generically in the edit & create views
     * Place this function in the appropriate model and return the relation to the model it belongs
     *
     * NOTE: this function will return null in all create contexts, because at this time no relation exists!
     */
    public function view_belongs_to()
    {
    }

    /**
     * Use PHP Reflection API to receive the default of a given Property.
     * CAUTION this returns not the current state of the property.
     *
     * @param  string  $class  Basename of class or fully qualified classname
     * @param  string  $property  Property you want to receive the default value for
     * @return mixed
     */
    public function getDefaultProperty(string $class, string $property)
    {
        if (! strpos($class, '\\')) {
            $class = $this->get_models()[$class];
        }

        return (new \ReflectionClass($class))->getDefaultProperties()[$property];
    }

    /**
     * Relation to Ticket if Ticketsystem is present.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany|\Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tickets()
    {
        if (Module::collections()->has('Ticketsystem')) {
            return  $this->morphMany(\Modules\Ticketsystem\Entities\Ticket::class, 'ticketable');
        }

        return new \Illuminate\Database\Eloquent\Relations\HasMany($this->newQuery(), $this, '', '', '');
    }

    /**
     * Basefunction to define tabs with associated panels (relation or view) for the models edit page
     * E.g. Add relation panel 'modems' on the right side of the contract edit page - see ContractController::view_has_many()
     * Note: Use Controller::editTabs() to define tabs refering to new pages
     *
     * @return array
     */
    public function view_has_many()
    {
        return [];
    }

    /**
     * Add Ticket relation to an edit view. This method should be called inside
     * the view_has_many() method and adds a relationship panel to the edit
     * blade.
     *
     * @param  array  $ret
     * @return void
     */
    public function addViewHasManyTickets(&$ret, $tabName = 'Edit')
    {
        if (Module::collections()->has('Ticketsystem')) {
            $ret[$tabName]['Ticket']['class'] = 'Ticket';
            $ret[$tabName]['Ticket']['relation'] = $this->tickets;
        }
    }

    /**
     * Basefunction for returning all objects that a model can have a one-to-one relation to
     * Place this function in the model where the edit/create view shall show all related objects
     *
     * @author Patrick Reichel
     *
     * @return an array with the appropriate hasOne()-functions of the model
     */
    public function view_has_one()
    {
        return [];
    }

    public function loadEditViewRelations()
    {
        return $this;
    }

    /**
     *	This returns an array with all possible enum values.
     *	Use this instead of hardcoding it e.g. in your view (where it has to be
     *		changed with changing/extending enum definition in database)
     *	You can also get an array with a first empty option – use this in create forms to
     *		show that this value is still not set
     *	call this method via YourModel::getPossibleEnumValues('yourEnumCol')
     *
     *	This method is following an idea found on:
     *		http://stackoverflow.com/questions/26991502/get-enum-options-in-laravels-eloquent
     *
     *	@author Patrick Reichel
     *
     *	@param name column name of your database defined as enum
     *	@param with_empty_option should an empty option be added?
     *	@return array with available enum options
     */
    public static function getPossibleEnumValues($name, $with_empty_option = false)
    {
        // create an instance of the model to be able to get the table name
        $instance = new static;

        // get metadata for the given column and extract enum options
        // Schema::getColumnType($instance->getTable(), $name); is not yet supported (Laravel v6.0) - throws exception - L8.1 probably supports it
        if (config('database.default') == 'pgsql') {
            $range = DB::select('SELECT enum_range(NULL::'.$instance->getTable().'_'.$name.')')[0]->enum_range;

            $values = str_replace(['{', '}'], '', $range);
        } else {
            // MySQL
            $type = DB::select(DB::raw('SHOW COLUMNS FROM '.$instance->getTable().' WHERE Field = "'.$name.'"'))[0]->Type;

            // create array with enum values (all values in brackets after “enum”)
            preg_match('/^enum\((.*)\)$/', $type, $matches);
            $values = $matches[1];
        }

        $enum_values = [];
        // add an empty option if wanted
        if ($with_empty_option) {
            $enum_values[0] = '';
        }

        // add options extracted from database
        foreach (explode(',', $values) as $value) {
            $v = trim($value, "'");
            $enum_values[$v] = $v;
        }

        return $enum_values;
    }

    /**
     * Get the names of all fulltext indexed database columns.
     * They have to be passed as a param to a MATCH-AGAINST query
     *
     * @param $table database to get index columns from
     * @return comma separated string of columns
     *
     * @author Patrick Reichel
     */
    protected function _getFulltextIndexColumns($table)
    {
        $cols = [];
        $indexes = DB::select(DB::raw('SHOW INDEX FROM '.$table));
        foreach ($indexes as $index) {
            if (($index->Key_name == $table.'_fulltext_all') && $index->Index_type == 'FULLTEXT') {
                array_push($cols, $index->Column_name);
            }
        }

        $cols = implode(',', $cols);

        return $cols;
    }

    /**
     * Get the filter to use for index view (used to show only new Tickets).
     * To make the filter available in datatables we use the session.
     *
     * @return array with key and data as keys
     */
    public static function storeIndexFilterIntoSession(): array
    {
        $filter = request('show_filter', 'all');

        if (! in_array($filter, static::AVAILABLE_FILTERS)) {
            $filter = 'all';
        }

        session([class_basename(static::class).'_show_filter' => $filter]);
        session(['filter_data' => $payload = e(request('data', ''))]);

        return [
            'key' => $filter,
            'data' => $payload,
        ];
    }

    /**
     * Get all models extending the BaseModel
     *
     * Attention: The array is cached in the session - so if modules are enabled/disabled
     *	you have to logout & login to rebuild the array again
     *
     * @return array of all models except base models
     *
     * @author Patrick Reichel,
     *         Torsten Schmidt: add modules path
     */
    public static function get_models()
    {
        if (Session::has('models')) {
            return Session::get('models');
        }

        // models to be excluded from search
        $exclude = [
            'AddressFunctionsTrait',
            'Ability',
            'BaseModel',
            'CsvData',
            'helpers',
            'BillingLogger',
            'TRCClass',	// static data; not for standalone use
            'CarrierCode', // cron updated data; not for standalone use
            'EkpCode', // cron updated data; not for standalone use
            'ProvVoipEnviaHelpers',
        ];
        $result = [];

        /*
         * Search all Models in /models Models Path
         */
        $models = glob(app_path().'/*.php');

        foreach ($models as $model) {
            $model = str_replace(app_path().'/', '', $model);
            $model = str_replace('.php', '', $model);
            if (array_search($model, $exclude) === false) {
                $namespace = 'App\\'.$model;
                if (is_subclass_of($namespace, '\App\BaseModel')) {
                    $result[$model] = $namespace;
                }
            }
        }

        /*
         * Search all Models in /Modules/../Entities Path
         */
        $path = base_path('modules');
        $dirs = [];
        $modules = \Module::allEnabled();
        foreach ($modules as $module) {
            array_push($dirs, $module->getPath().'/Entities');
        }

        foreach ($dirs as $dir) {
            $models = glob($dir.'/*.php');

            foreach ($models as $model) {
                preg_match("|$path/(.*?)/Entities/|", $model, $module_array);
                $module = $module_array[1];
                $model = preg_replace("|$path/(.*?)/Entities/|", '', $model);
                $model = str_replace('.php', '', $model);
                if (array_search($model, $exclude) === false) {
                    $namespace = "Modules\\$module\Entities\\".$model;
                    if (is_subclass_of($namespace, '\App\BaseModel')) {
                        $result[$model] = $namespace;
                    }
                }
            }
        }

        Session::put('models', $result);

        return $result;
    }

    protected function _guess_model_name($s)
    {
        return current(preg_grep('|.*?'.str_replace('_', '', $s).'$|i', $this->get_models()));
    }

    /**
     * Get all database fields
     *
     * @param table database table to get structure from
     * @return comma separated string of columns
     *
     * @author Patrick Reichel
     */
    public static function getTableColumns($table)
    {
        $columns = [];
        $cols = Schema::getColumnListing($table);

        foreach ($cols as $col) {
            $columns[] = $table.'.'.$col;
        }

        return implode(',', $columns);
    }

    /**
     * Generic function to build a list with key of id
     *
     * @param  array  $array  list of Models/Objects
     * @param 	String/Array 	$column 		sql column name(s) that contain(s) the description of the entry
     * @param  bool  $empty_option  true it first entry shall be empty
     * @return array $ret 			list
     */
    public function html_list($array, $columns, $empty_option = false, $separator = '--')
    {
        $ret = $empty_option ? [null => null] : [];

        if (is_string($columns)) {
            foreach ($array as $a) {
                $ret[$a->id] = $a->{$columns};
            }

            return $ret;
        }

        // column is array
        foreach ($array as $a) {
            $desc = [];
            foreach ($columns as $key => $c) {
                if ($a->{$c}) {
                    $desc[$key] = $a->{$c};
                }
            }

            $ret[$a->id] = implode($separator, $desc);
        }

        return $ret;
    }

    /**
     * Generic function to build a list with key of id and usage count.
     *
     * @param  array  $array  list of Models/Objects
     * @param String/Array  $column         sql column name(s) that contain(s) the description of the entry
     * @param  bool  $empty_option  true it first entry shall be empty
     * @param  string  $colname  the column to count
     * @param  string  $count_at  the database table to count at
     * @return array $ret            list
     *
     * @author Patrick Reichel
     */
    public function html_list_with_count($array, $columns, $empty_option = false, $separator = '--', $colname = '', $count_at = '')
    {
        $tmp = $this->html_list($array, $columns, $empty_option, $separator);
        if (! $colname || ! $count_at) {
            return $tmp;
        }

        $counts_raw = \DB::select("SELECT $colname AS value, COUNT($colname) AS count FROM $count_at WHERE deleted_at IS NULL GROUP BY $colname");
        $counts = [];
        foreach ($counts_raw as $entry) {
            $counts[$entry->value] = $entry->count;
        }

        $ret = [];
        foreach ($tmp as $id => $value) {
            $ret[$id] = array_key_exists($id, $counts) ? $value.' ('.$counts[$id].')' : $value.' (0)';
        }

        return $ret;
    }

    // Placeholder
    public static function view_headline()
    {
        return 'Need to be Set !';
    }

    // Placeholder
    public static function view_icon()
    {
        return '<i class="fa fa-circle-thin"></i>';
    }

    // Placeholder
    public static function view_no_entries()
    {
        return 'No entries found!';
    }

    // Placeholder
    public function view_index_label()
    {
        return 'Need to be Set !';
    }

    /**
     *	Returns a array of all children objects of $this object
     *  Note: - Must be called from object context
     *        - this requires straight forward names of tables an
     *          forgein key, like modem and modem_id.
     *
     *  NOTE: we define exceptions in an array where recursive deletion is disabled
     *  NOTE: we have to distinct between 1:n and n:m relations
     *
     *	@author Torsten Schmidt, Patrick Reichel
     *
     *	@return array of all children objects
     */
    public function get_all_children()
    {
        $relations = [
            '1:n' => [],
            'n:m' => [],
        ];
        // exceptions – the children (=their database ID fields) that never should be deleted
        $exceptions = [
            'company_id',
            'configfile_id',
            'costcenter_id',
            'country_id',	// not used yet
            //'mibfile_id',
            //'oid_id',
            'node_id',
            'product_id',
            'qos_id',
            'salesman_id',
            'sepaaccount_id',
            'voip_id',
        ];

        // Lookup all SQL Tables
        foreach (DB::getDoctrineSchemaManager()->listTableNames() as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if ($column != $this->table.'_id') {
                    continue;
                }

                if (in_array($column, $exceptions)) {
                    continue;
                }

                $children = DB::table($table)->where($column, $this->id)->get();

                foreach ($children as $child) {
                    $class_child_name = $this->_guess_model_name($table);

                    // check if we got a model name
                    if ($class_child_name) {
                        // yes! 1:n relation
                        $class = new $class_child_name;
                        $rel = $class->find($child->id);
                        if (! is_null($rel)) {
                            array_push($relations['1:n'], $rel);
                        }

                        continue;
                    }

                    // seems to be a n:m relation
                    $parts = $table == 'ticket_type_ticket' ? ['ticket_type', 'ticket'] : explode('_', $table);
                    foreach ($parts as $part) {
                        $class_child_name = $this->_guess_model_name($part);

                        // one of the models in pivot tables is the current model – skip
                        if ($class_child_name == get_class($this)) {
                            continue;
                        }

                        // add other model instances to relation array if existing
                        $class = new $class_child_name;
                        $id_col = $part.'_id';
                        // TODO: Replace next line with $rel = $class_child_name::find($child->{$id_col});
                        $rel = $class->find($child->{$id_col});
                        if (! is_null($rel)) {
                            array_push($relations['n:m'], $rel);
                        }
                    }
                }
            }
        }

        return $relations;
    }

    /**
     * Local Helper to differ between soft- and force-deletes
     *
     * @return type mixed
     */
    protected function _delete()
    {
        if (! $this->writeAllowed()) {
            return false;
        }
        if ($this->force_delete) {
            return parent::performDeleteOnModel();
        }

        return parent::delete();
    }

    /**
     * Recursive delete of all children objects
     *
     * @author Torsten Schmidt, Patrick Reichel
     *
     * @return bool
     *
     * @todo return state on success, should also take care of deleted children
     */
    public function delete()
    {
        if (! $this->writeAllowed()) {
            return false;
        }

        if (in_array($this->id, $this->undeletables())) {
            $msg = trans('messages.base.delete.failUndeletable', ['model' => $this->get_model_name(), 'id' => $this->id]);
            $this->addAboveMessage($msg, 'error');

            return false;
        }

        if ($this->delete_children) {
            $children = $this->get_all_children();
            // find and delete all children

            // deletion of 1:n related children is straight forward
            foreach ($children['1:n'] as $child) {
                // if one direct or indirect child cannot be deleted:
                // do not delete anything
                if (! $child->delete()) {
                    $msg = trans('messages.base.delete.failChild', ['model' => $this->get_model_name(), 'id' => $this->id, 'child_model' => $child->get_model_name(), 'child_id' => $child->id]);
                    $this->addAboveMessage($msg, 'error');

                    return false;
                }
            }

            // in n:m relations we have to detach instead of deleting if
            // child is related to others, too
            // this should be handled in class methods because BaseModel cannot know the possible problems
            foreach ($children['n:m'] as $child) {
                $delete_method = 'deleteNtoM'.$child->get_model_name();

                if (! method_exists($this, $delete_method)) {
                    // Keep Pivot Entries and children if method is not specified and just log a warning message
                    \Log::warning($this->get_model_name().' - N:M pivot entry deletion handling not implemented for '.$child->get_model_name());
                } elseif (! $this->{$delete_method}($child)) {
                    $msg = trans('messages.base.delete.failChildNM', ['model' => $this->get_model_name(), 'id' => $this->id, 'child_model' => $child->get_model_name(), 'child_id' => $child->id]);
                    $this->addAboveMessage($msg, 'error');

                    return false;
                }
            }
        }

        // always return this value (also in your derived classes!)
        $deleted = $this->_delete();
        $class = $this->get_model_name();
        $translatedClass = trans("messages.{$class}") != "messages.{$class}" ?: trans_choice("view.Header_{$class}", 1);

        if ($deleted) {
            $msg = trans('messages.base.delete.success', ['model' => $translatedClass, 'id' => $this->id]);
            $this->addAboveMessage($msg, 'success');
        } else {
            $msg = trans('messages.base.delete.fail', ['model' => $translatedClass, 'id' => $this->id]);
            $this->addAboveMessage($msg, 'error');
        }

        return $deleted;
    }

    public static function destroy($ids)
    {
        // checking if deletion is allowed is done in the delete method
        // so we don't have to check here

        $instance = new static;

        foreach ($ids as $id => $help) {
            $instance->findOrFail($id)->delete();
        }
    }

    /**
     * Placeholder for undeletable Elements of index tree view
     */
    public static function undeletables()
    {
        return [0 => 0];
    }

    /**
     * Checks if model is valid in specific timespan
     * (used for Billing or to calculate income for dashboard)
     *
     * Note: if param start_end_ts is not set the model must have a get_start_time- & get_end_time-Function defined
     *
     * @param 	timespan 		String		Yearly|Quarterly|Monthly|Now => Enum of Product->billing_cycle
     * @param 	time 			Integer 	Seconds since 1970 - check for timespan of specific point of time
     * @param 	start_end_ts 	Array 		UTC Timestamps [start, end] (in sec)
     * @return bool true, if model had valid dates during last month / year or is actually valid (now)
     *
     * @author Nino Ryschawy
     */
    public function isValid($timespan = 'monthly', $time = null, $start_end_ts = [])
    {
        $start = $start_end_ts ? $start_end_ts[0] : $this->get_start_time();
        $end = $start_end_ts ? $start_end_ts[1] : $this->get_end_time();

        // default - billing settlementruns/charges are calculated for last month
        $time = $time ?: strtotime('midnight first day of last month');

        switch (strtolower($timespan)) {
            case 'once':
                // E.g. one time or splitted payments of items - no open end! With end date: only on months from start to end
                return $end ? $start < strtotime('midnight first day of next month', $time) && $end > $time : date('Y-m', $start) == date('Y-m', $time);

            case 'monthly':
                // has valid dates in last month - open end possible
                return $start < strtotime('midnight first day of next month', $time) && (! $end || $end > $time);

            case 'quarterly':
                /* TODO: implement - 2 cases
                    * quarterly (quartalsweise): 1-3, 4-6, 7-9, 10-12 -> was already charged and wont be valid in next settlementrun
                    * quarter of a year (vierteljährlich): always 3 months from item start ->
                */
                break;

            case 'yearly':
                return $start < strtotime('midnight first day of january next year', $time) && (! $end || $end > strtotime('midnight first day of January this year', $time));

            case 'now':
                $time = strtotime('today');

                return $start <= $time && (! $end || $end > $time);

            default:
                \Log::error('Bad timespan param used in function '.__FUNCTION__);
                break;
        }

        return true;
    }

    /**
     * Helper to show info line above index_list|form depending on previous URL.
     *
     * @param  string  $msg  The message to be shown
     * @param  string  $type  The type [info|success|warning|error], default is 'info'
     * @param  string  $place  Where to show the message above [index_list, form, relations];
     *                         if not given try to determine from previous URL
     * @return bool true if message could be generated, false else
     *
     * @author Patrick Reichel
     */
    public function addAboveMessage($msg, $type = 'info', $place = null)
    {
        // check if type is valid
        if (! in_array($type, self::ABOVE_MESSAGES_ALLOWED_TYPES)) {
            throw new \UnexpectedValueException('$type has to be in ['.implode('|', self::ABOVE_MESSAGES_ALLOWED_TYPES).'], “'.$type.'” given.');
        }

        // determine or check place
        if (is_null($place)) {
            // check from where the deletion request has been triggered and set the correct var to show information
            // snippet taken from https://stackoverflow.com/questions/40690202/previous-route-name-in-laravel-5-1-5-8
            try {
                $prev_route_name = app('router')->getRoutes()->match(app('request')->create(\URL::previous()))->getName();
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
                Log::debug('Could not determine previous route after '.$msg);

                return false;
            }

            $place = Str::endsWith($prev_route_name, '.edit') ? 'form' : 'index_list';
        } elseif (! in_array($place, self::ABOVE_MESSAGES_ALLOWED_PLACES)) {
            throw new \UnexpectedValueException('$place has to be in ['.implode('|', self::ABOVE_MESSAGES_ALLOWED_PLACES).'], “'.$place.'” given.');
        }

        // build the message target
        $target = 'tmp_'.$type.'_above_'.$place;

        // push to session ⇒ will be shown once via resources/views/Generic/above_infos.blade.php
        Session::push($target, $msg);
    }

    public static function getUser()
    {
        $user = \Auth::user();

        return $user ? $user->first_name.' '.$user->last_name : 'cronjob';
    }

    /**
     * Helper to check if writing (=changing the database) is allowed
     * Intercept writing operations on ProvHA slave machines instead of let laravel throw PDO exceptions.
     *
     * @author Patrick Reichel
     */
    public function writeAllowed()
    {
        // in ProvHA environments: Only master is allowed to change the database
        if (\Module::collections()->has('ProvHA')) {
            if ('slave' == config('provha.hostinfo.ownState')) {
                $msg = trans('provha::messages.db_change_forbidden_not_master', ['state' => config('provha.hostinfo.ownState')]);
                $this->addAboveMessage($msg, 'error');
                \Log::error('Slave tried to write do database in '.get_class($this).'::'.debug_backtrace()[1]['function'].'()');

                return false;
            }
        }

        // default: writing is allowed
        return true;
    }

    /**
     * Overwrite parent to add check if changing database is allowed.
     *
     * @author Patrick Reichel
     */
    public function save(array $options = [])
    {
        if (! $this->writeAllowed()) {
            return false;
        }

        return parent::save($options);
    }

    /**
     * Overwrite parent to add check if changing database is allowed.
     *
     * @author Patrick Reichel
     */
    public function forceDelete()
    {
        if (! $this->writeAllowed()) {
            return false;
        }

        return parent::forceDelete();
    }

    /**
     * Overwrite parent to add check if changing database is allowed.
     *
     * @author Patrick Reichel
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (! $this->writeAllowed()) {
            return false;
        }

        return parent::update($attributes, $options);
    }

    public function getCachedIndexTableCountAttribute()
    {
        return cache('indexTables.'.$this->table, 0);
    }

    /**
     * Check if entry count of the index table of the model exceeds configured threshhold
     * Huge tables behave a bit different to not degrade performance - see description in config/datatables.php
     */
    public function hasHugeIndexTable()
    {
        if (
            config('datatables.isIndexCachingEnabled') &&
            $this->cachedIndexTableCount > config('datatables.hugeTableThreshhold')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Helper to log and output messages
     *
     * @param $level The log level
     * @param $msg The message to be logged and printed
     * @param $transArgs Array to be given to the trans() helper
     * @param $type color Bootsrap color for GUI (one of self::ABOVE_MESSAGES_ALLOWED_TYPES)
     * @param $place Where to put the message in GUI (one of self::ABOVE_MESSAGES_ALLOWED_PLACES)
     *
     * @author Patrick Reichel
     */
    public function logAndPrint($level, $msg, $transArgs = [], $type = null, $place = null)
    {
        // allowed levels are the keys, values are default types
        $allowedLevels = [
            'debug' => 'info',
            'info' => 'info',
            'notice' => 'info',
            'warning' => 'warning',
            'error' => 'error',
            'critical' => 'error',
            'alert' => 'error',
            'emergency' => 'error',
        ];

        // check if valid level has been given
        if (! array_key_exists($level, $allowedLevels)) {
            throw new \Exception('Invalid log level – “'.$level.'” not in ['.implode('|', $allowedLevels).']');
        }

        // check if type is given; set to default if not
        if (is_null($type)) {
            $type = $allowedLevels[$level];
        }

        // store the current locale
        $locale = \App::getLocale();

        // log the message (always in English – may be helpful on debugging a spanish system)
        \App::setLocale('en');
        \Log::{$level}(trans($msg, $transArgs));

        // reset the locale
        \App::setLocale($locale);

        // CLI output
        if (app()->runningInConsole()) {
            echo strtoupper($level).": $msg\n";

            return;
        }

        // GUI output
        $msg = trans(strtoupper($type)).': '.trans($msg, $transArgs);
        $this->addAboveMessage($msg, $type, $place);
    }
}
