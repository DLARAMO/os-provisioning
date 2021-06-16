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

class AddModemProvBaseRelationTableIndices extends BaseMigration
{
    public $migrationScope = 'database';

    protected $tableName = 'modem';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->addIndex('qos_id');
        $this->addIndex('contract_id');
        $this->addIndex('configfile_id');
        $this->addIndex('netelement_id');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropIndex('modem_qos_id_index');
            $table->dropIndex('modem_contract_id_index');
            $table->dropIndex('modem_configfile_id_index');
            $table->dropIndex('modem_netelement_id_index');
        });
    }
}
