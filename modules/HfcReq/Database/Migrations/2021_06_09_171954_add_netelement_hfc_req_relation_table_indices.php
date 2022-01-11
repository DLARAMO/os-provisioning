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

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class AddNetelementHfcReqRelationTableIndices extends BaseMigration
{
    public $migrationScope = 'database';

    protected $tableName = 'netelement';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->addIndex('parent_id');
        $this->addIndex('netelementtype_id');
        $this->addIndex('netgw_id');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropIndex('netelement_parent_id_index');
            $table->dropIndex('netelement_netelementtype_id_index');
            $table->dropIndex('netelement_netgw_id_index');
        });
    }
}