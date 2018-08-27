<?php

namespace Modules\ProvVoip\Http\Controllers;

use Bouncer;
use Session;
use Modules\ProvVoip\Entities\EkpCode;
use Modules\ProvVoip\Entities\TRCClass;
use Modules\ProvVoip\Entities\CarrierCode;
use Modules\ProvVoip\Entities\Phonenumber;
use Modules\ProvVoip\Entities\PhonenumberManagement;

class PhonenumberManagementController extends \BaseController
{
    /**
     * if set to true a create button on index view is available - set to true in BaseController as standard
     */
    protected $index_create_allowed = false;

    /**
     * Extend create: check if a phonenumber exists to attach this management to
     *
     * @author Patrick Reichel
     */
    public function create()
    {
        if ((! \Input::has('phonenumber_id')) ||
            ! (Phonenumber::find(\Input::get('phonenumber_id')))) {
            $this->edit_view_save_button = false;
            Session::push('tmp_error_above_form', 'Cannot create phonenumbermanagement – phonenumber ID missing or phonenumber not found');
        }

        return parent::create();
    }

    /**
     * Add functionality to clear envia TEL reference for this phonenumber(management)
     *
     * @author Patrick Reichel
     */
    public function edit($id)
    {
        if (\Input::has('clear_envia_reference')) {
            if (\Module::collections()->has('ProvVoipEnvia')) {
                $mgmt = PhonenumberManagement::find($id);
                $mgmt->phonenumber->contract_external_id = null;
                $mgmt->phonenumber->save();
                Session::push('tmp_info_above_form', 'Removed envia TEL contract reference. This can be restored via „Get envia TEL contract reference“.');

                return \Redirect::back();
            }
        }

        return parent::edit($id);
    }

