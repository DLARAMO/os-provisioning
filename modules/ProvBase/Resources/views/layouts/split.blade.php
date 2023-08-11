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
?>
@extends ('Layout.split-nopanel')

@section('content_top')

    @include ('provbase::layouts.top')

@stop


@section ('content_left')

@if(Module::collections()->has('ProvMon'))
<div
    v-pre
    id="EnterPriseModemAnalysis"
    class="row"
    data-realtime-broadcasting="{!! route('ProvMon.realtimeBroadcasting', ['id' => $modem->id]) !!}"
    data-picture="{{ isset($picture) ? url($picture) : '' }}"
    data-genie-cmds='@json($genieCmds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)'
    data-modem-analysis='@json(trans('view.modemAnalysis'))'
    data-messages-entries="{{ trans('messages.Entries') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-modem-id="{{ $modem->id }}"
    data-route-modem-refresh-genie-object="{{ route('Modem.refreshGenieObject', $modem->id) }}"
    data-route-delete-poller-file="{{ route('ProvMon.deletePollerFile', $modem->id) }}"
    data-route-modem-genie-task="{{ route('Modem.genieTask', $modem->id) }}"
    data-messages-analysis-ping-in-progress="{{ trans('provmon::messages.analysis.pingInProgress') }}"
    data-route-modem-flood-ping="{{ route("Modem.floodPing", ["modem" => $modem->id]) }}"
    data-messages-please-wait="{{ trans('messages.pleaseWait') }}"
    data-route-create-spectrum="{{ route('ProvMon.createSpectrum', [$modem->id]) }}"
    data-messages-spectrum-processing="{{ trans('provmon::messages.spectrum.processing') }}"
    data-messages-no-spectrum="{{ trans('messages.noSpectrum') }}"
    data-i18ndt='{@include('datatables.lang', ['withoutTrailingComma' => true])}'
    data-channel="{{ \Modules\ProvMon\Events\NewRealtimeValues::getChannelName($modem->id) }}"
    data-realtime-update-interval="{{ \Modules\ProvMon\Entities\ProvMon::first()->realtime_update_interval }}"
    data-view-header="{!! isset($view_header) ? $view_header : 'undefined'!!}"
    data-iperf-channel="{{ \Modules\ProvMon\Events\ArrisIperfProgress::getChannelName($modem->id) }}"
    data-iperf-route="{{ route('Modem.arrisIperf', $modem->id) }}"
    data-iperf-max-stream="{{ \Modules\ProvMon\Entities\ProvMon::first()->iperf_max_stream }}"
    data-start-measurement="{{ trans('view.modemAnalysis.startMeasurement') }}"
>

@else
<div
    v-pre
    id="OpenSourceModemAnalysis"
    class="row"
    data-modem-analysis-floodping-lowLoad="{{ trans('view.modemAnalysis.floodping.lowLoad') }}"
    data-modem-analysis-floodping-averageLoad="{{ trans('view.modemAnalysis.floodping.averageLoad') }}"
    data-modem-analysis-floodping-bigLoad="{{ trans('view.modemAnalysis.floodping.bigLoad') }}"
    data-modem-analysis-floodping-hugeLoad="{{ trans('view.modemAnalysis.floodping.hugeLoad') }}"
    data-messages-analysis-ping-in-progress="{{ trans('provmon::messages.analysis.pingInProgress') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-route-modem-flood-ping="{{ route("Modem.floodPing", ["modem" => $modem->id]) }}"
>
@endif

    <div class="col-md-7 ui-sortable">
        @include ('bootstrap.panel', array ('content' => 'content_dash', 'view_header' => 'Dashboard', 'i' => 1))
        @if (isset($realtime))
            @include ('bootstrap.panel', array ('content' => 'content_realtime', 'view_header' => \App\Http\Controllers\BaseViewController::translate_label('Real Time Values'), 'i' => 2))
        @endif
        @if (isset($hostId))
            @include ('bootstrap.panel', array ('content' => 'content_cacti', 'view_header' => 'Monitoring', 'i' => 3))
        @endif
    </div>

    <div class="col-md-5 ui-sortable">
        @php
        $panelHeaderPing = '<ul class="nav nav-pills" id="ping-tab">
                <li role="presentation"><a href="#ping-test" data-toggle="pill">Default Ping</a></li>
                <li role="presentation"><a href="#flood-ping" data-toggle="pill">Flood-Ping</a></li>';
        if (config('provmon.arrisIperf')['enable'] && \Str::contains($modem->model, 'TG3442S')) {
            $panelHeaderPing .= '<li role="presentation"><a href="#arris-iperf" data-toggle="pill">Iperf</a></li>';
        }
        $panelHeaderPing .= '</ul>';
        @endphp

        @include ('bootstrap.panel', array ('content' => 'content_ping', 'view_header' => $panelHeaderPing, 'i' => 4))

        @php
            $panelHeader = '<ul class="nav nav-pills" id="loglease">';
            foreach ($pills as $pill) {
                if (${$pill}) {
                    $panelHeader .= "<li role=\"presentation\"><a href=\"#$pill\" data-toggle=\"pill\">".ucfirst($pill).'</a></li>';
                }
            }

            if (isset($radius)) {
                foreach ($radius as $table => $data) {
                    if ($table == 'DT_Current Session') {
                        continue;
                    }

                    $panelHeader .= "<li role=\"presentation\"><a href=\"#$table\" data-toggle=\"pill\">".trans("view.modemAnalysis.$table").'</a></li>';
                }
            }

            $panelHeader .= '</ul>';
        @endphp

        @include ('bootstrap.panel', array ('content' => 'content_log', 'view_header' => $panelHeader, 'i' => 5))

        @if (\Module::collections()->has('HfcCustomer'))
            @include ('bootstrap.panel', array ('content' => 'content_proximity_search', 'view_header' => trans('messages.proximity'), 'i' => 6))
        @endif
    </div>

    @if (Module::collections()->has('ProvMon') && ! $modem->isPPP())
        <div class="col-md-12 ui-sortable">
            @include ('bootstrap.panel', array ('content' => 'spectrum-analysis', 'view_header' => trans('messages.spectrum'),  'i' => 7))
        </div>
    @endif

    </div>

@stop
