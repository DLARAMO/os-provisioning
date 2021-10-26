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

namespace Modules\ProvBase\Entities;

use File;

class ProvBase extends \BaseModel
{
    // The associated SQL table for this Model
    public $table = 'provbase';

    public $name = 'Provisioning Basic Config';

    // some variables used if module ProvHA is enabled
    public $provha;
    public $provhaState;
    public $provhaOwnIp;
    public $provhaPeerIp;
    public $provhaOwnDnsPw;
    public $provhaPeerDnsPw;

    protected const DEFAULT_NETWORK_FILE_PATH = '/etc/dhcp-nmsprime/default-network.conf';
    public const NSUPDATE_LOGFILE = '/var/log/nmsprime/nsupdate.log';

    /**
     * Constructor.
     *
     * @author Patrick Reichel
     */
    public function __construct()
    {
        // call \BaseModel's constructor
        parent::__construct();

        // set provha related variables
        $this->setProvhaProperties();
    }

    /**
     * Set module ProvHA related class variables.
     *
     * @author Patrick Reichel
     */
    protected function setProvhaProperties()
    {
        if (! \Module::collections()->has('ProvHA')) {
            $this->provha = null;
        } else {
            $this->provha = \DB::table('provha')->first();
            $this->provhaState = config('provha.hostinfo.ownState');
            if ('master' == $this->provhaState) {
                $this->provhaOwnIp = $this->provha->master;
                $this->provhaPeerIp = explode(',', $this->provha->slaves)[0];
                $this->provhaOwnDnsPw = $this->provha->master_dns_password;
                $this->provhaPeerDnsPw = $this->provha->slave_dns_password;
            } else {
                $this->provhaOwnIp = explode(',', $this->provha->slaves)[0];
                $this->provhaPeerIp = $this->provha->master;
                $this->provhaOwnDnsPw = $this->provha->slave_dns_password;
                $this->provhaPeerDnsPw = $this->provha->master_dns_password;
            }
        }
    }

    // Don't forget to fill this array
    // protected $fillable = ['provisioning_server', 'ro_community', 'rw_community', 'domain_name', 'notif_mail', 'dhcp_def_lease_time', 'dhcp_max_lease_time', 'startid_contract', 'startid_modem', 'startid_endpoint'];

    // Add your validation rules here
    public function rules()
    {
        return [
            'provisioning_server' => 'required|ip',
            'dhcp_def_lease_time' => 'required|integer|min:1',
            'dhcp_max_lease_time' => 'required|integer|min:1',
            'ppp_session_timeout' => 'required|integer|min:0',
            'max_cpe' => 'required|integer|min:0|max:254',
            // https://tools.ietf.org/html/rfc2869#section-5.16
            'acct_interim_interval' => 'required|integer|min:60',
        ];
    }

    // Name of View
    public static function view_headline()
    {
        return 'Prov Base Config';
    }

    // link title in index view
    public function view_index_label()
    {
        return 'Prov Base';
    }

    // View Icon
    public static function view_icon()
    {
        return '<i class="fa fa-database"></i>';
    }

    /**
     * BOOT - init provbase observer
     */
    public static function boot()
    {
        parent::boot();

        self::observe(new \Modules\ProvBase\Observers\ProvBaseObserver);
        self::observe(new \App\Observers\SystemdObserver);
    }

    /*
     * Return true if $this->prov_ip is online, otherwise false
     * This implies that the Mgmt Interface is setup correctly
     */
    public function prov_ip_online()
    {
        // Ping: Only check if device is online
        exec('sudo ping -c1 -i0 -w1 '.$this->provisioning_server, $ping, $ret);

        return $ret ? false : true;
    }

    /**
     * Create the vivso.2 option to provide multiple TFTP IPs.
     * see https://www.excentis.com/blog/how-provision-cable-modem-using-isc-dhcp-server for details
     *
     * @author Patrick Reichel
     */
    public function getDHCPOptionVivso2($ips)
    {
        $hex_ips = [];
        foreach ($ips as $ip) {
            $ip_split = explode('.', $ip);
            $ip_hex = sprintf('%02x:%02x:%02x:%02x', $ip_split[0], $ip_split[1], $ip_split[2], $ip_split[3]);
            $hex_ips[] = $ip_hex;
        }

        // option “syntax”:
        // option vivso <enterprise number> <length of the vivso TLV contents> <option type> <option length> <option value>
        $ret = [
            '00:00:11:8b',                          // dotted hex for 4491 (= CableLabs)
            sprintf('%02x', (4 * count($ips) + 2)), // bytes per IP + one byte for type + one byte for length
            '02',                                   // hex for option type 2
            sprintf('%02x', 4 * count($ips)),       // 4 bytes for each IP address
            implode(':', $hex_ips),
        ];

        return implode(':', $ret);
    }

