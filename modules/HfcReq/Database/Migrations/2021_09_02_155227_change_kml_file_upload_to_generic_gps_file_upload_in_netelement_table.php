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

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class ChangeKmlFileUploadToGenericGpsFileUploadInNetelementTable extends BaseMigration
{
    public $migrationScope = 'system';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $filesystem = new Filesystem();
        $filesystem->moveDirectory(storage_path('app/data/hfcbase/kml_static'), storage_path('app/data/hfcbase/infrastructureFiles'));

        // Make ERD files of "old" ERD publicly accessible
        Artisan::call('storage:link');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $filesystem = new Filesystem();
        $filesystem->moveDirectory(storage_path('app/data/hfcbase/infrastructureFiles'), storage_path('app/data/hfcbase/kml_static'));
    }
}
