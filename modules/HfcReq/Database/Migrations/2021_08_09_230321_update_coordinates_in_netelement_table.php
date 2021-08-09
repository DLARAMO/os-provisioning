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
use Modules\HfcReq\Entities\NetElement;

class UpdateCoordinatesInNetelementTable extends BaseMigration
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
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->decimal('lat', 9, 6)->nullable()->after('pos');
            $table->decimal('lng', 9, 6)->nullable()->after('pos');
        });

        foreach (NetElement::whereNotNull('pos')->get(['id', 'pos']) as $netelement) {
            $pos = explode(',', $netelement->pos);
            $netelement->lng = $pos[0];
            $netelement->lat = $pos[1];
            $netelement->save();
        }

        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropColumn('pos');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->tableName, function (Blueprint $table) {
            $table->string('pos');
        });

        foreach (NetElement::whereNotNull('lat')->get(['id', 'lat', 'lng']) as $netelement) {
            $netelement->pos = implode(',', [$netelement->lng, $netelement->lat]);
        }

        Schema::table($this->tableName, function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
}
