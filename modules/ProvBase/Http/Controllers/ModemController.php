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

namespace Modules\ProvBase\Http\Controllers;

use App\GlobalConfig;
use App\Http\Controllers\BaseViewController;
use App\Sla;
use App\V1\Repository;
use Bouncer;
use Illuminate\Support\Facades\Session;
use Modules\ProvBase\Entities\Configfile;
use Modules\ProvBase\Entities\Contract;
use Modules\ProvBase\Entities\Modem;
use Modules\ProvBase\Entities\ModemOption;
use Modules\ProvBase\Entities\ProvBase;
use Modules\ProvBase\Services\ModemService;
use Nwidart\Modules\Facades\Module;
use Request;
use View;

class ModemController extends \BaseController
{
    protected $index_create_allowed = false;
    protected $save_button_name = 'Save / Restart';
    protected $save_button_title_key = 'modem_save_button_title';

    // save button title ? for a help message
    protected $edit_view_second_button = true;
    protected $second_button_name = 'Restart via NetGw';
    protected $second_button_title_key = 'modem_force_restart_button_title';

    /**
     * Contains the configfile of the modem object for the current request to only have one DB query
     *
     * @var obj
     */
    private $configfile;

    public function edit($id)
    {
        $provbase = ProvBase::first();

        if ($provbase->additional_modem_reset) {
            $this->edit_view_third_button = true;
            $this->third_button_name = trans('messages.resetModem');
            $this->third_button_title_key = 'modem_reset_button_title';
        }

        if ($provbase->sync_provision) {
            $this->edit_view_fourth_button = true;
            $this->fourth_button_name = trans('messages.syncProvision');
            $this->fourth_button_title_key = 'syncProvisionButtonTitle';
        }

        return parent::edit($id);
    }