    /**
     * Create the global configuration file for DHCP Server from Global Config Parameters
     * Set correct Domain Name on Server from GUI (Permissions via sudoers-file needed!!)
     *
     * @author Nino Ryschawy
     */
    public function make_dhcp_glob_conf()
    {
        $file_dhcp_conf = '/etc/dhcp-nmsprime/global.conf';

        $ownIp = $this->provisioning_server;
        $ipList = $ownIp;
        if ($this->provha) {
            $ownIp = $this->provhaOwnIp;
            $peerIp = $this->provhaPeerIp;
            $ipList = "$ownIp,$peerIp";
        }

        $data = 'ddns-domainname "'.$this->domain_name.'.";'."\n";
        $data .= 'option domain-name "'.$this->domain_name.'";'."\n";
        $data .= 'option domain-name-servers '.$ipList.";\n";
        $data .= 'default-lease-time '.$this->dhcp_def_lease_time.";\n";
        $data .= 'max-lease-time '.$this->dhcp_max_lease_time.";\n";
        $data .= 'next-server '.$ownIp.";\n";
        if (! is_null($this->provha)) {
            $data .= "\n# option vivso.2 (CL_V4OPTION_TFTPSERVERS, see https://www.excentis.com/blog/how-provision-cable-modem-using-isc-dhcp-server)\n";
            $data .= 'option vivso '.$this->getDHCPOptionVivso2([$ownIp, $peerIp]).";\n\n";
        }
        $data .= 'option log-servers '.$ipList.";\n";
        $data .= 'option time-servers '.$ipList.";\n";
        $data .= 'option time-offset '.date('Z').";\n";

        $data .= "\n# zone\nzone ".$this->domain_name." {\n\tprimary 127.0.0.1;\n\tkey dhcpupdate;\n}\n";
        $data .= "\n# reverse zone\nzone in-addr.arpa {\n\tprimary 127.0.0.1;\n\tkey dhcpupdate;\n}\n";

        if (\Module::collections()->has('ProvVoip') && \Schema::hasTable('provvoip')) {
            // second domain for mta's if existent
            $mta_domain = \Modules\ProvVoip\Entities\ProvVoip::first()->mta_domain;
            $data .= $mta_domain ? "\n# zone for voip devices\nzone ".$mta_domain." {\n\tprimary ".$this->provisioning_server.";\n\tkey dhcpupdate;\n}\n" : '';
        }

        // provisioning server hostname encoding for dhcp
        $fqdn = exec('hostname');
        $hostname = '';
        $dhcp_fqdn = '';

        if (($pos = strpos($fqdn, $this->domain_name)) !== false) {
            // correct domain name already set
            if ($pos == 0) {
                throw new \Exception('Hostname of Server not Set! Please specify a hostname via command line first!', 1);
            }
        } else {
            // Set correct fully qualified domain name for server - we expect the hostname to be the first word in previous fqdn
            $hostname = explode('.', $fqdn);

            if (! isset($hostname[0])) {
                throw new \Exception('Hostname of Server not Set! Please specify a hostname via command line first!', 1);
            } else {
                $hostname = $hostname[0];
            }

            $fqdn = $hostname.'.'.$this->domain_name;

            system('sudo hostnamectl set-hostname '.escapeshellarg($fqdn), $ret);

            if ($ret != 0) {
                throw new \Exception('Could not Set FQDN. No Permission? Please add actual version of laravel sudoers file to /etc/sudoers.d/!', 1);
            }
        }

        $arr = explode('.', $fqdn);

        // encode - every word needs a backslash and it's length as octal number (with leading zero's - up to 3 numbers) in front of itself
        foreach ($arr as $value) {
            $nr = strlen($value);
            $nr = decoct((int) $nr);
            $dhcp_fqdn .= sprintf("\%'.03d%s", $nr, $value);
        }
        $dhcp_fqdn .= '\\000';

        $leaseLimit = $this->max_cpe ?: 4;

        $data .= "\n# CLASS Specs for CM, MTA, CPE\n";
        $data .= 'class "CM" {'."\n\t".'match if (substring(option vendor-class-identifier,0,6) = "docsis");'."\n\toption ccc.dhcp-server-1 0.0.0.0;\n\tddns-updates on;\n}\n\n";
        $data .= 'class "MTA" {'."\n\t".'match if (substring(option vendor-class-identifier,0,4) = "pktc");'."\n\t".'option ccc.provision-server 0 "'.$dhcp_fqdn.'"; # number of letters before every through dot seperated word'."\n\t".'option ccc.realm "\005BASIC\0011\000";'."\n\tddns-updates on;\n}\n\n";
        $data .= 'class "Client" {'."\n\t".'match if ((substring(option vendor-class-identifier,0,6) != "docsis") and (substring(option vendor-class-identifier,0,4) != "pktc"));'."\n\t".'spawn with binary-to-ascii(16, 8, ":", substring(option agent.remote-id, 0, 6)); # create a sub-class automatically'."\n\tlease limit $leaseLimit; # max $leaseLimit private cpe per cm\n}\n\n";
        $data .= 'class "Client-Public" {'."\n\t".'match if ((substring(option vendor-class-identifier,0,6) != "docsis") and (substring(option vendor-class-identifier,0,4) != "pktc"));'."\n\t".'match pick-first-value (option agent.remote-id);'."\n\tlease limit $leaseLimit; # max $leaseLimit public cpe per cm\n}\n\n";
        $data .= "# All CPEs of modems without internet access - will be defined as subclass\n";
        $data .= 'class "blocked" {'."\n\t".'match if ((substring(option vendor-class-identifier,0,6) != "docsis") and (substring(option vendor-class-identifier,0,4) != "pktc"));'."\n\tmatch pick-first-value (option agent.remote-id);\n\tdeny booting;\n}\n\n";

        File::put($file_dhcp_conf, $data);
    }

