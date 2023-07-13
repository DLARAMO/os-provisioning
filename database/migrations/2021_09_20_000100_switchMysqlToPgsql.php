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
use Nwidart\Modules\Facades\Module;

class SwitchMysqltoPgsql extends BaseMigration
{
    public $migrationScope = 'system';
    public $databases = [];

    /**
     * Run the migrations.
     *
     * TODO: Remove Migration on next release - fresh installations will already be rolled out with Pgsql
     *
     * @return void
     */
    public function up()
    {
        // Don't run migration on fresh installation (mysql nmsprime DB does not exist)
        $mysqlRootConf = DB::connection('mysql-root')->getConfig();
        $ret = system('mysql -u '.$mysqlRootConf['username'].' -p'.$mysqlRootConf['password'].' --exec="SHOW DATABASES LIKE \'nmsprime\'"');

        if (! $ret) {
            // Fresh installation - mysql nmsprime DB doesn't exist
            return;
        }

        // Check if postgres and pgloader is installed before starting any action
        exec('systemctl status postgresql-13.service', $out, $ret);
        if ($ret) {
            throw new Exception('Postgresql-13 is missing.');
        }

        if (! system('which pgloader')) {
            throw new Exception('Pgloader is not installed. Install via: yum install pgloader');
        }

        $this->convertNmsprimeDbs();
        $this->fixNmsprimeDb();
        $this->switchKeaDb();
        $this->changeConfig();

        // Icinga is done via icinga-module-director RPM - see SPEC file

        DB::connection('mysql-root')->statement('DROP USER psqlconverter');
    }

    public function getDbsConf($db = '')
    {
        if (! $this->databases) {
            $this->databases = [
                'nmsprime' => [
                    'user' => DB::getConfig('username'),
                    'password' => DB::getConfig('password'),
                    'schema' => DB::getConfig('schema') ?: DB::getConfig('search_path'),
                ],
            ];

            if (Module::collections()->has('Ccc')) {
                $this->databases['nmsprime_ccc'] = [
                    'user' => DB::connection('pgsql-ccc')->getConfig('username'),
                    'password' => DB::connection('pgsql-ccc')->getConfig('password'),
                    'schema' => DB::connection('pgsql-ccc')->getConfig('schema') ?: DB::connection('pgsql-ccc')->getConfig('search_path'),
                ];
            }
        }

        if (! $db) {
            return $this->databases;
        }

        return $this->databases[$db];
    }