    /**
     * defines the formular fields for the edit and create view
     */
    public function view_form_fields($model = null)
    {
        if (! $model) {
            $model = new Modem;
        }

        if (! $model->exists) {
            $config = GlobalConfig::find(1);
            $model->country_code = $config->default_country_code;

            if (! $model->ppp_password) {
                $model->ppp_password = \Acme\php\Password::generatePassword();
            }
        }

        $pos = explode(',', Request::get('pos'));
        if (count($pos) == 2) {
            [$model['lat'], $model['lng']] = $pos;

            $model->fill(Modem::where([['lng', $model['lng']], ['lat', $model['lat']]])
                ->select('street', 'house_number', 'zip', 'city', 'district', 'country_code')
                ->first()
                ?->getAttributes()
                ?? []
            );
        }

        $installation_address_change_date_options = ['placeholder' => 'YYYY-MM-DD'];
        // check if installation_address_change_date is readonly (address change has been sent to envia TEL API)
        if ($model['installation_address_change_date'] && Module::collections()->has('ProvVoipEnvia')) {
            $orders = \Modules\ProvVoipEnvia\Entities\EnviaOrder::where('modem_id', $model->id)
                ->where('method', 'contract/relocate')
                ->where('orderdate', '>=', $model['installation_address_change_date'])
                ->get();

            foreach ($orders as $order) {
                if (! \Modules\ProvVoipEnvia\Entities\EnviaOrder::orderstate_is_final($order)) {
                    array_push($installation_address_change_date_options, 'readonly');
                }
            }
        }

        $help['contract'] = $selectPropertyMgmt = [];
        if (Module::collections()->has('PropertyManagement')) {
            $selectPropertyMgmt = ['select' => 'noApartment'];
            $help['contract'] = ['help' => trans('propertymanagement::help.modem.contract_id')];
        }

        $cfIds = $this->dynamicDisplayFormFields();
        $qosIds = \Modules\ProvBase\Entities\Qos::pluck('id', 'id')->toArray();

        if (Module::collections()->has('HfcCustomer') && $model->exists) {
            $rect = [round($model->lng, 4) - 0.0001, round($model->lng, 4) + 0.0001, round($model->lat, 4) - 0.0001, round($model->lat, 4) + 0.0001];
            $geopos = link_to_route('CustomerModem.showModems', trans('messages.geopos_x_y'), ['modemId' => $model->id]).'    ('.link_to_route('CustomerRect.show', trans('messages.proximity'), $rect).')';
        } else {
            $geopos = trans('messages.geopos_x_y');
        }

        // label has to be the same like column in sql table
        $a = [
            ['form_type' => 'text', 'name' => 'name', 'description' => 'Name'],
            ['form_type' => 'select', 'name' => 'configfile_id', 'description' => 'Configfile', 'value' => $this->setupSelect2Field($model, 'Configfile'), 'help' => trans('helper.configfile_count').' '.trans('helper.modem.configfileSelect'), 'options' => ['class' => 'select2-ajax', 'ajax-route' => route('Modem.select2', ['relation' => 'configfiles'])], 'select' => $cfIds['keyById']],
            ['form_type' => 'text', 'name' => 'hostname', 'description' => 'Hostname', 'options' => ['readonly'], 'hidden' => 'C', 'space' => 1],
            // TODO: show this dropdown only if necessary (e.g. not if creating a modem from contract context)
            ['form_type' => 'text', 'name' => 'mac', 'description' => 'MAC Address', 'options' => ['placeholder' => 'AA:BB:CC:DD:EE:FF'], 'autocomplete' => ['modem'], 'help' => trans('helper.mac_formats')],
            ['form_type' => 'text', 'name' => 'serial_num', 'description' => 'Serial Number / CWMP-ID'],
            ['form_type' => 'text', 'name' => 'ppp_username', 'description' => 'PPP Username', 'select' => $cfIds['tr069'], 'options' => [$model->exists ? 'readonly' : '']],
            ['form_type' => 'text', 'name' => 'ppp_password', 'description' => 'PPP Password', 'select' => $cfIds['tr069']],
            array_merge(['form_type' => 'select', 'name' => 'contract_id', 'description' => 'Contract', 'hidden' => 'E', 'value' => $this->setupSelect2Field($model, 'Contract'), 'options' => ['class' => 'select2-ajax', 'ajax-route' => route('Modem.select2', ['relation' => 'contracts'])]], $help['contract']),
            ['form_type' => 'checkbox', 'name' => 'public', 'description' => 'Public CPE', 'value' => '1'],
            ['form_type' => 'checkbox', 'name' => 'internet_access', 'description' => 'Internet Access',
                'value' => '1', 'help' => trans('helper.Modem_InternetAccess'),
                'options' => $model->contract?->isCanceled() ? ['onclick' => 'return false;', 'readonly'] : [],
            ],
        ];

        if (false && Sla::first()->valid()) {
            $a[] = ['form_type'=> 'text', 'name' => 'formatted_support_state', 'description' => 'Support State', 'field_value' => ucfirst(str_replace('-', ' ', $model->support_state)), 'help'=>trans('helper.modemSupportState.'.$model->support_state), 'help_icon'=> $model->getFaSmileClass()['fa-class'], 'options' =>['readonly'], 'color'=>$model->getFaSmileClass()['bs-class']];
        }

        $c = [
            ['form_type' => 'text', 'name' => 'company', 'description' => 'Company'],
            ['form_type' => 'text', 'name' => 'department', 'description' => 'Department'],
            ['form_type' => 'select', 'name' => 'salutation', 'description' => 'Salutation', 'value' => $model->getSalutationOptions()],
            ['form_type' => 'text', 'name' => 'firstname', 'description' => 'Firstname'],
            ['form_type' => 'text', 'name' => 'lastname', 'description' => 'Lastname'],
            ['form_type' => 'date', 'name' => 'birthday', 'description' => 'Birthday', 'space' => 1, 'options' => ['placeholder' => 'YYYY-MM-DD']],

            array_merge(['form_type' => 'text', 'name' => 'street', 'description' => 'Street', 'autocomplete' => ['Contract']], $selectPropertyMgmt),
            array_merge(['form_type' => 'text', 'name' => 'house_number', 'description' => 'House Number'], $selectPropertyMgmt),
            array_merge(['form_type' => 'text', 'name' => 'zip', 'description' => 'Postcode', 'autocomplete' => ['Contract']], $selectPropertyMgmt),
            array_merge(['form_type' => 'text', 'name' => 'city', 'description' => 'City', 'autocomplete' => ['Contract']], $selectPropertyMgmt),
            array_merge(['form_type' => 'text', 'name' => 'district', 'description' => 'District', 'autocomplete' => ['Contract']], $selectPropertyMgmt),
            array_merge(['form_type' => 'text', 'name' => 'country_code', 'description' => 'Country code', 'help' => 'ISO 3166 ALPHA-2 (two characters)'], $selectPropertyMgmt),
        ];

        if (Module::collections()->has('PropertyManagement')) {
            $c[] = ['form_type' => 'select', 'name' => 'apartment_id', 'description' => 'Apartment', 'hidden' => 0,
                'value' => $this->setupSelect2Field($model, 'Apartment'), 'help' => trans('propertymanagement::help.apartmentList'),
                'options' => ['class' => 'select2-ajax', 'data-allow-clear' => 'true',
                    'ajax-route' => route('Apartment.select2', ['relation' => 'apartments']), ],
            ];
        } else {
            $c[] = ['form_type' => 'text', 'name' => 'apartment_nr', 'description' => 'Apartment number'];
        }

        if (Module::collections()->has('BillingBase')) {
            $b = [[
                'form_type' => 'select', 'name' => 'qos_id', 'description' => 'QoS', 'value' => $this->setupSelect2Field($model, 'Qos'), 'help' => trans('helper.modem.qosCount'),
                'options' => ['class' => 'select2-ajax', 'ajax-route' => route('Modem.select2', ['relation' => 'qos'])], 'select' => $qosIds,
            ]];
            $c[] = ['form_type' => 'checkbox', 'name' => 'address_to_invoice', 'description' => trans('billingbase::view.modemAddressToInvoice'), 'space' => '1', 'help' => trans('billingbase::messages.modemAddressToInvoice')];
        } else {
            $b = [['form_type' => 'select', 'name' => 'qos_id', 'description' => 'QoS', 'value' => $model->html_list($model->qualities(), 'name'), 'space' => '1']];
            $c[12] = array_merge($c[12], ['space' => 1]);
        }

        if (Module::collections()->has('Altiplano') &&
            (new \Modules\Altiplano\Helpers\AltiplanoApi())->token
        ) {
            $availableFiberNames = \Modules\Altiplano\Services\AltiplanoService::resolveAvailableFiberNames();
            $b[] = ['form_type' => 'select', 'name' => 'fiber_name', 'description' => 'Fiber Name', 'value' => $availableFiberNames, 'space' => '1'];
        }

        $d = [
            ['form_type' => 'html', 'name' => 'geopos', 'description' => $geopos, 'html' => BaseViewController::geoPosFields($model)],
            ['form_type' => 'text', 'name' => 'geocode_source', 'description' => 'Geocode origin', 'help' => trans('helper.Modem_GeocodeOrigin'), 'options' => ['readonly'], 'space' => 1],

            ['form_type' => 'date', 'name' => 'installation_address_change_date', 'description' => 'Date of installation address change', 'hidden' => 'C', 'options' => $installation_address_change_date_options, 'help' => trans('helper.Modem_InstallationAddressChangeDate')], // Date of adress change for notification at telephone provider - important for localisation of emergency calls
            ['form_type' => 'text', 'name' => 'inventar_num', 'description' => 'Inventar Number'],
            ['form_type' => 'textarea', 'name' => 'description', 'description' => 'Description'],
            ['form_type' => 'text', 'name' => 'additional', 'description' => 'Additional info', 'help' => trans('helper.modem.additional'), 'autocomplete' => [], 'space' => 1],
        ];

        return array_merge($a, $b, $c, $d);
    }