    /**
     * Create the default shared-network based on the provisioning server ip
     * address, so that dhcpd knows on which interface to listen
     *
     * @author Ole Ernst
     */
    public function make_dhcp_default_network_conf()
    {
        $sub = new \IPv4\SubnetCalculator($this->provisioning_server, 22);
        $net = $sub->getNetworkPortion();
        $mask = $sub->getSubnetMask();

        $data = "shared-network ETHERNET\n{\n\tsubnet $net netmask $mask\n\t{\n\t\tdeny booting;\n\t}\n}\n";

        return file_put_contents(self::DEFAULT_NETWORK_FILE_PATH, $data, LOCK_EX);
    }

    /**
     * Update named config
     *
     * @author Patrick Reichel
     */
    public function makeNamedConf($password)
    {
        $password = str_replace('/', '\/', $password);
        $success = true;

        $sed = storage_path('app/tmp/update-domain.sed');   // use the “wrong” filename until it is clear how to update sudoers file in “yum update”…
        $old = 'key dhcpupdate {[^}]*}';
        $new = 'key dhcpupdate {\n\t# settings in this section will be overwritten by NMSPrime\n\talgorithm hmac-md5;\n\tsecret "'.$password.'";\n}';
        // multiline sed command taken from:
        // https://stackoverflow.com/questions/1251999/how-can-i-replace-a-newline-n-using-sed
        $content = ":a;N;$!ba;s/$old/$new/g";
        file_put_contents($sed, $content);

        exec("sudo sed -i -f $sed /etc/named-nmsprime.conf", $out, $ret);
        unlink($sed);

        // error in creating config file?
        if ($ret > 0) {
            $msg = trans('messages.error_building_config', ['named']);
            \Session::push('tmp_error_above_form', $msg);
            \Log::critical($msg);

            return false;
        }

        exec('sudo systemctl restart named.service &', $out, $ret);

        // error in restarting named?
        if ($ret > 0) {
            $msg = trans('messages.error_restarting_daemon', ['named']);
            \Session::push('tmp_error_above_form', $msg);
            \Log::critical($msg);

            return false;
        }

        // all went fine
        return true;
    }

    /**
     * Update DDNS config in dhcpd config.
     *
     * @author Patrick Reichel
     */
    public function makeNamedDhcpdConf($password)
    {
        $dhcp_conf_file = '/etc/dhcp-nmsprime/dhcpd.conf';
        try {
            $old_conf = file_get_contents($dhcp_conf_file);
        } catch (\Exception $ex) {
            \Log::error('Exception in '.$ex->getFile().', line '.$ex->getLine().': '.$ex->getMessage());

            return false;
        }

        $key = "key dhcpupdate {\n\t# settings in this section will be overwritten by NMSPrime\n\talgorithm hmac-md5;\n\tsecret $password;\n}";
        $new_conf = preg_replace('|key dhcpupdate {[^}]*}|s', $key, $old_conf);

        if (strcmp($old_conf, $new_conf) != 0) {
            try {
                file_put_contents($dhcp_conf_file, $new_conf, LOCK_EX);
                \Log::info('Changed dhcp update key in '.$dhcp_conf_file);
            } catch (\Exception $ex) {
                \Log::error('Exception in '.$ex->getFile().', line '.$ex->getLine().': '.$ex->getMessage());

                return false;
            }
        }

        return true;
    }

