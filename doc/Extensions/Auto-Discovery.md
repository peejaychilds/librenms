# Auto Discovery Support

## Getting Started

LibreNMS provides the ability to automatically add devices on your
network, we can do this via a few methods which will be explained
below and also indicate if they are enabled by default.

All discovery methods run when discovery runs (every 6 hours by
default and within 5 minutes for new devices).

Please note that you need at least ONE device added before
auto-discovery will work.

The first thing to do though is add the required configuration options.

## SNMP Details

To add devices automatically we need to know your snmp details,
examples of SNMP v1, v2c and v3 are below:

!!! setting "poller/snmp"
    ```bash
    lnms config:set snmp.community.+ my_custom_community
    lnms config:set snmp.community.+ another_community

    lnms config:set snmp.v3.+ '{
        "authlevel": "authPriv",
        "authname": "my_username",
        "authpass": "my_password",
        "authalgo": "SHA",
        "cryptopass": "my_crypto",
        "cryptoalgo": "AES"
    }'
    ```

These details will be attempted when adding devices, you can specify
any mixture of these.

## Allowed Networks

### Your Networks

To add devices, we need to know what are your subnets so we don't go
blindly attempting to add devices not under your control.

!!! setting "discovery/networks"
    ```bash
    lnms config:set nets.+ '192.168.0.0/24'
    lnms config:set nets.+ '172.2.4.0/22'
    ```

### Exclusions

If you have added a network as above but a single device exists within
it that you can't auto add, then you can exclude this with the following:

!!! setting "discovery/networks"
    ```bash
    lnms config:set autodiscovery.nets-exclude.+ '192.168.0.1/32'
    ```

## Additional Options

### Discovering devices by IP

By default we don't add devices by IP address, we look for a reverse
dns name to be found and add with that. If this fails
and you would like to still add devices automatically then you will
need to set `$config['discovery_by_ip'] = true;`

### Short hostnames

If your devices only return a short hostname such as lax-fa0-dc01 but
the full name should be lax-fa0-dc01.example.com then you can
set

!!! setting "discovery/general"
    ```bash
    lnms config:set mydomain example.com
    ```

### Allow Duplicate sysName

By default we require unique sysNames when adding devices (this is
returned over snmp by your devices). If you would like to allow
devices to be added with duplicate sysNames then please set

!!! setting "discovery/discovery_modules"
    ```bash
    lnms config:set allow_duplicate_sysName true
    ```

## Discovery Methods

Below are the methods for auto discovering devices.  Each one can be
enabled or disabled and may have additional configuration options.

### ARP

Disabled by default.

Adds devices that are listed in another device's arp table.  This
module depends on the arp-table module being enabled and returning
data.

To enable, switch on globally the
`discovery_modules.discovery-arp` or per device
within the Modules section.

!!! setting "discovery/discovery_modules"
    ```bash
    lnms config:set discovery_modules.discovery-arp true
    ```

### XDP

Enabled by default. Can be disabled with:

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.xdp false
    ```

This includes FDP, CDP and LLDP support based on the device type.

The LLDP/xDP links with neighbours will always be discovered as soon as the discovery module is enabled.
However, LibreNMS will only try to add the new devices discovered with LLDP/xDP if `$config['autodiscovery']['xdp'] = true;`.

Devices may be excluded from xdp discovery by sysName and sysDescr.

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.xdp_exclude.sysname_regexp.+ '/host1/'
    lnms config:set autodiscovery.xdp_exclude.sysname_regexp.+ '/^dev/'
    
    lnms config:set autodiscovery.xdp_exclude.sysdescr_regexp.+ '/-K9W8/'
    lnms config:set autodiscovery.xdp_exclude.sysdescr_regexp.+ '/Vendor X/'
    ```

Devices may be excluded from cdp discovery by platform. (CDP only)

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.cdp_exclude.platform_regexp.+ '/WS-C3750G/'
    lnms config:set autodiscovery.cdp_exclude.platform_regexp.+ '/^Cisco IP Phone/'
    ```

### OSPF

Enabled by default.

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.ospf false
    ```

### OSPFv3

Enabled by default.

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.ospfv3 false
    ```


### BGP

Enabled by default.

!!! setting "discovery/autodiscovery"
    ```bash
    lnms config:set autodiscovery.bgp false
    ```

This module is invoked from bgp-peers discovery module.

### SNMP Scan

Apart from the aforementioned Auto-Discovery options, LibreNMS is also
able to proactively scan a network for SNMP-enabled devices using the
configured version/credentials.

SNMP Scan will scan `nets` by default and respects `autodiscovery.nets-exclude`.

To run the SNMP-Scanner you need to execute the `snmp-scan.py` from
within your LibreNMS installation directory.

Here the script's help-page for reference:

```text
usage: snmp-scan.py [-h] [-t THREADS] [-g GROUP] [-l] [-v] [--ping-fallback] [--ping-only] [-P] [network ...]

Scan network for snmp hosts and add them to LibreNMS.

positional arguments:
  network          CIDR noted IP-Range to scan. Can be specified multiple times
                   This argument is only required if 'nets' config is not set
                   Example: 192.168.0.0/24
                   Example: 192.168.0.0/31 will be treated as an RFC3021 p-t-p network with two addresses, 192.168.0.0 and 192.168.0.1
                   Example: 192.168.0.1/32 will be treated as a single host address

optional arguments:
  -h, --help       show this help message and exit
  -t THREADS       How many IPs to scan at a time.  More will increase the scan speed, but could overload your system. Default: 32
  -g GROUP         The poller group all scanned devices will be added to. Default: The first group listed in 'distributed_poller_group', or 0 if not specificed
  -l, --legend     Print the legend.
  -v, --verbose    Show debug output. Specifying multiple times increases the verbosity.
  --ping-fallback  Add the device as an ICMP only device if it replies to ping but not SNMP.
  --ping-only      Always add the device as an ICMP only device.
  -P, --ping       Deprecated. Use --ping-fallback instead.
```

### Discovered devices

Newly discovered devices will be added to the `default_poller_group`, this value defaults to 0 if unset.

When using distributed polling, this value can be changed locally by setting `default_poller_group`

To set per-poller, set this in each poller's config.php file:
```php
$config['default_poller_group'] = 3;
```

Set globally

!!! setting "poller/distributed"
    ```bash
    lnms config:set default_poller_group 3
    ```