    /**
     * Prepare the preselected model for the select 2 field. Currently only
     * single select is supported.
     *
     * @param  BaseModel|null  $model  model in edit view, null in create context
     * @param  string  $class  unqualified name of the Class
     * @param  string|null  $field  Name of the input field
     * @param  string|null  $fn  Name of the relation(function)
     * @return array
     */
    protected function setupSelect2Field($model, string $class, string $field = null, string $fn = null): array
    {
        // chose the first ONT configfile for new dreamfiber „modems“
        if (
            Module::collections()->has('Dreamfiber') &&
            (! $model->exists) &&
            (in_array(Request::get('type'), ['DF_OTO_STORAGE', 'DF_OTO']))
        ) {
            $cf = Configfile::where('device', '=', 'ont')->select('id', 'name')->first();

            return [$cf->id => $cf->name];
        }

        // default – use version of BaseController
        return parent::setupSelect2Field($model, 'Configfile');
    }

    /**
     * Change form fields based on selected configfile device (cm || tr069)
     *
     * @author Roy Schneider
     *
     * @return array with array of all ids from configfile of device cm or tr069 and string of ids from configfile of device cm/tr069
     */
    public function dynamicDisplayFormFields()
    {
        $tr069Configfiles = Configfile::where('device', 'tr069')->pluck('id', 'id');
        $ontConfigfiles = Configfile::where('device', 'ont')->pluck('id', 'id');
        // keys are distinct here – can safely use the “+” operator
        $allConfigfiles = $tr069Configfiles->toArray() + $ontConfigfiles->toArray();
        ksort($allConfigfiles);

        return [
            'keyById' => $allConfigfiles,
            'tr069' => $tr069Configfiles->isEmpty() ? 'hide' : $tr069Configfiles->implode(' '),
            'ont' => $ontConfigfiles->isEmpty() ? 'hide' : $ontConfigfiles->implode(' '),
        ];
    }

    /**
     * Get all management jobs for envia TEL
     *
     * @author Patrick Reichel
     *
     * @param $modem current modem object
     * @return array containing linktexts and URLs to perform actions against REST API
     */
    public static function _get_envia_management_jobs($modem)
    {
        $provvoipenvia = new \Modules\ProvVoipEnvia\Entities\ProvVoipEnvia();

        if (Bouncer::can('view', 'Modules\ProvVoipEnvia\Entities\ProvVoipEnvia')) {
            return $provvoipenvia->get_jobs_for_view($modem, 'modem');
        }
    }

