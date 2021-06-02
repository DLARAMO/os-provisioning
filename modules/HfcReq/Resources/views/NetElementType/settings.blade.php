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
{!! Form::open(['route' => ['NetElementType.settings', $view_var->id], 'method' => 'post']) !!}

    <div class="col-md-12">
    <div class="form-group row">
        {!! Form::label('param_id', 'Choose Parameter') !!}
        {!! Form::select('param_id[]', $list, null , ['multiple' => 'multiple']) !!}
    <br><br><br>
    </div></div>

    <div class="col-md-12">
    <div class="form-group row">
        {!! Form::label('html_frame', 'HTML Frame ID') !!}
        {!! Form::text('html_frame') !!}
    </div></div>

    <div class="col-md-12">
    <div class="form-group row">
        {!! Form::label('html_id', 'Order ID') !!}
        {!! Form::text('html_id') !!}
    </div></div>

    {!! Form::submit('Set Value(s)') !!}

{!! Form::close() !!}