    /**
     * Write ddns update script.
     *
     * @author Patrick Reichel
     */
    public function makeDdnsUpdateScript($password)
    {
        $script_file = '/etc/named-ddns.sh';

        try {
            $old_script = file_get_contents($script_file);
        } catch (\Exception $ex) {
            \Log::error('Exception in '.$ex->getFile().', line '.$ex->getLine().': '.$ex->getMessage());

            return false;
        }

        $lines = [
            '#!/bin/bash',
            '# Warning: Script has been autogenerated by NMSPrime – changes may be overwritten!',
            '',
            "LOGFILE='".self::NSUPDATE_LOGFILE."'",
            'echo "" >> $LOGFILE',
            'echo "--------------------------------------------------------------------------------" >> $LOGFILE',
            'date -Is >> $LOGFILE',
            'echo "$0 $1 $2 $3" >> $LOGFILE',
            '',
            '# do not run ddns for CPEs with a private IP address, those are not publicly reachable anyway',
            'if grep -q -E \'^(10\.|192\.168)\' <<< "$2"; then',
            '    exit 0',
            'fi',
            'if grep -q -E \'^(172\.|100\.)\' <<< "$2"; then',
            '    IFS=\'.\' read -r -a ip <<< "$2"',
            '    if [ "${ip[0]}" -eq 172 -a "${ip[1]}" -ge 16 -a "${ip[1]}" -le 31 ]; then',
            '        exit 0',
            '    fi',
            '    if [ "${ip[0]}" -eq 100 -a "${ip[1]}" -ge 64 -a "${ip[1]}" -le 127 ]; then',
            '        exit 0',
            '    fi',
            'fi',
            '',
            '# we use a secret to salt the generation of hostnames (base32 encoded and truncated to 6 characters)',
            '# the python code should be replaced by coreutuils base32, which will be available with version 8.25',
            'mangle=$(echo "$1" | tr -cd "[:xdigit:]" | xxd -r -p | openssl dgst -sha256 -mac hmac -macopt hexkey:$(cat /etc/named-ddns-cpe.key) -binary | python -c \'import base64; import sys; print(base64.b32encode(sys.stdin.read())[:6].lower())\')',
            'rev=$(awk -F. \'{OFS="."; print $4,$3,$2,$1}\' <<< "$2")',
            '',
        ];

        $servers = [
            '127.0.0.1' => $password,    // nsupdate does not accept „localhost“ (“response to SOA query was unsuccessful”)!
        ];
        if (! is_null($this->provha)) {
            $servers[$this->provhaPeerIp] = $this->provhaPeerDnsPw;
        }

        foreach ($servers as $server=>$pw) {
            $update = [
                'if [ "$3" -ne 0 ]',
                'then',
                '    cmd="server '.$server,
                'update delete ${mangle}.cpe.'.$this->domain_name.'.',
                'send',
                'server '.$server,
                'update delete ${rev}.in-addr.arpa.',
                'send"',
                '',
                'else',
                '    cmd="server '.$server,
                'update add ${mangle}.cpe.'.$this->domain_name.'. 3600 A $2',
                'send',
                'server '.$server,
                'update add ${rev}.in-addr.arpa. 3600 PTR ${mangle}.cpe.'.$this->domain_name.'.',
                'send"',
                '',
                'fi',
                '',
                'echo "$cmd" >> $LOGFILE',
                'echo "$cmd" | nsupdate -v -y dhcpupdate:'.$pw,
                '',
            ];
            $lines = array_merge($lines, $update);
        }

        $new_script = implode("\n", $lines);

        if (strcmp($old_script, $new_script) != 0) {
            try {
                file_put_contents($script_file, $new_script, LOCK_EX);
                \Log::info('Changed '.$script_file);
            } catch (\Exception $ex) {
                \Log::error('Exception in '.$ex->getFile().', line '.$ex->getLine().': '.$ex->getMessage());

                return false;
            }
        }

        return true;
    }

    /**
     * Create all DDNS related config and scripts.
     *
     * @author Patrick Reichel
     */
    public function makeDdnsConf()
    {
        $password = $this->provhaOwnDnsPw ?: $this->dns_password;

        return $this->makeNamedConf($password) &&
            $this->makeNamedDhcpdConf($password) &&
            $this->makeDdnsUpdateScript($password);
    }
}