    /**
     * Modem Tabs Controller. -> Panel Header Right
     * See: BaseController native function for more information
     *
     * @param Modules\ProvBase\Entities\Modem
     *
     * @return: array, e.g. [['name' => '..', 'route' => '', 'link' => [$view_var->id]], .. ]
     *
     * @author: Torsten Schmidt
     */
    public function editTabs($model)
    {
        $defaultTabs = parent::editTabs($model);

        if (
            $model->provNetelement &&
            $model->provNetelement->base_type_id !== array_search('NetGw', \Modules\HfcReq\Entities\NetElementType::$undeletables)
        ) {
            $tabs = $model->provNetelement->tabs();
            $tabs[] = $defaultTabs[1];
            unset($tabs[1]['route']);

            return $tabs;
        }

        // Defines which edit page you came from
        Session::put('Edit', 'Modem');

        $analysisTabs = $model->analysisTabs();
        unset($defaultTabs[0]);
        unset($analysisTabs[0]['route']);

        return array_merge($defaultTabs, $analysisTabs);
    }

    /**
     * Perform a fulltext search.
     *
     * Changes to BaseController:
     *  - Topography Mode when HfcCustomer Module is active
     *  - also search for Contracts while searching for Modems
     *
     * @author Torsten Schmidt
     */
    public function fulltextSearch()
    {
        $obj = static::get_model_obj();

        if (! Module::collections()->has('HfcCustomer')) {
            return parent::fulltextSearch();
        }

        // get the search scope
        $scope = Request::get('scope');
        $mode = Request::get('mode');
        $query = Request::get('query');
        $pre_f = Request::get('preselect_field');
        $pre_v = Request::get('preselect_value');
        $pre_t = '';

        // perform Modem search
        $modems = $obj->getFulltextSearchResults($scope, $mode, $query, $pre_f, $pre_v)[0];

        // perform contract search
        $obj = new Contract;
        $contracts = $obj->getFulltextSearchResults('contract', $mode, $query, $pre_f, $pre_v)[0];

        // generate Topography
        if (Request::get('topo') == '1') {
            // Generate KML file
            $customer = new \Modules\HfcCustomer\Http\Controllers\CustomerTopoController;
            $file = $customer->kml_generate($modems);

            $view_header_right = 'Topography';
            $body_onload = 'init_for_map';
        }

        if ($pre_f && $pre_v) {
            $pre_t = ' Search in '.strtoupper($pre_f).' '.\Modules\HfcReq\Entities\NetElement::find($pre_v)->name;
        }

        $tabs = [['name' => 'List', 'route' => 'Modem.fulltextSearch', 'link' => ['topo' => '0', 'scope' => $scope, 'mode' => $mode, 'query' => $query, 'preselect_field' => $pre_f, 'preselect_value' => $pre_v]],
            ['name' => 'Topography', 'icon' => 'map', 'route' => 'Modem.fulltextSearch', 'link' => ['topo' => '1', 'scope' => $scope, 'mode' => $mode, 'query' => $query, 'preselect_field' => $pre_f, 'preselect_value' => $pre_v]], ];

        $view_var = $modems->get();
        $view_var = $view_var->merge($contracts->get());
        $route_name = 'Modem';
        $view_header = 'Modems '.$pre_t;
        $create_allowed = $this->index_create_allowed;

        $preselect_field = Request::get('preselect_field');
        $preselect_value = Request::get('preselect_value');

        return \View::make('provbase::Modem.index', $this->compact_prep_view(compact('tabs', 'view_header_right', 'view_var', 'create_allowed', 'file', 'target', 'route_name', 'view_header', 'body_onload', 'field', 'search', 'preselect_field', 'preselect_value')));
    }

