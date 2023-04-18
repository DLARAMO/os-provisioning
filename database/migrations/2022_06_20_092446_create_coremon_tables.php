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

use Database\Migrations\BaseMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\HfcReq\Entities\NetElement;

class CreateCoremonTables extends BaseMigration
{
    public $migrationScope = 'database';

    protected $tables = [
        'ccap',
        'dpa',
        'hubsite',
        'market',
        'ncs',
        'net',
        'rpa',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->tables as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $this->up_table_generic($table);
                $table->integer('netelement_id');
            });
        }

        $ids = NetElement::join('netelementtype as t', 'netelement.netelementtype_id', '=', 't.id')
            ->where('t.base_type_id', 18)->pluck('netelement.id');

        foreach ($ids as $id) {
            Ccap::create(['netelement_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->tables as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
}