<?php

/**
 * CppmServiceRestartTrapTest.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2024 Dag Bakke
 * @author     Dag Bakke <dag@bakke.com>
 */

namespace LibreNMS\Tests\Feature\SnmpTraps;

use LibreNMS\Enum\Severity;

class CppmServiceStopTrapTest extends SnmpTrapTestCase
{
    public function testServiceStop(): void
    {
        $this->assertTrapLogsMessage(<<<'TRAP'
{{ hostname }}
UDP: [{{ ip }}]:57602->[192.168.5.5]:162
DISMAN-EVENT-MIB::sysUpTimeInstance 26:19:43:37.24
SNMPv2-MIB::snmpTrapOID.0 CPPM-MIB::cppmServiceStopNotification
CPPM-MIB::cppmServiceName.0 "cpass-radius-server"
CPPM-MIB::cppmTrapMessage.0 "cpass-radius-server service is stopped"

TRAP,
            'Clearpass Service Trap - Host:{{ hostname }} Service:cpass-radius-server Message:cpass-radius-server service is stopped',
            'Could not handle cppmServiceStopNotification',
            [Severity::Warning],
        );
    }
}