    /**
     * Perform GenieACS task
     *
     * @author Ole Ernst
     *
     * @return string
     */
    public static function genieTask($id)
    {
        if (! $modem = Modem::find($id)) {
            return trans('messages.modemNotFound');
        }

        if (! $genieId = $modem->getGenieId()) {
            return;
        }

        // setWlan and setDns
        $formInput = request('taskName');

        $task = Request::get('task');
        // used for commands like: "cmd;Fernzugang aktivieren;set;InternetGatewayDevice.User.1.Enable;1"
        if (is_array($task) && ! $formInput) {
            foreach ($task as $data) {
                if (str_contains($data, 'getParameterValues')) {
                    $modem->callGenieAcsApi("devices/$genieId/tasks?connection_request", 'POST', $data);
                    continue;
                }

                $modem->callGenieAcsApi("devices/$genieId/tasks?connection_request", 'POST', $data);
            }

            return trans('messages.modemAnalysis.actionExecuted');
        }

        // setWlan, setDns, blockDhcp, unblockDhcp
        if ($formInput || \Str::startsWith($task, 'custom/')) {
            $cwmpModel = $modem->getCwmpDataModel($modem->getGenieId());
            $task = request('taskName') ?? $task;
            $taskName = \Str::after($task, 'custom/');

            return $cwmpModel->$taskName();
        }

        // manually delete tasks
        if (\Str::startsWith($task, 'tasks/')) {
            Modem::callGenieAcsApi($task, 'DELETE');

            return trans('messages.modemAnalysis.actionExecuted');
        }

        $taskDecode = json_decode($task, true);
        if ($taskDecode === null) {
            return trans('messages.JsonDecodeFailed');
        }

        if ($taskDecode == ['name' => 'connection_request']) {
            $modem->callGenieAcsApi("devices/$genieId/tasks?timeout=3000&connection_request", 'POST', '');

            Session::push('tmp_info_above_form', trans('messages.modemAnalysis.actionExecuted'));

            return trans('messages.modemAnalysis.actionExecuted');
        }

        foreach (['factoryReset', 'reboot'] as $action) {
            if ($taskDecode === ['name' => $action] &&
                json_decode(Modem::callGenieAcsApi("tasks?query={\"device\":\"$genieId\",\"name\":\"$action\"}", 'GET'))) {
                return $action.trans('messages.modemAnalysis.actionAlreadyScheduled');
            }
        }

        Modem::callGenieAcsApi("devices/$genieId/tasks?connection_request", 'POST', $task);

        return trans('messages.modemAnalysis.actionExecuted');
    }

    /**
     * Refresh a GenieACS object.
     *
     * @author Roy Schneider
     *
     * @return string
     */
    public function refreshGenieObject($id)
    {
        $modem = Modem::find($id);

        $modem::callGenieAcsApi(
            'devices/'.$modem->getGenieId().'/tasks?timeout=3000&connection_request',
            'POST',
            json_encode(['name' => 'refreshObject', 'objectName' => request('object')])
        );

        return trans('messages.modemAnalysis.refreshFinished');
    }

    /**
     * Return tree view of all used firmwares
     *
     * @return View
     *
     * @author Ole Ernst
     */
    public function firmware_view()
    {
        if (! Module::collections()->has('ProvMon')) {
            return $this->missingModule('Prime Monitoring');
        }

        $view_var = Modem::get_firmware_tree();

        $headline = $view_header = 'Firmware';
        $create_allowed = false;

        return \View::make('Generic.tree', $this->compact_prep_view(compact('headline', 'view_header', 'view_var', 'create_allowed')));
    }

    public function cwmpDeviceView()
    {
        $knownDevices = Modem::join('configfile', 'modem.configfile_id', 'configfile.id')->where('configfile.device', 'tr069')->pluck('serial_num');

        $discoveredDevices = array_map(function ($m) use ($knownDevices) {
            return [
                $m['_deviceId']['_SerialNumber'] ?? null,
                isset($m['_lastInform']) ? \Carbon\Carbon::parse($m['_lastInform'])->diffForHumans() : null,
                $knownDevices->contains($m['_deviceId']['_SerialNumber']) ? 'success' : 'warning',
            ];
        }, json_decode(Modem::callGenieAcsApi('devices?projection=_deviceId._SerialNumber,_lastInform', 'GET'), true));

        $headline = 'CWMP';

        return \View::make('provbase::GenieACS.devices', $this->compact_prep_view(compact('headline', 'discoveredDevices')));
    }

    /**
     * Returns MAC addresses of all modems (including the respective CMTS name),
     * which were denied booting in the last two hours, since they are unknown.
     * This is used to autocomplete the MAC address field when creating a modem.
     *
     * Cache is used, since we don't want to search through the log on every XHR.
     *
     * @author Ole Ernst
     */
    public static function unknownMACAddresses()
    {
        $all = \Cache::remember('unknownMACAddresses', now()->addMinute(), function () {
            $matches = [];

            exec("sudo /usr/bin/journalctl -udhcpd -p3 -S-2h -ocat | grep 'no free leases$' | sort -u", $lines);
            // exec("grep 'no free leases$' /var/log/messages | grep -o 'DHCPDISCOVER from.*' | sort -u", $lines);

            foreach ($lines as $line) {
                if (preg_match('/DHCPDISCOVER from (([[:xdigit:]]{2}:){5}([[:xdigit:]]{2})).*network ([^:]+)/', $line, $match)) {
                    $matches[] = ['label' => "$match[1] - $match[4]", 'value' => $match[1]];
                }
            }

            return $matches;
        });

        $filter = Request::get('q');

        return array_filter($all, function ($element) use ($filter) {
            return stripos($element['value'], $filter) !== false;
        });
    }

    /**
     * Return status of modem via API
     *
     * @return JsonResponse
     *
     * @author Ole Ernst
     */
    public function api_status($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);

        $data = [];
        if (Module::collections()->has('ProvMon') && Request::get('verbose') == 'true') {
            $data['data'] = $this->analysis($id, true);
        }

