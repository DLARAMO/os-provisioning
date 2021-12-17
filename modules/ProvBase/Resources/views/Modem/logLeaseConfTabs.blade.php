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
<div class="tab-pane fade in" id="log">
    @if ($log)
        <font color="green"><b>Modem Logfile</b></font><br>
        @foreach ($log as $line)
            <table>
                <tr>
                    <td>
                     <font color="grey">{{$line}}</font>
                    </td>
                </tr>
            </table>
        @endforeach
    @else
        <font color="red">{{ trans('messages.modem_log_error') }}</font>
    @endif
</div>
<div class="tab-pane fade in" id="lease">
    @if ($lease)
        <font color="{{$lease['state']}}"><b>{{$lease['forecast']}}</b></font><br>
        @foreach ($lease['text'] as $line)
            <table>
                <tr>
                    <td>
                        <font color="grey">{!!$line!!}</font>
                    </td>
                </tr>
            </table>
        @endforeach
    @else
        <font color="red">{{ trans('messages.modem_lease_error')}}</font>
    @endif
</div>
<div class="tab-pane fade in" id="configfile">
    @if ($configfile)
        @if ($modem->configfile->device != 'tr069')
            <font color="green"><b>Modem Configfile ({{$configfile['mtime']}})</b></font><br>
            @if (isset($configfile['warn']))
                <font color="red"><b>{{$configfile['warn']}}</b></font><br>
            @endif
        @else
            <?php
                $blade_type = 'form';
            ?>
            @include('Generic.above_infos')
            <form v-on:submit.prevent="updateGenieTasks">
                <script type="text/x-template" id="select2-template">
                    <select>
                        <slot></slot>
                    </select>
                </script>
                <div class="row d-flex">
                    <div style="flex:1;">
                        <select2 v-model="selectedTask" :initial-value="taskOptions[0].task">
                            <template v-for="option in taskOptions">
                                <option :value="option.task" v-text="option.name"></option>
                            </template>
                        </select2>
                    </div>
                    <button type="submit" class="btn btn-danger" style="margin-left: 10px; margin-bottom: 10px;">{{ trans('view.Button_Submit') }}</button>
                </div>
            </form>
        @endif
        @foreach ($configfile['text'] as $line)
            <table>
                <tr>
                    <td>
                     <font color="grey">{!! $line !!}</font>
                    </td>
                </tr>
            </table>
        @endforeach
    @else
        <font color="red">{{ trans('messages.modem_configfile_error')}}</font>
    @endif
</div>

<div class="tab-pane fade in" id="eventlog">
    @if ($eventlog)
        <div class="table-responsive">
            <table class="table streamtable table-bordered" width="100%">
                <thead>
                    <tr class='active'>
                        <th width="20px"></th>
                        @foreach (array_shift($eventlog) as $col_name)
                            <th class='text-center'>{{$col_name}}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach ($eventlog as $row)
                    <tr class = "{{$row[2]}}">
                        <td></td>
                        @foreach ($row as $idx => $data)
                            @if($idx != 2)
                                <td><font>{{$data}}</font></td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <font color="red">{{ trans('messages.modem_eventlog_error')}}</font>
    @endif
</div>
