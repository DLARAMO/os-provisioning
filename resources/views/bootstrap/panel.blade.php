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
    if (isset($height) && !Str::endsWith($height, 'vh')) {
        $height = 'height: '.(($height == 'auto') ? '100%' : "$height%");
    }

    $overflow_y = isset($overflow) ? $overflow : 'auto';

    $display = isset($options['display']) ? 'display: '.$options['display'] : '';

    $dataSortId = isset($tab) ? $tab['name'].'-'.$view : ($i ?? 1);
    $attrExt = isset($handlePanelPosBy) && $handlePanelPosBy == 'nmsprime' ? '' : 'able';
?>

{{-- begin col-dyn --}}
@if(isset($md))
<div class="col-{{ $md }}">
@endif
    <div class="{{ isset($fillToContainerHeight) ? 'h-full' : '' }} {{ $classes ?? '' }} flex flex-col panel panel-inverse card-2 dark:shadow-none dark:border-none dark:p-2 dark:bg-slate-800" data-sort{{$attrExt}}-id="{{ $dataSortId }}">
        @include ('bootstrap.panel-header', ['view_header' => $view_header])
        <div class="flex flex-col text-gray-500 panel-body fader dark:bg-slate-900 dark:mx-2 {{ isset($fillToContainerHeight) ? 'flex-1' : '' }}" style="overflow-x: hidden; overflow-y:{{ $overflow_y }};{{ $height ?? '' }}; {{ $display }}">
            @yield($content)
        </div>
    </div>
@if(isset($md))
</div>
@endif
