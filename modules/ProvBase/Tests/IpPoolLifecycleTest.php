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

namespace Modules\ProvBase\Tests;

use Modules\ProvBase\Http\Controllers\IpPoolController;

class IpPoolLifecycleTest extends \BaseLifecycleTest
{
    // modem can only be created from NetGw.edit
    protected $create_from_model_context = '\Modules\ProvBase\Entities\NetGw';

    // create form is filled with initial data from IpPoolController
    protected $creating_empty_should_fail = false;

    // do not create using fake data – TODO: this needs rewriting of the seeder to match
    // the models validation rules
    protected $tests_to_be_excluded = ['testCreateWithFakeData', 'testCreateTwiceUsingTheSameData'];

    // fields to be used in update test
    protected $update_fields = [
        'dns2_ip',
        'dns3_ip',
        'description',
    ];
}