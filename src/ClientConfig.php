<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace SURFnet\VPN\Portal;

class ClientConfig
{
    public static function get(array $profileConfig, array $clientCertificate, $shufflePorts)
    {
        // make a list of ports/proto to add to the configuration file
        $hostName = $profileConfig['hostName'];
        $processCount = $profileConfig['processCount'];

        $remoteProtoPortList = self::remotePortProtoList($processCount, $shufflePorts);

        $clientConfig = [
            sprintf('# OpenVPN Client Configuration for %s', $clientCertificate['cn']),

            // XXX fix date format to be in UTC
            sprintf('# Valid From: %s', date('Y-m-d', $clientCertificate['valid_from'])),
            sprintf('# Valid To: %s', date('Y-m-d', $clientCertificate['valid_to'])),

            'dev tun',
            'client',
            'nobind',
            'persist-key',
            'persist-tun',
            'remote-cert-tls server',

            // adaptive compression, allow server to override using push
            'comp-lzo',

            'verb 3',
            // tell the server more about this client (version, OS)
            'push-peer-info',

            // wait this long (seconds) before trying the next server in the list
            'server-poll-timeout 10',

            // allow the server to dictate the reneg-sec, by default it will be
            // 3600 seconds, but when 2FA is enable we'd like to increase this
            // to e.g. 8 hours to avoid asking for the OTP every hour
            'reneg-sec 0',

            // CRYPTO (DATA CHANNEL)
            'auth SHA256',
            'cipher AES-256-CBC',

            // CRYPTO (CONTROL CHANNEL)
            // @see RFC 7525
            // @see https://bettercrypto.org
            // @see https://community.openvpn.net/openvpn/wiki/Hardening
            'tls-version-min 1.2',

            'tls-cipher TLS-ECDHE-RSA-WITH-AES-256-GCM-SHA384:TLS-ECDHE-ECDSA-WITH-AES-256-GCM-SHA384:TLS-ECDHE-RSA-WITH-AES-256-CBC-SHA384:TLS-ECDHE-ECDSA-WITH-AES-256-CBC-SHA384:TLS-DHE-RSA-WITH-AES-256-GCM-SHA384:TLS-DHE-RSA-WITH-AES-128-GCM-SHA256',

            '<ca>',
            $clientCertificate['ca'],
            '</ca>',

            '<cert>',
            $clientCertificate['cert'],
            '</cert>',

            '<key>',
            $clientCertificate['key'],
            '</key>',

            'key-direction 1',

            '<tls-auth>',
            $clientCertificate['ta'],
            '</tls-auth>',
        ];

        // 2FA
        if ($profileConfig['twoFactor']) {
            $clientConfig[] = 'auth-user-pass';
        }

        // remote entries
        foreach ($remoteProtoPortList as $remoteProtoPort) {
            $clientConfig[] = sprintf('remote %s %d %s', $hostName, $remoteProtoPort['port'], $remoteProtoPort['proto']);
        }

        return implode("\r\n", $clientConfig);
    }

    public static function remotePortProtoList($processCount, $shufflePorts)
    {
        switch ($processCount) {
            case 1:
                return [
                    ['proto' => 'udp', 'port' => 1194],
                ];
            case 2:
                return [
                    ['proto' => 'udp', 'port' => 1194],
                    ['proto' => 'tcp', 'port' => 443],
                ];
            case 4:
            default:
                $portList = [1194, 1195];
                if ($shufflePorts) {
                    shuffle($portList);
                }

                return [
                    ['proto' => 'udp', 'port' => $portList[0]],
                    ['proto' => 'udp', 'port' => $portList[1]],
                    ['proto' => 'tcp', 'port' => 1194],
                    ['proto' => 'tcp', 'port' => 443],
                ];
            case 8:
                $udpPortList = [1194, 1195, 1196, 1197, 1198];
                $tcpPortList = [1194, 1195];
                if ($shufflePorts) {
                    shuffle($udpPortList);
                    shuffle($tcpPortList);
                }

                return [
                    ['proto' => 'udp', 'port' => $udpPortList[0]],
                    ['proto' => 'udp', 'port' => $udpPortList[1]],
                    ['proto' => 'tcp', 'port' => $tcpPortList[0]],
                    ['proto' => 'tcp', 'port' => 443],
                ];
        }
    }
}