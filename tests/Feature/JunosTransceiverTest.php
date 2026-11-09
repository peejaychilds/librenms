<?php

namespace LibreNMS\Tests\Feature;

use App\Discovery\Sensor as SensorDiscovery;
use App\Models\Device;
use App\Models\Port;
use App\Models\Sensor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Data\Source\Snmp\SnmpQueryBuilder;
use LibreNMS\Data\Source\Snmp\SnmpResponse;
use LibreNMS\Exceptions\SnmpException;
use LibreNMS\Modules\Transceivers;
use LibreNMS\OS;
use LibreNMS\OS\Junos;
use LibreNMS\Tests\DBTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class JunosTransceiverTest extends DBTestCase
{
    use DatabaseTransactions;

    private Device $device;

    /** @var array<string, string> */
    private array $entities;

    /** @var array<string, string> */
    private array $dom;

    /** @var array<string, string> */
    private array $lanes;

    /** @var array<string, SnmpResponse> */
    private array $errors = [];

    /** @var array<string, string> */
    private array $optics = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = Device::factory()->create(['os' => 'junos']);
        foreach ([507 => 'et-0/0/0', 508 => 'et-0/0/1'] as $index => $name) {
            Port::factory()->for($this->device)->create(['ifIndex' => $index, 'ifName' => $name, 'ifDescr' => $name]);
        }
        \DeviceCache::setPrimary($this->device->device_id);
        $this->entities = [
            'ENTITY-MIB::entPhysicalDescr.75' => 'QSFP @ /Chassis[0]/Fpc[0]/Pic[0]/Port[0]',
            'ENTITY-MIB::entPhysicalName.75' => 'QSFP',
            'ENTITY-MIB::entPhysicalMfgName.75' => 'Example',
            'ENTITY-MIB::entPhysicalModelName.75' => 'OPTIC-A',
            'ENTITY-MIB::entPhysicalSerialNum.75' => 'SERIAL-A',
        ];
        $this->dom = [
            'JUNIPER-DOM-MIB::jnxDomCurrentAlarms.507' => '00 00 00',
            'JUNIPER-DOM-MIB::jnxDomCurrentModuleTemperature.507' => '24',
            'JUNIPER-DOM-MIB::jnxDomCurrentModuleLaneCount.507' => '4',
            'JUNIPER-DOM-MIB::jnxDomCurrentAlarms.508' => '00 00 00',
            'JUNIPER-DOM-MIB::jnxDomCurrentModuleTemperature.508' => '33',
        ];
        $this->lanes = [
            'JUNIPER-DOM-MIB::jnxDomCurrentLaneRxLaserPower.507.0' => '-200',
            'JUNIPER-DOM-MIB::jnxDomCurrentLaneRxLaserPower.507.1' => '-300',
            'JUNIPER-DOM-MIB::jnxDomCurrentLaneRxLaserPower.508.0' => '-400',
        ];
        $query = Mockery::mock(SnmpQueryBuilder::class);
        $this->app->instance(SnmpQueryBuilder::class, $query);
        $query->shouldReceive('cache', 'mibs', 'bulk', 'numericIndex', 'options')->andReturnSelf();
        $query->shouldReceive('walk')->andReturnUsing(fn (string $oid): SnmpResponse => $this->errors[$oid] ?? new SnmpResponse(match ($oid) {
            'ENTITY-MIB::entityPhysical' => $this->entities,
            'JUNIPER-DOM-MIB::jnxDomCurrentTable' => $this->dom,
            'JUNIPER-DOM-MIB::jnxDomModuleLaneTable' => $this->lanes,
            'JUNIPER-IFOPTICS-MIB::jnxOpticsPMCurrentTable' => $this->optics,
            default => [],
        }));
    }

    private function os(): Junos
    {
        \DeviceCache::flush();
        \DeviceCache::setPrimary($this->device->device_id);
        $array = $this->device->toArray();

        $os = OS::make($array);
        $this->assertInstanceOf(Junos::class, $os);

        return $os;
    }

    private function discover(): void
    {
        (new Transceivers)->discover($this->os());
        $this->device->unsetRelation('transceivers');
    }

    public function test_missing_inventory_preserves_an_existing_dom_transceiver(): void
    {
        $this->discover();
        $id = $this->device->transceivers()->firstOrFail()->id;
        $this->entities = [];
        $this->discover();
        $transceiver = $this->device->transceivers()->first();
        $this->assertNotNull($transceiver);
        $this->assertSame($id, $transceiver->id);
        $this->assertSame('SERIAL-A', $transceiver->serial);
    }

    /** @return array<string, array{string}> */
    public static function unknownSerials(): array
    {
        return ['blank' => [''], 'whitespace' => ['  '], 'unknown' => ['unknown'], 'unavailable' => ['N/A'], 'zero' => ['0']];
    }

    #[DataProvider('unknownSerials')]
    public function test_unknown_serial_does_not_overwrite_known_identity(string $serial): void
    {
        $this->discover();
        $this->entities['ENTITY-MIB::entPhysicalSerialNum.75'] = $serial;
        $this->discover();
        $this->assertSame('SERIAL-A', $this->device->transceivers()->firstOrFail()->serial);
    }

    public function test_missing_inventory_does_not_invent_a_new_transceiver(): void
    {
        $this->entities = [];
        $this->discover();
        $this->assertSame(0, $this->device->transceivers()->count());
    }

    private function sensor(string $class, string $index, string $custom = 'No', int $portIndex = 507): Sensor
    {
        return Sensor::factory()->for($this->device)->createQuietly([
            'sensor_class' => $class, 'sensor_type' => 'junos', 'sensor_index' => $index,
            'poller_type' => 'snmp', 'sensor_custom' => $custom, 'sensor_current' => 24,
            'sensor_limit' => 80, 'sensor_limit_warn' => 75, 'sensor_limit_low_warn' => -10, 'sensor_limit_low' => -15,
            'entPhysicalIndex' => (string) $portIndex, 'entPhysicalIndex_measured' => 'ports',
        ])->refresh();
    }

    public function test_serial_change_resets_automatic_limits_on_the_port_including_lanes(): void
    {
        $this->discover();
        $temperature = $this->sensor('temperature', 'rx-507');
        $lane = $this->sensor('dbm', 'rx-507.0');
        $frequency = $this->sensor('frequency', 'jnxPMCurCarFreqOffset.507');
        $manual = $this->sensor('dbm', 'tx-507.1', 'Yes');
        $otherPort = $this->sensor('temperature', 'rx-508', portIndex: 508);
        $this->entities['ENTITY-MIB::entPhysicalSerialNum.75'] = 'SERIAL-B';
        $this->discover();
        $this->assertSame('Reset', $temperature->refresh()->sensor_custom);
        $this->assertSame('Reset', $lane->refresh()->sensor_custom);
        $this->assertSame('Reset', $frequency->refresh()->sensor_custom);
        $this->assertSame('Yes', $manual->refresh()->sensor_custom);
        $this->assertSame('No', $otherPort->refresh()->sensor_custom);

        $discovery = new SensorDiscovery($this->device);
        foreach ([$temperature, $lane, $frequency, $manual, $otherPort] as $sensor) {
            $discovery->discover($sensor->replicate(['sensor_custom'])->forceFill([
                'sensor_current' => 40, 'sensor_limit' => null, 'sensor_limit_warn' => null,
                'sensor_limit_low_warn' => null, 'sensor_limit_low' => null,
            ]));
        }
        $discovery->sync(sensor_class: 'temperature', poller_type: 'snmp');
        $discovery->sync(sensor_class: 'dbm', poller_type: 'snmp');
        $discovery->sync(sensor_class: 'frequency', poller_type: 'snmp');
        $this->assertEquals(42, $frequency->refresh()->sensor_limit);
        $this->assertEquals(60, $temperature->refresh()->sensor_limit);
        $this->assertEquals(30, $temperature->sensor_limit_low);
        $this->assertNull($temperature->sensor_limit_warn);
        $this->assertSame('No', $temperature->sensor_custom);
        $this->assertNull($lane->refresh()->sensor_limit);
        $this->assertNull($lane->sensor_limit_low_warn);
        $manual->refresh();
        $this->assertEquals([80, 75, -10, -15], [$manual->sensor_limit, $manual->sensor_limit_warn, $manual->sensor_limit_low_warn, $manual->sensor_limit_low]);
        $this->assertEquals(80, $otherPort->refresh()->sensor_limit);
    }

    public function test_same_serial_does_not_reset_limits_when_other_metadata_changes(): void
    {
        $this->discover();
        $sensor = $this->sensor('temperature', 'rx-507');
        $this->entities['ENTITY-MIB::entPhysicalModelName.75'] = 'RENAMED';
        $this->discover();
        $this->assertSame('No', $sensor->refresh()->sensor_custom);
        $this->assertEquals(80, $sensor->sensor_limit);
    }

    public function test_removed_alarm_excludes_the_transceiver_and_all_its_dom_lanes(): void
    {
        $this->discover();
        $this->dom['JUNIPER-DOM-MIB::jnxDomCurrentAlarms.507'] = '00 00 20';
        $this->discover();
        $this->assertSame(0, $this->device->transceivers()->count());
        $cache = $this->os()->preCache();
        $this->assertArrayNotHasKey(507, $cache['JUNIPER-DOM-MIB::jnxDomCurrentTable']);
        $this->assertArrayHasKey(508, $cache['JUNIPER-DOM-MIB::jnxDomCurrentTable']);
        $this->assertArrayNotHasKey('507.0', $cache['JUNIPER-DOM-MIB::jnxDomModuleLaneTable']);
        $this->assertArrayNotHasKey('507.1', $cache['JUNIPER-DOM-MIB::jnxDomModuleLaneTable']);
        $this->assertArrayHasKey('508.0', $cache['JUNIPER-DOM-MIB::jnxDomModuleLaneTable']);
    }

    /** @return array<string, array{string}> */
    public static function otherAlarms(): array
    {
        return ['clear' => ['00 00 00'], 'link down' => ['00 00 40'], 'power down' => ['00 00 80'], 'missing' => [''], 'invalid' => ['invalid'], 'encoded text' => ['38 41 20 38 30 20 30 30']];
    }

    #[DataProvider('otherAlarms')]
    public function test_only_explicit_module_removal_excludes_dom(string $alarm): void
    {
        $this->dom['JUNIPER-DOM-MIB::jnxDomCurrentAlarms.507'] = $alarm;
        $this->discover();
        $this->assertSame(1, $this->device->transceivers()->count());
        $this->assertArrayHasKey(507, $this->os()->preCache()['JUNIPER-DOM-MIB::jnxDomCurrentTable']);
    }

    /** @return array<string, array{string}> */
    public static function identityTables(): array
    {
        return ['inventory' => ['ENTITY-MIB::entityPhysical'], 'DOM' => ['JUNIPER-DOM-MIB::jnxDomCurrentTable']];
    }

    #[DataProvider('identityTables')]
    public function test_failed_walk_does_not_remove_known_identity(string $oid): void
    {
        $this->discover();
        $this->errors[$oid] = new SnmpResponse([], 'Timeout: No Response from device', 1);
        try {
            $this->discover();
            $this->fail('A failed walk must not be reconciled as an empty inventory');
        } catch (SnmpException) {
            $this->assertSame('SERIAL-A', $this->device->transceivers()->firstOrFail()->serial);
        }
    }

    public function test_removed_dom_module_cannot_be_rediscovered_through_ifoptics(): void
    {
        $this->discover();
        $this->dom = array_filter($this->dom, fn ($key) => str_ends_with($key, '.507'), ARRAY_FILTER_USE_KEY);
        $this->dom['JUNIPER-DOM-MIB::jnxDomCurrentAlarms.507'] = '00 00 20';
        $this->optics = [
            'JUNIPER-IFOPTICS-MIB::jnxPMCurTemperature.507' => '24',
            'JUNIPER-IFOPTICS-MIB::jnxPMCurTemperature.508' => '33',
        ];
        $this->discover();
        $this->assertFalse($this->device->transceivers()->where('index', '507')->exists());
        $cache = $this->os()->preCache();
        $this->assertArrayNotHasKey(507, $cache['JUNIPER-IFOPTICS-MIB::jnxOpticsPMCurrentTable']);
        $this->assertArrayHasKey(508, $cache['JUNIPER-IFOPTICS-MIB::jnxOpticsPMCurrentTable']);
    }

    public function test_failed_dom_walk_does_not_reconcile_sensor_cache_as_empty(): void
    {
        $this->errors['JUNIPER-DOM-MIB::jnxDomCurrentTable'] = new SnmpResponse($this->dom, 'Timeout: No Response from device', 1);
        $this->expectException(SnmpException::class);
        $this->os()->preCache();
    }

    public function test_ifoptics_timeout_does_not_delete_fallback_transceiver(): void
    {
        $this->dom = [];
        $this->optics = ['JUNIPER-IFOPTICS-MIB::jnxPMCurTemperature.507' => '24'];
        $this->discover();
        $id = $this->device->transceivers()->firstOrFail()->id;
        $this->errors['JUNIPER-IFOPTICS-MIB::jnxOpticsPMCurrentTable'] = new SnmpResponse([], 'Timeout: No Response from device', 1);
        try {
            $this->discover();
            $this->fail('A failed fallback walk must not delete its existing transceiver');
        } catch (SnmpException) {
            $this->assertSame($id, $this->device->transceivers()->firstOrFail()->id);
        }
    }

    public function test_partial_dom_walk_is_not_reconciled_as_complete(): void
    {
        $this->discover();
        $this->errors['JUNIPER-DOM-MIB::jnxDomCurrentTable'] = new SnmpResponse([
            'JUNIPER-DOM-MIB::jnxDomCurrentModuleTemperature.508' => '33',
        ], 'Error: OID not increasing', 1);
        try {
            $this->discover();
            $this->fail('A partial failed walk must not replace a complete inventory');
        } catch (SnmpException) {
            $this->assertSame('SERIAL-A', $this->device->transceivers()->firstOrFail()->serial);
        }
        $this->expectException(SnmpException::class);
        $this->os()->preCache();
    }

    public function test_serial_change_does_not_reset_other_operating_systems(): void
    {
        $this->discover();
        $sensor = $this->sensor('temperature', 'rx-507');
        $this->device->os = 'ios';
        $this->device->save();
        \DeviceCache::flush();
        $transceiver = $this->device->transceivers()->firstOrFail();
        $transceiver->serial = 'SERIAL-B';
        $transceiver->save();
        $this->assertSame('No', $sensor->refresh()->sensor_custom);
        $this->assertEquals(80, $sensor->sensor_limit);
    }

    public function test_reset_is_applied_even_when_sensor_relation_was_already_loaded(): void
    {
        $this->discover();
        $sensor = $this->sensor('temperature', 'rx-507');
        $device = \DeviceCache::get($this->device->device_id);
        $device->load('sensors');
        $transceiver = $this->device->transceivers()->firstOrFail();
        $transceiver->serial = 'SERIAL-B';
        $transceiver->save();
        $discovered = $sensor->replicate(['sensor_custom'])->forceFill([
            'sensor_current' => 40, 'sensor_limit' => null, 'sensor_limit_warn' => null,
            'sensor_limit_low_warn' => null, 'sensor_limit_low' => null,
        ]);
        $discovery = new SensorDiscovery($device);
        $discovery->discover($discovered)->sync(sensor_class: 'temperature', poller_type: 'snmp');
        $this->assertEquals(60, $sensor->refresh()->sensor_limit);
        $this->assertSame('No', $sensor->sensor_custom);
    }
}