    /**
     * Convert MySQL NMSPrime DBs to PostgreSQL - add users and permissions
     */
    private function convertNmsprimeDbs()
    {
        $dbs = ['nmsprime'];

        if (Module::collections()->has('Ccc')) {
            $dbs[] = 'nmsprime_ccc';
        }

        foreach ($dbs as $db) {
            $conf = $this->getDbsConf($db);
            $user = $conf['user'];
            $schema = $conf['schema'];

            if ($db == 'nmsprime') {
                // DB already exists - Just add extension for indexing and quick search with % at start & end
                // Schema::createExtension('pg_trgm'); - only with https://github.com/tpetry/laravel-postgresql-enhanced
                system("sudo -u postgres /usr/pgsql-13/bin/psql -d $db -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'");
            } else {
                system("sudo -u postgres /usr/pgsql-13/bin/psql -c 'CREATE DATABASE $db'");
                echo "$db\n";

                // Convert MySQL DB to PostgreSQL
                exec("sudo -u postgres pgloader mysql://psqlconverter@localhost/$db postgresql:///$db", $ret);

                echo implode(PHP_EOL, $ret)."\n";
                $ret = [];

                // Create user
                system("sudo -u postgres /usr/pgsql-13/bin/psql -c \"CREATE USER $user PASSWORD '".$conf['password'].'\'"');
                echo "$user\n";

                // Move nmsprime_ccc table to schema public
                system("sudo -u postgres /usr/pgsql-13/bin/psql nmsprime_ccc -c 'ALTER TABLE nmsprime_ccc.cccauthuser SET SCHEMA public'");
            }

            // Set search path of postgres user to mainly used schema to not be required to always specify schema in queries
            system("sudo -u postgres /usr/pgsql-13/bin/psql $db -c \"ALTER ROLE postgres in DATABASE $db set search_path to '$schema'\"");
            // Move tables to public schema
            // $tables = [];
            // exec("sudo -u postgres /usr/pgsql-13/bin/psql $db -t -c \"SELECT table_name FROM information_schema.tables WHERE table_schema = 'nmsprime'\"", $tables);
            // system("sudo -u postgres /usr/pgsql-13/bin/psql $db -c 'ALTER TABLE $db.$table SET SCHEMA public'");

            // Grant permissions
            system("sudo -u postgres /usr/pgsql-13/bin/psql -d $db -c '
                GRANT USAGE ON SCHEMA $schema TO $user;
                GRANT ALL PRIVILEGES ON ALL Tables in schema $schema TO $user;
                GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA $schema TO $user;
                '");

            system("for tbl in `sudo -u postgres /usr/pgsql-13/bin/psql -qAt -c \"select tablename from pg_tables where schemaname = '$schema';\" $db`;
                do sudo -u postgres /usr/pgsql-13/bin/psql $db -c \"alter table $schema.".'$tbl'." owner to $user\"; done");
        }
    }

    /**
     * NMSPrime DB specific adaptions
     */
    private function fixNmsprimeDb()
    {
        if (Schema::hasTable('oid') && Module::collections()->has('HfcSnmp')) {
            \Modules\HfcSnmp\Entities\OID::where('type', 'u')->update(['type' => null]);
        }

        // IPs are stored as inet type and compared not with INET_ATON anymore
        // Generally we should use type cidr Using net::cidr for first column, but this can result in errors on insert and it's harder to validate - Possible validation could be: https://www.phpclasses.org/browse/file/70429.html
        if (Schema::hasTable('ippool')) {
            DB::table('ippool')->where('netmask', '')->orWhere('ip_pool_start', '')->orWhere('ip_pool_end', '')->orWhere('router_ip', '')->delete();

            DB::statement('ALTER table ippool
                ALTER COLUMN net type inet USING net::inet,
                ALTER COLUMN ip_pool_start type inet USING ip_pool_start::inet,
                ALTER COLUMN ip_pool_end type inet USING ip_pool_end::inet,
                ALTER COLUMN router_ip type inet USING router_ip::inet
            ');

            DB::statement('ALTER table modem RENAME COLUMN ipv4 to ipv4_tmp');
            DB::statement('ALTER table modem add column ipv4 inet');
            DB::raw('UPDATE modem set ipv4 = \'0.0.0.0\'::inet + ipv4_tmp');
            DB::statement('ALTER table modem drop column ipv4_tmp;');
            // ALTER COLUMN broadcast_ip type inet USING broadcast_ip::inet,

            foreach (\Modules\ProvBase\Entities\IpPool::withTrashed()->get() as $ippool) {
                \Modules\ProvBase\Entities\IpPool::where('id', $ippool->id)->update(['net' => $ippool->net.$ippool->maskToCidr()]);
            }

            DB::statement('ALTER table ippool drop column netmask;');
        }

        DB::statement('ALTER table global_config RENAME COLUMN passwordresetinterval to password_reset_interval');
        DB::statement('ALTER table global_config RENAME COLUMN isallnetssidebarenabled to is_all_nets_sidebar_enabled');

        if (Schema::hasTable('ticketsystem')) {
            DB::statement('ALTER table ticketsystem RENAME COLUMN noreplymail to noreply_mail');
            DB::statement('ALTER table ticketsystem RENAME COLUMN noreplyname to noreply_name');
            DB::statement('ALTER table ticketsystem RENAME COLUMN opentickets to open_tickets');
        }
    }

    /**
     * Use Postgres for Kea DHCP - give nmsprime user all permissions
     */
    private function switchKeaDb()
    {
        $user = 'kea';
        $psw = \Str::random(12);
        $envPath = '/etc/nmsprime/env/provbase.env';

        exec("grep 'KEA_DB_PASSWORD' $envPath", $exists);

        if ($exists) {
            system("sed -i 's/^KEA_DB_PASSWORD=.*$/KEA_DB_PASSWORD=$psw/' /etc/nmsprime/env/provbase.env");
        } else {
            file_put_contents($envPath, "# Configuration for database used by kea\nKEA_DB_HOST=localhost\n
KEA_DB_DATABASE=kea\nKEA_DB_USERNAME=kea\nKEA_DB_PASSWORD=$psw", FILE_APPEND);
        }

        Config::set('database.connections.pgsql-kea.password', $psw);
        DB::reconnect('pgsql-kea');

        system('sudo -u postgres /usr/pgsql-13/bin/psql -c "CREATE DATABASE kea"');
        echo "kea\n";
        system("sudo -u postgres /usr/pgsql-13/bin/psql -d kea -c \"CREATE USER $user PASSWORD '$psw';\"");

        // (1) Initialise new kea DB for Pgsql (contains less tables than mysql schema)
        system("/usr/sbin/kea-admin db-init pgsql -u $user -p $psw -n kea");

        // Add permissions
        system("sudo -u postgres /usr/pgsql-13/bin/psql kea -c \"
            GRANT ALL PRIVILEGES ON ALL Tables in schema public TO $user;
            GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $user;
        \"");

        system('sudo -u postgres /usr/pgsql-13/bin/psql kea -c "ALTER ROLE postgres set search_path to \'public\'"');

        echo "Change owner of kea DB tables to kea\n";

        system("for tbl in `sudo -u postgres /usr/pgsql-13/bin/psql kea -qAt -c \"select tablename from pg_tables where schemaname = 'public';\"`;
            do sudo -u postgres /usr/pgsql-13/bin/psql kea -c \"alter table ".'$tbl'.' owner to '.$user.'"; done');

        // Transfer leases
        foreach (DB::connection('mysql')->table('kea.lease6')->get() as $lease) {
            DB::connection('pgsql-kea')->table('lease6')->insert((array) $lease);
        }

        system('sed -i \'s/"type": "mysql"/"type": "postgresql"/\' /etc/kea/dhcp6-nmsprime.conf');
        system('systemctl restart kea-dhcp6');
    }

    private function changeConfig()
    {
        system("sed -i 's/QUEUE_DRIVER_DATABASE_CONNECTION=mysql/QUEUE_DRIVER_DATABASE_CONNECTION=pgsql/' /etc/nmsprime/env/global.env");

        if (Module::collections()->has('ProvMon')) {
            system("sed -i 's/ROOT_DB_DATABASE=nmsprime/ROOT_DB_DATABASE=cacti/' /etc/nmsprime/env/root.env");
        }

        \Artisan::call('config:cache');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        system('sed -i \'s/"type": "postgresql"/"type": "mysql"/\' /etc/kea/dhcp6-nmsprime.conf');
        system('systemctl restart kea-dhcp6');

        // Remove kea and radius DB and user
        system("sudo -u postgres /usr/pgsql-13/bin/psql -c 'drop database kea;'");
        system("sudo -u postgres /usr/pgsql-13/bin/psql -c 'drop owned by kea; drop user kea;'");

        // Remove nmsprime and icinga DBs and users
        $dbs = $this->getDbsConf();

        foreach ($dbs as $db => $config) {
            if ($db == 'nmsprime') {
                system("sudo -u postgres /usr/pgsql-13/bin/psql -d $db -c 'drop schema $db cascade'");
            } else {
                system("sudo -u postgres /usr/pgsql-13/bin/psql -c 'drop database $db'");
            }

            $user = $config['user'];

            system("sudo -u postgres /usr/pgsql-13/bin/psql -c 'DROP OWNED BY $user; drop user $user'");
        }

        // Config::set('database.connections.default', 'mysql');
    }
}