    /**
     * defines the formular fields for the edit and create view
     */
    public function view_form_fields($model = null)
    {
        // create
        if (! $model) {
            $model = new PhonenumberManagement;
        }

        // in most cases the subscriber is identical to contract partner ⇒ on create we prefill these values with data from contract
        if (! $model->exists) {
            if (
                (! \Input::has('phonenumber_id'))
                ||
                ! ($phonenumber = Phonenumber::find(\Input::get('phonenumber_id')))
            ) {
                return [];
            }
            $contract = $phonenumber->mta->modem->contract;

            $init_values = [
                'subscriber_company' => $contract->company,
                'subscriber_department' => $contract->department,
                'subscriber_salutation' => $contract->salutation,
                'subscriber_academic_degree' => $contract->academic_degree,
                'subscriber_firstname' => $contract->firstname,
                'subscriber_lastname' => $contract->lastname,
                'subscriber_street' => $contract->street,
                'subscriber_house_number' => $contract->house_number,
                'subscriber_zip' => $contract->zip,
                'subscriber_city' => $contract->city,
                'subscriber_district' => $contract->district,
            ];
        }
        // edit
        else {
            $init_values = [];
        }

        // set hide flags for some entries depending on the current state
        $hide_flags = [
            'external_activation_date' => '1',
            'deactivation_date' => '1',
            'external_deactivation_date' => '1',
            'porting_out' => '1',
        ];

        // if activation date is set: show information about external activation and allow deactivation
        if (! is_null($model->activation_date)) {
            $hide_flags['external_activation_date'] = '0';
            $hide_flags['deactivation_date'] = '0';
            $hide_flags['porting_out'] = '0';
        }

        // if deactivation is set: show information about external deactivation
        if (! is_null($model->deactivation_date)) {
            $hide_flags['external_deactivation_date'] = '0';
        }

        // show autogenerated marker only if set
        if (boolval($model->autogenerated)) {
            $hide_flags['autogenerated'] = '0';
        } else {
            $hide_flags['autogenerated'] = '1';
        }

        // help text for carrier/ekp settings
        if (\Module::collections()->has('ProvVoipEnvia')) {
            $trc_help = trans('helper.PhonenumberManagement_TRCWithEnvia');
            $carrier_in_help = trans('helper.PhonenumberManagement_CarrierInWithEnvia');
            $ekp_in_help = trans('helper.PhonenumberManagement_EkpInWithEnvia');
        } else {
            $trc_help = trans('helper.PhonenumberManagement_TRC');
            $carrier_in_help = trans('helper.PhonenumberManagement_CarrierIn');
            $ekp_in_help = trans('helper.PhonenumberManagement_EkpIn');
        }

        // label has to be the same like column in sql table
        $ret_tmp = [
            [
                'form_type' => 'select',
                'name' => 'phonenumber_id',
                'description' => 'Phonenumber',
                'value' => $model->html_list($model->phonenumber(),
                'id'),
                'hidden' => '1',
            ],
            [
                'form_type' => 'select',
                'name' => 'trcclass',
                'description' => 'TRC class',
                'value' => TRCClass::trcclass_list_for_form_select(),
                'help' => $trc_help,
                'space' => '1',
            ],
            [
                'form_type' => 'text',
                'name' => 'activation_date',
                'description' => 'Activation date',
            ],
            [
                'form_type' => 'text',
                'name' => 'external_activation_date',
                'description' => 'External activation date',
                'options' => ['readonly'],
                'hidden' => $hide_flags['external_activation_date'],
            ],
            [
                'form_type' => 'checkbox',
                'name' => 'porting_in',
                'description' => 'Incoming porting',
            ],
            [
                'form_type' => 'select',
                'name' => 'carrier_in',
                'description' => 'Carrier in',
                'value' => CarrierCode::carrier_list_for_form_select(false),
                'help' => $carrier_in_help,
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'select',
                'name' => 'ekp_in',
                'description' => 'EKP in',
                'value' => EkpCode::ekp_list_for_form_select(false),
                'help' => $ekp_in_help,
                'checkbox' => 'show_on_porting_in',
            ],

            // preset subscriber data => this comes from model
            [
                'form_type' => 'text',
                'name' => 'subscriber_company',
                'description' => 'Subscriber company',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_department',
                'description' => 'Subscriber department',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'select',
                'name' => 'subscriber_salutation',
                'description' => 'Subscriber salutation',
                'value' => $model->get_salutation_options(),
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'select',
                'name' => 'subscriber_academic_degree',
                'description' => 'Subscriber academic degree',
                'value' => $model->get_academic_degree_options(),
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_firstname',
                'description' => 'Subscriber firstname',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_lastname',
                'description' => 'Subscriber lastname',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_street',
                'description' => 'Subscriber street',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_house_number',
                'description' => 'Subscriber house number',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_zip',
                'description' => 'Subscriber zipcode',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_city',
                'description' => 'Subscriber city',
                'checkbox' => 'show_on_porting_in',
            ],
            [
                'form_type' => 'text',
                'name' => 'subscriber_district',
                'description' => 'Subscriber district',
                'space' => '1',
                'checkbox' => 'show_on_porting_in',
            ],

            [
                'form_type' => 'text',
                'name' => 'deactivation_date',
                'description' => 'Termination date',
                'hidden' => $hide_flags['deactivation_date'],
            ],
            [
                'form_type' => 'text',
                'name' => 'external_deactivation_date',
                'description' => 'External deactivation date',
                'options' => ['readonly'],
                'hidden' => $hide_flags['external_deactivation_date'],
            ],
            [
                'form_type' => 'checkbox',
                'name' => 'porting_out',
                'description' => 'Outgoing porting',
                'hidden' => $hide_flags['porting_out'],
            ],
            [
                'form_type' => 'select',
                'name' => 'carrier_out',
                'description' => 'Carrier out',
                'value' => CarrierCode::carrier_list_for_form_select(true),
                'checkbox' => 'show_on_porting_out',
                'space' => '1',
            ],
            [
                'form_type' => 'checkbox',
                'name' => 'autogenerated',
                'description' => 'Automatically generated',
                'help' => trans('helper.PhonenumberManagement_Autogenerated'),
                'hidden' => $hide_flags['autogenerated'],
            ],
        ];

        // add init values if set
        $ret = [];
        foreach ($ret_tmp as $elem) {
            if (array_key_exists($elem['name'], $init_values)) {
                $elem['init_value'] = $init_values[$elem['name']];
            }
            array_push($ret, $elem);
        }

        return $ret;
    }

    /**
     * Get all management jobs for envia TEL
     *
     * @author Patrick Reichel
     * @param $phonenumbermanagement current phonenumbermanagement object
     * @return array containing linktexts and URLs to perform actions against REST API
     */
    public static function _get_envia_management_jobs($phonenumbermanagement)
    {
        if (Bouncer::cannot('view', 'Modules\ProvVoipEnvia\Entities\ProvVoipEnvia')) {
            return;
        }

        $provvoipenvia = new \Modules\ProvVoipEnvia\Entities\ProvVoipEnvia();

        return $provvoipenvia->get_jobs_for_view($phonenumbermanagement, 'phonenumbermanagement');
    }

    /**
     * Overwrite BaseController method => not required dates should be set to null if not set
     * Otherwise we get entries like 0000-00-00, which cause crashes on validation rules in case of update
     *
     * @author Patrick Reichel
     */
    protected function prepare_input($data)
    {
        $data = parent::prepare_input($data);

        $nullable_fields = [
            'activation_date',
            'deactivation_date',
        ];
        $data = $this->_nullify_fields($data, $nullable_fields);

        return $data;
    }
}