        $domain_name = ProvBase::first()->domain_name;
        exec("sudo ping -c1 -i0 -w1 {$modem->hostname}.$domain_name", $ping, $offline);

        return response()->v0ApiReply($data, ! $offline, $id);
    }

    /**
     * Restart modem via API
     *
     * @return JsonResponse
     *
     * @author Ole Ernst
     */
    public function api_restart($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);
        $modem->restart_modem();

        return response()->v0ApiReply([], true, $id);
    }

    public function apiGeoPos($ver)
    {
        if ($ver !== '1') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }
        $resourceOptions = $this->parseResourceOptions();
        $service = new ModemService(new Repository(static::get_model_obj()));
        $data = $service->getPosModems($resourceOptions);
        $parsedData = $this->parseData($data, $resourceOptions);

        return $this->response($parsedData);
    }

    public function apiGetModemsOfSameLocation($ver, $id)
    {
        if ($ver !== '1') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }
        $resourceOptions = $this->parseResourceOptions();
        $service = new ModemService(new Repository(static::get_model_obj()));
        $data = $service->getModemsOfSameLocation($id, $resourceOptions);
        $parsedData = $this->parseData($data, $resourceOptions);

        return $this->response($parsedData);
    }

    /**
     * Block DHCP Server of the modem via API.
     *
     * @return JsonResponse
     *
     * @author Roy Schneider
     */
    public function api_blockDhcp($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);

        $cwmpModel = $modem->getCwmpDataModel($modem->getGenieId());
        ModemOption::updateOrCreate(['modem_id' => $modem->id, 'key' => 'dhcp_enable'], ['value' => 'false']);

        if ($cwmpModel) {
            $cwmpModel->blockDhcp();
        }

        return response()->v0ApiReply([], true, $id);
    }

    /**
     * Unblock DHCP Server of the modem via API.
     *
     * @return JsonResponse
     *
     * @author Roy Schneider
     */
    public function api_unblockDhcp($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);

        $cwmpModel = $modem->getCwmpDataModel($modem->getGenieId());
        ModemOption::updateOrCreate(['modem_id' => $modem->id, 'key' => 'dhcp_enable'], ['value' => 'true']);

        if ($cwmpModel) {
            $cwmpModel->unblockDhcp();
        }

        return response()->v0ApiReply([], true, $id);
    }

    /**
     * Set DNS of the modem via API.
     *
     * @return JsonResponse
     *
     * @author Roy Schneider
     */
    public function api_setDns($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);

        $cwmpModel = $modem->getCwmpDataModel($modem->getGenieId());
        ModemOption::updateOrCreate(['modem_id' => $modem->id, 'key' => 'custom_dns_enable'], ['value' => 'true']);
        ModemOption::updateOrCreate(['modem_id' => $modem->id, 'key' => 'custom_dns'], ['value' => request('dns')]);

        if ($cwmpModel) {
            $cwmpModel->setDns(request('dns'));
        }

        return response()->v0ApiReply([], true, $id);
    }

    /**
     * Unset DNS of the modem via API.
     *
     * @return JsonResponse
     *
     * @author Roy Schneider
     */
    public function api_unsetDns($ver, $id)
    {
        if ($ver !== '0') {
            return response()->v0ApiReply(['messages' => ['errors' => ["Version $ver not supported"]]]);
        }

        $modem = static::get_model_obj()->findOrFail($id);

        $cwmpModel = $modem->getCwmpDataModel($modem->getGenieId());
        ModemOption::updateOrCreate(['modem_id' => $modem->id, 'key' => 'custom_dns_enable'], ['value' => 'false']);

        if ($cwmpModel) {
            $cwmpModel->setDns('0.0.0.0,0.0.0.0');
        }

        return response()->v0ApiReply([], true, $id);
    }

    /**
     * Set nullable fields.
     *
     * @author Patrick Reichel
     */
    public function prepare_input($data)
    {
        $data = parent::prepare_input($data);

        // set this to null if no value is given
        $nullable_fields = [
            'contract_ext_creation_date',
            'contract_ext_termination_date',
            'installation_address_change_date',
        ];
        $data = $this->_nullify_fields($data, $nullable_fields);

        // ISO 3166 country codes are uppercase
        $data['country_code'] = \Str::upper($data['country_code']);

        if (isset($data['serial_num'])) {
            $data['serial_num'] = \Str::upper($data['serial_num']);
        }

        if (! $this->configfile) {
            $this->configfile = Configfile::find($data['configfile_id']);
        }

        if ($this->configfile && $this->configfile->device != 'tr069') {
            $data['ppp_password'] = null;
        }

        $data['mac'] = unifyMac($data['mac'] ?? null);

        return $data;
    }

    /**
     * Inheritet update function to handle force restart button as
     * we dont want to update the modem when this button is clicked
     */
    public function update($id)
    {
        if (! Request::filled('_2nd_action') && ! Request::filled('_3rd_action') && ! Request::filled('_4th_action')) {
            return parent::update($id);
        }

        $modem = Modem::find($id);

        if (Request::filled('_4th_action')) {
            $modem->createSyncPreset();

            return \Redirect::back();
        }

        $modem->restart_modem(false, Request::filled('_3rd_action'));

        return \Redirect::back();
    }

    /**
     * Show minimum amount of information about modem status
     *
     * @return View
     */
    public function analysis($id, $api = false)
    {
        $modem = Modem::with('configfile')->find($id);

        if (! $modem) {
            return View::make('errors.generic', ['error' => '', 'message' => trans('view.error.specifyId')]);
        }

        $data = $modem->getAnalysisBaseData($api);

        if ($api) {
            return response()->v0ApiReply($data, true);
        }

        return View::make('provbase::Modem.analysis', $this->compact_prep_view($data));
    }

    /**
     * Returns view of cpe analysis page
     */
    public function cpeAnalysis($id)
    {
        $ping = $lease = $log = $dash = $cpeMac = null;
        $modem = Modem::with('endpoints')->find($id);
        $type = 'CPE';
        $modem_mac = strtolower($modem->mac);
        $modem->help = 'cpe_analysis';

        // Lease
        $dhcpd_mac = implode(':', array_map(function ($byte) {
            if ($byte == '00') {
                return '0';
            }

            return ltrim($byte, '0');
        }, explode(':', $modem_mac)));

        $ep = $modem->endpoints->first();
        if ($ep?->fixed_ip && $ep?->ip) {
            $lease = $this->_fake_lease($modem, $ep);
        } else {
            $lease['text'] = Modem::searchLease("billing subclass \".*\" \"$dhcpd_mac\";");
            $lease = Modem::validateLease($lease, $type);
        }

        /// get MAC of CPE first
        $str = getSyslogEntries($modem_mac, '| grep CPE | tail -n 1 | tac');

        if ($str == []) {
            $mac = $modem_mac;
            $mac[0] = ' ';
            $mac = trim($mac);
            $mac_bug = true;
            $str = getSyslogEntries($mac, '| grep CPE | tail -n 1 | tac');

            if (! $str && $lease['text']) {
                // get cpe mac addr from lease - first option tolerates small structural changes in dhcpd.leases and assures that it's a mac address
                preg_match_all('/(?:[0-9a-fA-F]{2}[:]?){6}/', substr($lease['text'][0], strpos($lease['text'][0], 'hardware ethernet'), 40), $cpeMac);
            }
        }

        if (isset($str[0])) {
            if (isset($mac_bug)) {
                preg_match_all('/([0-9a-fA-F][:]){1}(?:[0-9a-fA-F]{2}[:]?){5}/', $str[0], $cpeMac);
            } else {
                preg_match_all('/(?:[0-9a-fA-F]{2}[:]?){6}/', $str[0], $cpeMac);
            }
        }

        if (isset($cpeMac[0][0])) {
            $cpeMac = $cpeMac[0][0];
            $log = getSyslogEntries($cpeMac, '| tail -n 20 | tac');
            $this->addIPv6LeaseInfo($cpeMac, $lease);
        }

        // Ping
        if (isset($lease['text'][0])) {
            // get ip first
            preg_match_all('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $lease['text'][0], $ip);
            if (isset($ip[0][0])) {
                $ip = $ip[0][0];
                exec('sudo ping -c3 -i0 -w1 '.$ip, $ping);

                exec("dig -x $ip +short", $fqdns);
                foreach ($fqdns as $fqdn) {
                    $dash .= "Hostname: $fqdn<br>";
                    exec("dig $fqdn ptr +short", $ptrs);
                    foreach ($ptrs as $ptr) {
                        $dash .= "Hostname: $ptr<br>";
                    }
                }
            }
        }
        if (is_array($ping) && count(array_keys($ping)) <= 7) {
            $ping = null;
            if ($lease['state'] == 'green') {
                $ping[0] = trans('messages.cpe_not_reachable');
            }
        }

        $tabs = $modem->analysisTabs();
        $view_header = 'Provmon-CPE';

        return View::make('provbase::Modem.cpeAnalysis', $this->compact_prep_view(compact('modem', 'ping', 'type', 'tabs', 'lease', 'log', 'dash', 'view_header')));
    }

    /**
     * Returns view of mta analysis page
     *
     * Note: This is never called if ProvVoip Module is not active
     */
    public function mtaAnalysis($id)
    {
        $ping = $lease = $log = $dash = $realtime = $configfile = null;
        $modem = Modem::with('mtas')->find($id);
        $type = 'MTA';
        $modem->help = 'mta_analysis';

        $mtas = $modem->mtas;       // Note: we should use one-to-one relationship here
        if (isset($mtas[0])) {
            $mta = $mtas[0];
        } else {
            goto end;
        }

        // Ping
        $hostname = $mta->hostname.'.'.ProvBase::first()->domain_name;

        exec('sudo ping -c3 -i0 -w1 '.$hostname, $ping);
        if (count(array_keys($ping)) <= 7) {
            $ping = null;
        }

        $lease['text'] = Modem::searchLease("mta-$mta->id.");
        $lease = Modem::validateLease($lease, $type);

        $configfile = Modem::getConfigfileText("/tftpboot/mta/$mta->hostname");

        // log
        $ip = gethostbyname($mta->hostname);
        $ip = $mta->hostname == $ip ? null : $ip;
        $mac = strtolower($mta->mac);
        $search = $ip ? "$mac|$mta->hostname|$ip " : "$mac|$mta->hostname";
        $log = getSyslogEntries($search, '| tail -n 25  | tac');

        end:

        $tabs = $modem->analysisTabs();
        $view_header = 'Provmon-MTA';

        return View::make('provbase::Modem.cpeAnalysis', $this->compact_prep_view(compact('modem', 'ping', 'type', 'tabs', 'lease', 'log', 'dash', 'realtime', 'configfile', 'view_header')));
    }

    /**
     * Add IPv6 leases of CPE to lease array
     *
     * colorized by expiry and lifetime (red: expired, yellow: half lifetime passed, green: less than half lifetime passed)
     */
    private function addIPv6LeaseInfo($cpeMac, &$lease)
    {
        if (! $cpeMac) {
            return;
        }

        $con = \DB::connection('pgsql-kea');
        $leaseQuery = $con->table('lease6');

        if ($con->getConfig('driver') == 'pgsql') {
            $leaseQuery->whereRaw("encode(hwaddr, 'hex') = '".strtolower(str_replace(':', '', $cpeMac))."'");
        } else {
            // MySql
            $leaseQuery->whereRaw('hex(hwaddr) = "'.strtoupper(str_replace(':', '', $cpeMac)).'"');
        }

        $leases = $leaseQuery->get();

        foreach ($leases as $lease6) {
            $lease6->hwaddr = $cpeMac;

            $lease6->bsclass = 'success';
            if (strtotime($lease6->expire) < time()) {
                $lease6->bsclass = 'danger';
            } elseif (strtotime($lease6->expire) - $lease6->valid_lifetime / 2 < time()) {
                $lease6->bsclass = 'warning';
            }
        }

        $lease['ipv6'] = $leases;
    }

    /**
     * Flood ping
     *
     * NOTE:
     * --- add /etc/sudoers.d/nms-nmsprime ---
     * Defaults:apache        !requiretty
     * apache  ALL=(root) NOPASSWD: /usr/bin/ping
     * --- /etc/sudoers.d/nms-nmsprime ---
     *
     * @param hostname  the host to send a flood ping
     * @return flood ping exec result
     */
    public static function floodPing(Modem $modem)
    {
        if (! $task = request('task')) {
            return;
        }

        $hostname = escapeshellarg($modem->hostname);

        match (intval($task)) {
            1 => exec("sudo ping -c500 -f $hostname 2>&1", $fp, $ret),
            2 => exec("sudo ping -c1000 -s736 -f $hostname 2>&1", $fp, $ret),
            3 => exec("sudo ping -c2500 -f $hostname 2>&1", $fp, $ret),
            4 => exec("sudo ping -c2500 -s1472 -f $hostname 2>&1", $fp, $ret),
        };

        // remove the flood ping line "....." from result
        if ($ret == 0) {
            unset($fp[1]);
        }

        return $fp;
    }

    /**
     * Send output of Ping as Stream
     * in blade files using Server Sent Events
     *
     * @param string
     * @return response
     *
     * @author Roy Schneider
     */
    public static function realtimePing($ip)
    {
        $cmd = 'ping -c 5 ';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $cmd .= '-6 ';
        }

        return ProvBaseController::serverSentEvents($cmd.escapeshellarg($ip));
    }

    private function _fake_lease($modem, $ep)
    {
        $lease['state'] = 'green';
        $lease['forecast'] = trans('messages.cpe_fake_lease').'<br />';
        $lease['text'][0] = "lease $ep->ip {<br />".
            "starts 3 $ep->updated_at;<br />".
            'binding state active;<br />'.
            'next binding state active;<br />'.
            'rewind binding state active;<br />'.
            "billing subclass \"Client\" $modem->mac;<br />".
            "hardware ethernet $ep->mac;<br />".
            "set ip = \"$ep->ip\";<br />".
            "set hw_mac = \"$ep->mac\";<br />".
            "set cm_mac = \"$modem->mac\";<br />".
            "option agent.remote-id $modem->mac;<br />".
            'option agent.unknown-9 0:0:11:8b:6:1:4:1:2:3:0;<br />'.
            '}<br />';

        return $lease;
    }
}
